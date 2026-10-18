<?php
/**
 * WP-CLI: Textfocus-API.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Integrations\Textfocus\ProviderCallRepository;
use Lsec\Integrations\Textfocus\TextfocusException;
use Lsec\Integrations\Textfocus\TextfocusService;
use Lsec\Research\Keywords\KeywordNormalizer;
use Lsec\Research\Keywords\KeywordRepository;
use Lsec\Research\Keywords\TypoChecker;
use Lsec\Workflow\RetryableException;
use Lsec\Integrations\Textfocus\AccountSnapshot;
use WP_CLI;

/**
 * Abfragen bei Textfocus mit Credit-Anzeige (Prüfpunkt M16).
 */
final class TextfocusCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin            $plugin   Plugin.
	 * @param KeywordRepository $keywords Keyword-Speicher.
	 */
	public function __construct( private readonly Plugin $plugin, private readonly KeywordRepository $keywords ) {}

	/**
	 * Zeigt Tarif, API-Zugang und Restguthaben (0 Credits).
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function account( array $args, array $assoc_args ): void {
		$this->run(
			function ( TextfocusService $tf ): void {
				$info = $tf->account();
				AccountSnapshot::save( $info );
				$rows = array();
				foreach ( $info as $key => $value ) {
					$rows[] = array(
						'Angabe' => $key,
						'Wert'   => is_bool( $value ) ? ( $value ? 'ja' : 'nein' ) : ( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ),
					);
				}
				WP_CLI\Utils\format_items( 'table', $rows, array( 'Angabe', 'Wert' ) );
				if ( false === $info['api_access'] ) {
					WP_CLI::warning( 'Der Tarif hat keinen API-Zugang. Textfocus-Abfragen sind damit nicht möglich.' );
				}
				$this->print_budget();
			}
		);
	}

	/**
	 * Listet die Sprachcodes von Textfocus.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Filter auf Code, Sprache oder Land, z. B. german oder austria.
	 *
	 * [--save=<id>]
	 * : Sprachcode als Einstellung textfocus_lang speichern.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function langs( array $args, array $assoc_args ): void {
		$this->run(
			function ( TextfocusService $tf ) use ( $assoc_args ): void {
				$search = mb_strtolower( (string) ( $assoc_args['search'] ?? '' ) );
				$langs  = $tf->langs();
				$rows   = array_values(
					array_filter(
						$langs,
						static fn( array $l ): bool => '' === $search || str_contains( mb_strtolower( $l['id'] . ' ' . $l['label'] . ' ' . $l['locale'] ), $search )
					)
				);
				WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'label', 'locale' ) );
				if ( ! empty( $assoc_args['save'] ) ) {
					$id    = (string) $assoc_args['save'];
					$match = array_values( array_filter( $langs, static fn( array $l ): bool => $l['id'] === $id ) );
					if ( array() === $match ) {
						WP_CLI::error( 'Code ' . $id . ' steht nicht in der Liste von Textfocus.' );
					}
					Settings::set( 'textfocus_lang', $id );
					Settings::set( 'textfocus_locale', '' !== $match[0]['locale'] ? $match[0]['locale'] : TextfocusService::locale_from_code( $id ) );
					WP_CLI::success( sprintf( 'textfocus_lang = %s (%s, Locale %s)', $id, $match[0]['label'], (string) Settings::get( 'textfocus_locale' ) ) );
				}
			}
		);
	}

	/**
	 * Fragt Volumen, CPC und Schwierigkeit ab und speichert sie als Quelle „textfocus“.
	 *
	 * Keywords, für die Textfocus in den letzten 30 Tagen Werte geliefert hat, werden übersprungen.
	 *
	 * ## OPTIONS
	 *
	 * <keyword>...
	 * : Ein oder mehrere Keywords.
	 *
	 * [--lang=<lang>]
	 * : Sprachcode. Standard: Einstellung textfocus_lang oder de.
	 *
	 * [--force]
	 * : Auch frische Werte neu abfragen (verbraucht Credits).
	 *
	 * [--dry-run]
	 * : Nur anzeigen, wie viele Aufrufe nötig wären.
	 *
	 * [--ignore-typos]
	 * : Trotz Hinweis auf eine mögliche Schreibvariante abfragen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec textfocus keyword "flächenreinigung" "terrassenreinigung hannover" --dry-run
	 *
	 * @param string[]                   $args       Keywords.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function keyword( array $args, array $assoc_args ): void {
		$lang   = (string) ( $assoc_args['lang'] ?? TextfocusService::default_lang() );
		$locale = TextfocusService::locale_for( $lang );
		$force  = ! empty( $assoc_args['force'] );
		if ( empty( $assoc_args['ignore-typos'] ) ) {
			$this->stop_on_typos( $args );
		}

		$todo = $args;
		if ( ! $force ) {
			$fresh = $this->keywords->fresh( TextfocusService::PROVIDER, $args, $locale, TextfocusService::CACHE_DAYS['tf_keyword'] );
			$todo  = array_values( array_filter( $args, static fn( string $k ): bool => ! in_array( KeywordNormalizer::normalize( $k ), $fresh, true ) ) );
			if ( array() !== $fresh ) {
				WP_CLI::log( 'Schon vorhanden (jünger als 30 Tage): ' . implode( ', ', $fresh ) );
			}
		}
		if ( array() === $todo ) {
			WP_CLI::success( 'Nichts abzufragen.' );
			return;
		}
		$batch = max( 1, (int) Settings::get( 'textfocus_batch_size' ) );
		$calls = (int) ceil( count( $todo ) / $batch );
		WP_CLI::log( sprintf( '%d Keywords in %d Aufruf(en) zu je bis zu %d Keywords, Sprache %s.', count( $todo ), $calls, $batch, $lang ) );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$this->print_budget();
			WP_CLI::success( 'Probelauf, keine Credits verbraucht.' );
			return;
		}

		$this->run(
			function ( TextfocusService $tf ) use ( $todo, $lang, $locale, $force ): void {
				$out  = $tf->keywords( $todo, $lang, $force );
				$rows = array();
				foreach ( $out['keywords'] as $entry ) {
					$rows[] = array(
						'keyword'    => $entry['keyword'],
						'volumen'    => null === $entry['volume'] ? 'unbekannt' : (string) $entry['volume'],
						'cpc'        => null === $entry['cpc'] ? '' : number_format( $entry['cpc'], 2, ',', '' ),
						'schwierig'  => null === $entry['difficulty'] ? '' : (string) $entry['difficulty'],
						'wettbewerb' => null === $entry['competition'] ? '' : (string) $entry['competition'],
					);
				}
				WP_CLI\Utils\format_items( 'table', $rows, array( 'keyword', 'volumen', 'cpc', 'schwierig', 'wettbewerb' ) );
				$missing = array_diff( array_map( array( KeywordNormalizer::class, 'normalize' ), $todo ), array_keys( $out['keywords'] ) );
				if ( array() !== $missing ) {
					WP_CLI::warning( 'Ohne Ergebnis: ' . implode( ', ', $missing ) );
				}
				$saved = $this->keywords->save_metrics( TextfocusService::PROVIDER, $out['keywords'], $locale );
				WP_CLI::log( sprintf( 'Aufrufe: %d · aus Zwischenspeicher: %d · gespeicherte Kennzahlen: %d', $out['calls'], $out['cached'], $saved ) );
				$this->print_budget();
			}
		);
	}

	/**
	 * Verwandte Begriffe (bis zu 100), Wettbewerber, Semantik oder On-Page-Analyse.
	 *
	 * ## OPTIONS
	 *
	 * <endpoint>
	 * : related, competition, semantic oder seo.
	 *
	 * <keyword>
	 * : Ausgangs-Keyword.
	 *
	 * [--url=<url>]
	 * : Für seo: zu analysierende Seite.
	 *
	 * [--lang=<lang>]
	 * : Sprachcode.
	 *
	 * [--force]
	 * : Zwischenspeicher umgehen.
	 *
	 * [--ignore-typos]
	 * : Trotz Hinweis auf eine mögliche Schreibvariante abfragen.
	 *
	 * [--limit=<n>]
	 * : Zeilen in der Anzeige.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec textfocus query related "flächenreinigung"
	 *     wp lsec textfocus query seo "flächenreinigung" --url=https://flaechenreiniger-wassertechnik.de/
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function query( array $args, array $assoc_args ): void {
		$map  = array(
			'related'     => 'tf_related',
			'competition' => 'tf_competition',
			'semantic'    => 'tf_semantic',
			'seo'         => 'tf_seo',
		);
		$name = (string) ( $args[0] ?? '' );
		if ( ! isset( $map[ $name ] ) ) {
			WP_CLI::error( 'Endpunkt: related, competition, semantic oder seo.' );
		}
		$params = array(
			'lang'    => (string) ( $assoc_args['lang'] ?? TextfocusService::default_lang() ),
			'keyword' => (string) ( $args[1] ?? '' ),
		);
		if ( '' === $params['keyword'] ) {
			WP_CLI::error( 'Bitte ein Keyword angeben.' );
		}
		if ( empty( $assoc_args['ignore-typos'] ) ) {
			$this->stop_on_typos( array( $params['keyword'] ) );
		}
		if ( 'seo' === $name ) {
			$url = esc_url_raw( (string) ( $assoc_args['url'] ?? '' ) );
			if ( '' === $url ) {
				WP_CLI::error( 'Für seo bitte --url angeben.' );
			}
			$params['url'] = $url;
		}
		$this->run(
			function ( TextfocusService $tf ) use ( $map, $name, $params, $assoc_args ): void {
				$out    = $tf->query( $map[ $name ], $params, ! empty( $assoc_args['force'] ) );
				$result = $out['result'];
				if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
					WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
				} else {
					$this->print_summary( $name, $result, (int) ( $assoc_args['limit'] ?? 25 ) );
				}
				WP_CLI::log( $out['cached'] ? 'Aus dem Zwischenspeicher, keine Credits verbraucht.' : 'Neu abgefragt.' );
				$this->print_budget();
			}
		);
	}

	/**
	 * Letzte Aufrufe mit Status und Credits.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function calls( array $args, array $assoc_args ): void {
		$rows = ( new ProviderCallRepository() )->recent( TextfocusService::PROVIDER, 30 );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'endpoint', 'status', 'credits_used', 'credits_remaining', 'response_ref', 'cached_until', 'created_at' ) );
	}

	/**
	 * Bricht ab, wenn ein Keyword wie eine Schreibvariante eines bekannten Begriffs aussieht (Spezifikation 6.7).
	 *
	 * @param string[] $keywords Keywords.
	 */
	private function stop_on_typos( array $keywords ): void {
		$checker = new TypoChecker( $this->keywords->known_terms() );
		$hints   = array();
		foreach ( $keywords as $keyword ) {
			$suggestion = $checker->suggest( $keyword );
			if ( null !== $suggestion ) {
				$hints[] = sprintf( '„%s“ → meinten Sie „%s“?', $keyword, $suggestion );
			}
		}
		if ( array() !== $hints ) {
			WP_CLI::error( "Mögliche Schreibvariante, keine Credits verbraucht:\n  " . implode( "\n  ", $hints ) . "\nKorrigieren oder mit --ignore-typos trotzdem abfragen." );
		}
	}

	/**
	 * Gibt eine kurze Tabelle je Endpunkt aus.
	 *
	 * @param string $name   Endpunkt.
	 * @param mixed  $result Ergebnis.
	 * @param int    $limit  Zeilen.
	 */
	private function print_summary( string $name, mixed $result, int $limit ): void {
		if ( ! is_array( $result ) ) {
			WP_CLI::log( 'Kein Ergebnis.' );
			return;
		}
		$pick = static function ( array $items, array $fields ) use ( $limit ): array {
			$rows = array();
			foreach ( array_slice( array_values( $items ), 0, $limit ) as $item ) {
				$row = array();
				foreach ( $fields as $field ) {
					$value         = is_array( $item ) ? ( $item[ $field ] ?? '' ) : '';
					$row[ $field ] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
				}
				$rows[] = $row;
			}
			return $rows;
		};
		switch ( $name ) {
			case 'related':
				$fields = array( 'keyword', 'volume', 'intent', 'difficulty', 'cost' );
				WP_CLI\Utils\format_items( 'table', $pick( (array) ( $result['semantic'] ?? array() ), $fields ), $fields );
				break;
			case 'competition':
				$fields = array( 'position', 'url', 'title', 'nbWords', 'scoreSeo', 'typePage' );
				WP_CLI\Utils\format_items( 'table', $pick( (array) ( $result['competitors'] ?? array() ), $fields ), $fields );
				break;
			case 'semantic':
				$fields = array( 'keyword', 'kei', 'used', 'omoy', 'tfidf', 'intitle' );
				WP_CLI\Utils\format_items( 'table', $pick( (array) ( $result['semantic'] ?? array() ), $fields ), $fields );
				break;
			default:
				WP_CLI::log( 'Titel: ' . (string) ( $result['title'] ?? '' ) );
				WP_CLI::log( 'H1: ' . ( is_scalar( $result['h1'] ?? null ) ? (string) $result['h1'] : (string) wp_json_encode( $result['h1'] ?? '' ) ) );
				WP_CLI::log( 'Wörter: ' . (string) ( $result['nbWords'] ?? '' ) . ' · SEO-Wert: ' . (string) ( $result['scoreSeo'] ?? '' ) );
				$entities = (array) ( $result['entities'] ?? array() );
				WP_CLI::log( count( $entities ) . ' Entitäten (werden später nur als Kandidaten übernommen):' );
				foreach ( array_slice( $entities, 0, $limit ) as $entity ) {
					WP_CLI::log( '  ' . ( is_scalar( $entity ) ? (string) $entity : (string) wp_json_encode( $entity, JSON_UNESCAPED_UNICODE ) ) );
				}
		}
	}

	/**
	 * Tagesbudget anzeigen (ohne Aufruf beim Anbieter).
	 */
	private function print_budget(): void {
		$budget = (int) Settings::get( 'textfocus_daily_budget' );
		$used   = ( new ProviderCallRepository() )->credits_today( TextfocusService::PROVIDER );
		WP_CLI::log( sprintf( 'Tagesbudget: heute %s von %d Credits verbraucht, noch %s.', self::num( $used ), $budget, self::num( max( 0.0, $budget - $used ) ) ) );
	}

	/**
	 * Zahl ohne überflüssige Nachkommastellen.
	 *
	 * @param float $value Wert.
	 */
	private static function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, ',', '' ), '0' ), ',' );
	}

	/**
	 * Erstellt den Dienst und übersetzt Fehler in CLI-Meldungen.
	 *
	 * @param callable(TextfocusService): void $callback Aktion.
	 */
	private function run( callable $callback ): void {
		try {
			$sleep = static function ( int $seconds ): void {
				WP_CLI::log( sprintf( 'Warte %d Sekunden (Mindestabstand zwischen Textfocus-Aufrufen) …', $seconds ) );
				sleep( $seconds );
			};
			$callback( TextfocusService::from_settings( $this->plugin->http, $this->plugin->secrets, new ProviderCallRepository(), $sleep ) );
		} catch ( TextfocusException $e ) {
			WP_CLI::error( $e->error_code . ': ' . $this->plugin->logger->redact( $e->getMessage() ) );
		} catch ( RetryableException $e ) {
			WP_CLI::error( 'Vorübergehend (' . $e->error_code . '): ' . $this->plugin->logger->redact( $e->getMessage() ) );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}
}
