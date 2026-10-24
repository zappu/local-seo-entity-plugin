<?php
/**
 * Textfocus mit Budget, Abstand und Zwischenspeicher.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\Textfocus;

use Lsec\Core\Settings;
use Lsec\Infrastructure\Http\HttpTransport;
use Lsec\Infrastructure\Security\Secrets;
use Lsec\Integrations\AI\Json;
use Lsec\Research\Import\KeywordImporter;
use Lsec\Research\Keywords\KeywordNormalizer;
use Lsec\Workflow\RetryableException;

/**
 * Regeln aus Spezifikation 6.5:
 * - Tagesbudget in Credits (Standard 25), bei Erreichen budget_exceeded
 * - mindestens 60 Sekunden Abstand zwischen echten Aufrufen
 * - Zwischenspeicher je Anfrage: Volumina 30 Tage, Wettbewerber und Semantik 14 Tage, On-Page 7 Tage
 * - tf_keyword gebündelt mit bis zu N Keywords pro Aufruf
 */
final class TextfocusService {

	public const PROVIDER = 'textfocus';

	/**
	 * Gültigkeit des Zwischenspeichers in Tagen. 0 = nicht speichern.
	 */
	public const CACHE_DAYS = array(
		'tf_account'     => 0,
		'tf_langs'       => 30,
		'tf_keyword'     => 30,
		'tf_related'     => 30,
		'tf_competition' => 14,
		'tf_semantic'    => 14,
		'tf_seo'         => 7,
	);

	/**
	 * Endpunkte ohne Credit-Verbrauch.
	 */
	private const FREE = array( 'tf_account', 'tf_endpoints', 'tf_history' );

	/**
	 * Zeitlimits je Endpunkt (Wettbewerb und Semantik brauchen laut Doku bis zu 30 Sekunden).
	 */
	private const TIMEOUTS = array(
		'tf_competition' => 60,
		'tf_semantic'    => 60,
		'tf_seo'         => 60,
	);

	/**
	 * Wartefunktion, in Tests ersetzbar.
	 *
	 * @var \Closure(int): void
	 */
	private \Closure $sleep;

	/**
	 * Uhr, in Tests ersetzbar.
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Konstruktor.
	 *
	 * @param TextfocusClient $client       Client.
	 * @param ProviderCallLog $log          Protokoll und Zwischenspeicher.
	 * @param int             $daily_budget Credits pro Tag.
	 * @param int             $min_interval Mindestabstand in Sekunden.
	 * @param int             $batch_size   Keywords pro tf_keyword-Aufruf.
	 * @param \Closure|null   $sleep        Wartefunktion (Sekunden).
	 * @param \Closure|null   $clock        Uhr (Unix-Zeit).
	 */
	public function __construct(
		private readonly TextfocusClient $client,
		private readonly ProviderCallLog $log,
		private readonly int $daily_budget = 25,
		private readonly int $min_interval = 60,
		private readonly int $batch_size = 10,
		?\Closure $sleep = null,
		?\Closure $clock = null
	) {
		$this->sleep = $sleep ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Erstellt den Dienst aus Schlüssel und Einstellungen.
	 *
	 * @param HttpTransport   $http    HTTP.
	 * @param Secrets         $secrets Geheimnisse.
	 * @param ProviderCallLog $log     Protokoll.
	 * @param \Closure|null   $sleep   Wartefunktion (Sekunden), z. B. mit Hinweis in WP-CLI.
	 * @throws TextfocusException Wenn kein Schlüssel hinterlegt ist.
	 */
	public static function from_settings( HttpTransport $http, Secrets $secrets, ProviderCallLog $log, ?\Closure $sleep = null ): self {
		$key = $secrets->get( 'textfocus_api_key' );
		if ( null === $key || '' === $key ) {
			throw new TextfocusException( 'not_configured', 'Kein Textfocus-Schlüssel. Konstante ' . Secrets::constant_name( 'textfocus_api_key' ) . ' in wp-config.php setzen.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return new self(
			new TextfocusClient( $http, $key ),
			$log,
			(int) Settings::get( 'textfocus_daily_budget' ),
			(int) Settings::get( 'textfocus_min_interval' ),
			(int) Settings::get( 'textfocus_batch_size' ),
			$sleep
		);
	}

	/**
	 * Sprachcode aus der Einstellung, sonst de-DE.
	 */
	public static function default_lang(): string {
		$lang = (string) Settings::get( 'textfocus_lang' );
		return '' !== $lang ? $lang : 'de';
	}

	/**
	 * Locale des Plugins zum Textfocus-Sprachcode. Gespeichert mit wp lsec textfocus langs --save,
	 * sonst aus der Form de-AT abgeleitet, sonst de_DE. Textfocus nutzt Kürzel wie „de“, die das Land
	 * nicht erkennen lassen.
	 *
	 * @param string $lang Sprachcode.
	 */
	public static function locale_for( string $lang ): string {
		if ( (string) Settings::get( 'textfocus_lang' ) === $lang && '' !== (string) Settings::get( 'textfocus_locale' ) ) {
			return (string) Settings::get( 'textfocus_locale' );
		}
		return self::locale_from_code( $lang );
	}

	/**
	 * Locale aus einem Code der Form de-AT oder de_AT.
	 *
	 * @param string $lang Sprachcode.
	 */
	public static function locale_from_code( string $lang ): string {
		if ( preg_match( '/^([a-z]{2})[-_]([a-z]{2})$/i', $lang, $m ) ) {
			return strtolower( $m[1] ) . '_' . strtoupper( $m[2] );
		}
		return 'de_DE';
	}

	/**
	 * Konto, Tarif und Restguthaben (0 Credits, wird nicht gespeichert, da Adressdaten enthalten).
	 *
	 * @param bool $wait Bei zu kurzem Abstand warten statt Fehler.
	 * @return array{api_access: bool|null, pack: string, credits_remaining: float|null, consumed_30_days: float|null, rate_interval: mixed, rate_max: mixed, nb_compete_max: mixed, entities_access: bool|null}
	 */
	public function account( bool $wait = true ): array {
		$out    = $this->request( 'tf_account', array(), false, $wait );
		$result = is_array( $out['result'] ) ? $out['result'] : array();
		$pack   = is_array( $result['pack'] ?? null ) ? $result['pack'] : array();
		$cred   = is_array( $result['credits'] ?? null ) ? $result['credits'] : array();
		return array(
			'api_access'        => isset( $pack['apiAccess'] ) ? (bool) $pack['apiAccess'] : null,
			'pack'              => (string) ( $pack['name'] ?? '' ),
			'credits_remaining' => isset( $cred['remaining'] ) && is_numeric( $cred['remaining'] ) ? (float) $cred['remaining'] : $out['credits_remaining'],
			'consumed_30_days'  => isset( $cred['consumed30days'] ) && is_numeric( $cred['consumed30days'] ) ? (float) $cred['consumed30days'] : null,
			'rate_interval'     => $pack['rateInterval'] ?? null,
			'rate_max'          => $pack['rateMax'] ?? null,
			'nb_compete_max'    => $pack['nbCompeteMax'] ?? null,
			'entities_access'   => isset( $pack['entitiesAccess'] ) ? (bool) $pack['entitiesAccess'] : null,
		);
	}

	/**
	 * Verfügbare Sprachen.
	 *
	 * @param bool $wait Warten statt Fehler.
	 * @return array<int, array{id: string, label: string, locale: string}>
	 */
	public function langs( bool $wait = true ): array {
		$out = $this->request( 'tf_langs', array(), false, $wait );
		return self::parse_langs( $out['result'] );
	}

	/**
	 * Liest die Sprachliste. Aufbau laut Antwort vom 18.10.2026:
	 * {"id":"de","language_code":"de","country_iso_code":"DE","language_name":"German","location_name":"Germany"}.
	 *
	 * @param mixed $result Feld result.
	 * @return array<int, array{id: string, label: string, locale: string}>
	 */
	public static function parse_langs( mixed $result ): array {
		$langs = array();
		foreach ( is_array( $result ) ? $result : array() as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				$langs[] = array(
					'id'     => is_string( $key ) ? $key : (string) $entry,
					'label'  => (string) $entry,
					'locale' => '',
				);
				continue;
			}
			$language = strtolower( (string) ( $entry['language_code'] ?? '' ) );
			$country  = strtoupper( (string) ( $entry['country_iso_code'] ?? '' ) );
			$label    = trim( (string) ( $entry['language_name'] ?? $entry['name'] ?? '' ) . ' – ' . (string) ( $entry['location_name'] ?? '' ), ' –' );
			$langs[]  = array(
				'id'     => (string) ( $entry['id'] ?? $key ),
				'label'  => $label,
				'locale' => preg_match( '/^[a-z]{2}$/', $language ) && preg_match( '/^[A-Z]{2}$/', $country ) ? $language . '_' . $country : '',
			);
		}
		return $langs;
	}

	/**
	 * Volumen, CPC und Schwierigkeit, gebündelt.
	 *
	 * @param string[] $keywords Keywords.
	 * @param string   $lang     Sprachcode, z. B. de-DE.
	 * @param bool     $force    Zwischenspeicher umgehen (auch bei Textfocus: force_refetch=1).
	 * @param bool     $wait     Warten statt Fehler.
	 * @return array{keywords: array<string, array{keyword: string, volume: int|null, cpc: float|null, difficulty: float|null, competition: float|null}>, calls: int, cached: int, call_ids: int[]}
	 */
	public function keywords( array $keywords, string $lang, bool $force = false, bool $wait = true ): array {
		$unique = array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( (string) preg_replace( '/\s+/u', ' ', $keyword ) );
			if ( '' !== $keyword ) {
				$unique[ KeywordNormalizer::normalize( $keyword ) ] = $keyword;
			}
		}
		$out = array(
			'keywords' => array(),
			'calls'    => 0,
			'cached'   => 0,
			'call_ids' => array(),
		);
		foreach ( array_chunk( array_values( $unique ), max( 1, $this->batch_size ) ) as $chunk ) {
			$response = $this->request(
				'tf_keyword',
				array(
					'lang'    => $lang,
					'keyword' => $chunk,
				),
				$force,
				$wait
			);
			$response['cached'] ? ++$out['cached'] : ++$out['calls'];
			$out['call_ids'][] = $response['call_id'];
			foreach ( self::parse_keyword_result( $response['result'] ) as $normalized => $entry ) {
				$out['keywords'][ $normalized ] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Allgemeiner Aufruf für tf_related, tf_competition, tf_semantic und tf_seo.
	 *
	 * @param string               $endpoint Endpunkt.
	 * @param array<string, mixed> $params   Parameter (lang, keyword, url).
	 * @param bool                 $force    Zwischenspeicher umgehen.
	 * @param bool                 $wait     Warten statt Fehler.
	 * @return array{result: mixed, cached: bool, call_id: int, credits_remaining: float|null}
	 */
	public function query( string $endpoint, array $params, bool $force = false, bool $wait = true ): array {
		return $this->request( $endpoint, $params, $force, $wait );
	}

	/**
	 * Wandelt das Ergebnis von tf_keyword in Kennzahlen je normalisiertem Keyword.
	 * Textfocus liefert „cost“ (CPC) und „concurrency“ (Wettbewerb) zusätzlich zu volume und difficulty.
	 *
	 * @param mixed $result Feld result.
	 * @return array<string, array{keyword: string, volume: int|null, cpc: float|null, difficulty: float|null, competition: float|null}>
	 */
	public static function parse_keyword_result( mixed $result ): array {
		$out = array();
		if ( ! is_array( $result ) ) {
			return $out;
		}
		foreach ( $result as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$keyword = (string) ( $entry['keyword'] ?? ( is_string( $key ) ? $key : '' ) );
			if ( '' === $keyword ) {
				continue;
			}

			// Textfocus schreibt 0, wenn keine Daten vorliegen (Spezifikation 6.6, Regel 4).
			$num    = static fn( string $field ): ?float => isset( $entry[ $field ] ) && is_numeric( $entry[ $field ] ) && 0.0 !== (float) $entry[ $field ] ? (float) $entry[ $field ] : null;
			$volume = $num( 'volume' );
			$out[ KeywordNormalizer::normalize( $keyword ) ] = array(
				'keyword'     => $keyword,
				'volume'      => null === $volume ? null : (int) round( $volume ),
				'cpc'         => $num( 'cost' ),
				'difficulty'  => $num( 'difficulty' ),
				'competition' => $num( 'concurrency' ),
			);
		}
		return $out;
	}

	/**
	 * Wandelt das Ergebnis von tf_related (Liste „semantic“) in Kennzahlen je normalisiertem Keyword.
	 * Gleiche Regeln wie tf_keyword: 0 heißt unbekannt.
	 *
	 * @param mixed $result Feld result.
	 * @return array<string, array{keyword: string, volume: int|null, cpc: float|null, difficulty: float|null, competition: float|null, intent: string|null}>
	 */
	public static function parse_related_result( mixed $result ): array {
		$items = is_array( $result ) ? (array) ( $result['semantic'] ?? array() ) : array();
		$out   = array();
		foreach ( self::parse_keyword_result( array_values( $items ) ) as $normalized => $entry ) {
			$out[ $normalized ] = $entry + array( 'intent' => null );
		}
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['keyword'], $item['intent'] ) && is_scalar( $item['intent'] ) ) {
				$key = KeywordNormalizer::normalize( (string) $item['keyword'] );
				if ( isset( $out[ $key ] ) ) {
					$out[ $key ]['intent'] = KeywordImporter::intent( (string) $item['intent'] );
				}
			}
		}
		return $out;
	}

	/**
	 * Fingerabdruck einer Anfrage: Endpunkt und Parameter, Keywords normalisiert und sortiert.
	 *
	 * @param string               $endpoint Endpunkt.
	 * @param array<string, mixed> $params   Parameter.
	 */
	public static function request_hash( string $endpoint, array $params ): string {
		if ( isset( $params['keyword'] ) ) {
			$keywords = array_map( array( KeywordNormalizer::class, 'normalize' ), (array) $params['keyword'] );
			sort( $keywords );
			$params['keyword'] = $keywords;
		}
		if ( isset( $params['lang'] ) ) {
			$params['lang'] = strtolower( (string) $params['lang'] );
		}
		ksort( $params );
		return hash( 'sha256', $endpoint . '|' . Json::encode( $params ) );
	}

	/**
	 * Verbleibende Credits heute nach Tagesbudget.
	 */
	public function budget_left(): float {
		return max( 0.0, $this->daily_budget - $this->log->credits_today( self::PROVIDER ) );
	}

	/**
	 * Gemeinsamer Ablauf: Zwischenspeicher, Budget, Abstand, Aufruf, Protokoll.
	 *
	 * @param string               $endpoint Endpunkt.
	 * @param array<string, mixed> $params   Parameter.
	 * @param bool                 $force    Zwischenspeicher umgehen.
	 * @param bool                 $wait     Warten statt Fehler.
	 * @return array{result: mixed, cached: bool, call_id: int, credits_remaining: float|null}
	 * @throws TextfocusException Wenn das Tagesbudget erreicht ist.
	 * @throws RetryableException Wenn der Mindestabstand nicht eingehalten ist und nicht gewartet werden soll.
	 */
	private function request( string $endpoint, array $params, bool $force, bool $wait ): array {
		$hash  = self::request_hash( $endpoint, $params );
		$days  = self::CACHE_DAYS[ $endpoint ] ?? 7;
		$store = $days > 0;

		if ( $store && ! $force ) {
			$hit = $this->log->cached( self::PROVIDER, $hash );
			if ( null !== $hit ) {
				$data = json_decode( $hit['body'], true );
				$id   = $this->log->record(
					array(
						'provider'     => self::PROVIDER,
						'endpoint'     => $endpoint,
						'request_hash' => $hash,
						'status'       => 'cached',
						'credits_used' => 0.0,
						'response_ref' => 'call:' . $hit['id'],
					)
				);
				return array(
					'result'            => is_array( $data ) ? ( $data['result'] ?? null ) : null,
					'cached'            => true,
					'call_id'           => $id,
					'credits_remaining' => null,
				);
			}
		}

		if ( ! in_array( $endpoint, self::FREE, true ) && $this->budget_left() < 1 ) {
			$this->log->record(
				array(
					'provider'     => self::PROVIDER,
					'endpoint'     => $endpoint,
					'request_hash' => $hash,
					'status'       => 'budget_exceeded',
					'credits_used' => 0.0,
				)
			);
			throw new TextfocusException( 'budget_exceeded', sprintf( 'Textfocus-Tagesbudget von %d Credits erreicht. Weiter am nächsten Tag oder Budget erhöhen (Einstellungen → Textfocus).', $this->daily_budget ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->respect_interval( $wait );

		if ( $force ) {
			$params['force_refetch'] = 1;
		}
		try {
			$response = $this->client->call( $endpoint, $params, self::TIMEOUTS[ $endpoint ] ?? 30 );
		} catch ( RetryableException $e ) {
			$this->log->record(
				array(
					'provider'     => self::PROVIDER,
					'endpoint'     => $endpoint,
					'request_hash' => $hash,
					'status'       => 'rate_limited' === $e->error_code ? 'rate_limited' : 'error',
					'credits_used' => 0.0,
				)
			);
			throw $e;
		} catch ( TextfocusException $e ) {
			$this->log->record(
				array(
					'provider'     => self::PROVIDER,
					'endpoint'     => $endpoint,
					'request_hash' => $hash,
					'status'       => 'error',
					'credits_used' => 0.0,
				)
			);
			throw $e;
		}

		$used = $response['credits_used'] ?? ( in_array( $endpoint, self::FREE, true ) ? 0.0 : 1.0 );
		$id   = $this->log->record(
			array(
				'provider'          => self::PROVIDER,
				'endpoint'          => $endpoint,
				'request_hash'      => $hash,
				'status'            => 'ok',
				'credits_used'      => $used,
				'credits_remaining' => $response['credits_remaining'],
				// Nur result speichern: die Hülle wiederholt die Parameter einschließlich key.
				'response_body'     => $store ? Json::encode( array( 'result' => $response['result'] ) ) : null,
				'cached_until'      => $store ? gmdate( 'Y-m-d H:i:s', ( $this->clock )() + $days * 86400 ) : null,
			)
		);
		return array(
			'result'            => $response['result'],
			'cached'            => false,
			'call_id'           => $id,
			'credits_remaining' => $response['credits_remaining'],
		);
	}

	/**
	 * Hält den Mindestabstand zwischen echten Aufrufen ein.
	 *
	 * @param bool $wait Warten (CLI) oder Fehler zum späteren Wiederholen (Ablauf).
	 * @throws RetryableException Wenn nicht gewartet werden soll.
	 */
	private function respect_interval( bool $wait ): void {
		$last = $this->log->last_call_at( self::PROVIDER );
		if ( null === $last ) {
			return;
		}
		$remaining = $last + $this->min_interval - ( $this->clock )();
		if ( $remaining <= 0 ) {
			return;
		}
		if ( ! $wait ) {
			throw new RetryableException( 'rate_limited', sprintf( 'Textfocus: Mindestabstand von %d Sekunden, noch %d Sekunden.', $this->min_interval, $remaining ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		( $this->sleep )( $remaining );
	}
}
