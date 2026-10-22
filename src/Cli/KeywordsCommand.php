<?php
/**
 * WP-CLI: Keywords importieren und anzeigen.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Integrations\Textfocus\TextfocusException;
use Lsec\Research\Import\KeywordImporter;
use Lsec\Research\Keywords\KeywordSyncWorkflow;
use Lsec\Research\Keywords\RelatedKeywords;
use Lsec\Workflow\RetryableException;
use Lsec\Research\Keywords\KeywordRepository;
use Lsec\Research\Keywords\TypoChecker;
use WP_CLI;

/**
 * Keyword-Import aus CSV und Excel (Prüfpunkt M10).
 */
final class KeywordsCommand {

	/**
	 * Konstruktor.
	 *
	 * @param KeywordRepository $repository Speicher.
	 * @param Plugin|null       $plugin     Plugin (für Abgleich und Textfocus).
	 */
	public function __construct( private readonly KeywordRepository $repository, private readonly ?Plugin $plugin = null ) {}

	/**
	 * Importiert eine CSV- oder Excel-Datei.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Pfad zur Datei (.csv oder .xlsx).
	 *
	 * [--profile=<profile>]
	 * : keyword_revealer, keyword_chef, textfocus oder generic. Standard: automatisch.
	 *
	 * [--source=<source>]
	 * : Eigene Quellkennung, z. B. csv:sistrix. Standard: aus der Vorlage.
	 *
	 * [--map=<map>]
	 * : Spaltenzuordnung, z. B. keyword=Begriff,volume=SV.
	 *
	 * [--locale=<locale>]
	 * : Sprache und Land.
	 * ---
	 * default: de_DE
	 * ---
	 *
	 * [--region=<region>]
	 * : Ort, falls alle Keywords der Datei zu einem Ort gehören.
	 *
	 * [--dry-run]
	 * : Nur prüfen und anzeigen, nichts speichern.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec keywords import keyword-revealer.csv
	 *     wp lsec keywords import export.csv --map=keyword=Begriff,volume=SV --dry-run
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function import( array $args, array $assoc_args ): void {
		$path = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( 'Datei nicht gefunden oder nicht lesbar: ' . $path );
		}
		try {
			$result = KeywordImporter::from_file(
				$path,
				basename( $path ),
				(string) ( $assoc_args['profile'] ?? '' ),
				(string) ( $assoc_args['map'] ?? '' ),
				(string) ( $assoc_args['source'] ?? '' )
			);
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::log( sprintf( 'Vorlage: %s · Quelle: %s · Kodierung: %s · Trennzeichen: %s', $result->profile->label, $result->profile->source, $result->encoding, $result->delimiter ) );
		$mapping = array();
		foreach ( $result->profile->mapping as $field => $column ) {
			$mapping[] = $field . ' ← ' . $column;
		}
		WP_CLI::log( 'Zuordnung: ' . implode( ', ', $mapping ) );
		WP_CLI::log( sprintf( 'Zeilen: %d · Keywords: %d · übersprungen: %d', $result->rows_total, count( $result->keywords ), $result->rows_skipped ) );
		foreach ( $result->notes as $note ) {
			WP_CLI::log( 'Hinweis: ' . $note );
		}

		$preview = array();
		foreach ( array_slice( $result->keywords, 0, 10 ) as $entry ) {
			$preview[] = array(
				'keyword'    => $entry['keyword'],
				'volumen'    => null === $entry['volume'] ? 'unbekannt' : (string) $entry['volume'],
				'schwierig'  => null === $entry['difficulty'] ? '' : (string) $entry['difficulty'],
				'cpc'        => null === $entry['cpc'] ? '' : number_format( $entry['cpc'], 2, ',', '' ),
				'wettbewerb' => null === $entry['competition'] ? '' : (string) $entry['competition'],
				'intention'  => (string) $entry['intent'],
				'spannweite' => null === $entry['trend'] ? '' : $entry['trend']['min'] . '–' . $entry['trend']['max'],
			);
		}
		if ( array() !== $preview ) {
			WP_CLI\Utils\format_items( 'table', $preview, array( 'keyword', 'volumen', 'schwierig', 'cpc', 'wettbewerb', 'intention', 'spannweite' ) );
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( 'Probelauf, nichts gespeichert.' );
			return;
		}
		try {
			$saved = $this->repository->save_import(
				$result,
				(string) ( $assoc_args['locale'] ?? 'de_DE' ),
				(string) ( $assoc_args['region'] ?? '' ),
				0 !== get_current_user_id() ? get_current_user_id() : null
			);
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( 'Import %d gespeichert: %d neue, %d vorhandene Keywords.', $saved['import_id'], $saved['created'], $saved['updated'] ) );
	}

	/**
	 * Zeigt Keywords mit der neuesten Kennzahl je Quelle.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Teilwort.
	 *
	 * [--source=<source>]
	 * : Nur diese Quelle, z. B. csv:keyword_chef.
	 *
	 * [--limit=<n>]
	 * : Höchstzahl.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function list( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( $this->repository->list_with_metrics( (string) ( $assoc_args['search'] ?? '' ), (string) ( $assoc_args['source'] ?? '' ), (int) ( $assoc_args['limit'] ?? 50 ) ) as $row ) {
			$trend  = null === $row['trend'] ? null : json_decode( (string) $row['trend'], true );
			$gsc    = 'gsc' === $row['source'];
			$rows[] = array(
				'keyword'      => $row['keyword'],
				'quelle'       => $row['source'],
				'volumen'      => $gsc ? '' : ( null === $row['search_volume'] ? 'unbekannt' : $row['search_volume'] ),
				'klicks'       => null === $row['clicks'] ? '' : (string) $row['clicks'],
				'impressionen' => null === $row['impressions'] ? '' : (string) $row['impressions'],
				'ctr'          => null === $row['ctr'] ? '' : number_format( (float) $row['ctr'] * 100, 1, ',', '' ) . ' %',
				'position'     => null === $row['position'] ? '' : number_format( (float) $row['position'], 1, ',', '' ),
				'seite'        => null === $row['page_url'] ? '' : (string) wp_parse_url( (string) $row['page_url'], PHP_URL_PATH ),
				'schwierig'    => (string) $row['difficulty'],
				'cpc'          => (string) $row['cpc'],
				'wettbewerb'   => null === ( $row['competition'] ?? null ) ? '' : (string) $row['competition'],
				'intention'    => (string) $row['intent'],
				'spannweite'   => is_array( $trend ) ? $trend['min'] . '–' . $trend['max'] : '',
			);
		}
		// Search Console liefert Klicks und Impressionen, die übrigen Quellen Suchvolumen und Kennzahlen der Tools.
		$fields = 'gsc' === ( $assoc_args['source'] ?? '' )
			? array( 'keyword', 'klicks', 'impressionen', 'ctr', 'position', 'seite' )
			: array( 'keyword', 'quelle', 'volumen', 'impressionen', 'position', 'cpc', 'wettbewerb', 'schwierig', 'intention', 'spannweite' );
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, $fields );
	}

	/**
	 * Prüft Keywords auf mögliche Schreibvarianten bekannter Begriffe (M17).
	 *
	 * ## OPTIONS
	 *
	 * <keyword>...
	 * : Keywords.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec keywords check "flächenreingung"
	 *
	 * @param string[]              $args       Keywords.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function check( array $args, array $assoc_args ): void {
		$checker = new TypoChecker( $this->repository->known_terms() );
		$rows    = array();
		foreach ( $args as $keyword ) {
			$findings = $checker->check( $keyword );
			if ( array() === $findings ) {
				$rows[] = array(
					'keyword'   => $keyword,
					'wort'      => '',
					'vorschlag' => '',
					'grund'     => 'unauffällig',
				);
			}
			foreach ( $findings as $finding ) {
				$rows[] = array(
					'keyword'   => $keyword,
					'wort'      => $finding['word'],
					'vorschlag' => $finding['suggestion'],
					'grund'     => 'umlaut' === $finding['reason'] ? 'Umlaut-Schreibweise' : 'Abstand ' . $finding['distance'] . ', bekannt ' . $finding['known_count'] . '×',
				);
			}
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'keyword', 'wort', 'vorschlag', 'grund' ) );
	}

	/**
	 * Zeigt die letzten Importe.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function imports( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( $this->repository->imports() as $row ) {
			$notes  = json_decode( (string) $row['notes'], true );
			$rows[] = array(
				'id'           => $row['id'],
				'quelle'       => $row['source'],
				'datei'        => $row['filename'],
				'zeilen'       => $row['rows_total'],
				'importiert'   => $row['rows_imported'],
				'übersprungen' => $row['rows_skipped'],
				'hinweise'     => is_array( $notes ) ? implode( ' | ', $notes ) : '',
				'am'           => $row['created_at'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'quelle', 'datei', 'zeilen', 'importiert', 'übersprungen', 'hinweise', 'am' ) );
	}

	/**
	 * Keywords mit Suchvolumen, Search-Console-Werten und Ausschlussgrund (wie die Keywords-Seite).
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : active (Standard), excluded, all oder ein Grund: competitor, brand, foreign_place, off_topic, manual.
	 *
	 * [--search=<text>]
	 * : Teil des Keywords.
	 *
	 * [--source=<source>]
	 * : gsc, textfocus, import oder eine Quellkennung.
	 *
	 * [--sort=<sort>]
	 * : volume, impressions, clicks, position, keyword oder new.
	 *
	 * [--limit=<n>]
	 * : Anzahl.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec keywords table --status=excluded
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function table( array $args, array $assoc_args ): void {
		$result = $this->repository->table(
			array(
				'status' => (string) ( $assoc_args['status'] ?? 'active' ),
				'search' => (string) ( $assoc_args['search'] ?? '' ),
				'source' => (string) ( $assoc_args['source'] ?? '' ),
				'sort'   => (string) ( $assoc_args['sort'] ?? 'volume' ),
				'limit'  => (int) ( $assoc_args['limit'] ?? 50 ),
			)
		);
		$rows   = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = array(
				'id'           => $row['id'],
				'keyword'      => $row['keyword'],
				'volumen'      => null === $row['search_volume'] ? '' : $row['search_volume'] . ' (' . $row['volume_source'] . ')',
				'wettbewerb'   => null === $row['competition'] ? '' : (string) $row['competition'],
				'impressionen' => null === $row['impressions'] ? '' : (string) $row['impressions'],
				'klicks'       => null === $row['clicks'] ? '' : (string) $row['clicks'],
				'position'     => null === $row['position'] ? '' : number_format( (float) $row['position'], 1, ',', '' ),
				'ausschluss'   => null === $row['exclusion'] ? '' : $row['exclusion'] . ( null === $row['exclusion_detail'] ? '' : ': ' . $row['exclusion_detail'] ) . ( null === $row['exclusion_by'] ? '' : ' (von Hand)' ),
			);
		}
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'keyword', 'volumen', 'wettbewerb', 'impressionen', 'klicks', 'position', 'ausschluss' ) );
		if ( 'table' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( sprintf( '%d von %d Keywords.', count( $rows ), $result['total'] ) );
		}
	}

	/**
	 * Startet den Keyword-Abgleich: Search Console, Orte und Namen (KI), Ausschlüsse, Textfocus-Volumina.
	 *
	 * Läuft sonst einmal am Tag über wp lsec cron.
	 *
	 * ## OPTIONS
	 *
	 * [--now]
	 * : Hier abarbeiten statt über den Cronjob (Textfocus-Volumina nur bis zum ersten Mindestabstand).
	 *
	 * [--[no-]gsc]
	 * : Search Console abrufen (Standard) oder mit --no-gsc nicht.
	 *
	 * [--[no-]volumes]
	 * : Textfocus-Volumina nachholen (Standard) oder mit --no-volumes nicht.
	 *
	 * [--offline]
	 * : Orte nur mit bekannten Entitäten prüfen, ohne Wikidata (Prüfskript).
	 *
	 * [--stub-analysis=<file>]
	 * : JSON-Antwort statt KI-Aufruf (Prüfskript).
	 *
	 * [--ids=<ids>]
	 * : Nur diese Keyword-IDs prüfen und filtern, ohne Volumina (Prüfskript).
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$plugin  = $this->plugin();
		$options = array(
			'gsc'     => ! isset( $assoc_args['gsc'] ) || false !== $assoc_args['gsc'],
			'volumes' => ! isset( $assoc_args['volumes'] ) || false !== $assoc_args['volumes'],
			'reason'  => 'cli',
		);
		if ( ! empty( $assoc_args['offline'] ) ) {
			$options['offline'] = true;
		}
		if ( ! empty( $assoc_args['ids'] ) ) {
			$options['keyword_ids'] = array_values( array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['ids'] ) ) ) );
		}
		if ( ! empty( $assoc_args['stub-analysis'] ) ) {
			$stub = (string) file_get_contents( (string) $assoc_args['stub-analysis'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( null === json_decode( $stub, true ) ) {
				WP_CLI::error( 'Die Datei enthält kein gültiges JSON.' );
			}
			$options['stub_analysis'] = $stub;
		}
		$run = KeywordSyncWorkflow::start( $plugin, $options );
		if ( null === $run ) {
			WP_CLI::error( 'Es läuft schon ein Keyword-Abgleich. Stand: wp lsec runs list' );
		}
		if ( empty( $assoc_args['now'] ) ) {
			WP_CLI::success( 'Ablauf ' . $run['run_id'] . ' eingeplant; der Cronjob arbeitet ihn ab.' );
			return;
		}
		for ( $i = 0; $i < 500; $i++ ) {
			$plugin->engine->process( $run['run_id'] );
			$found = $plugin->runs->find( $run['run_id'] );
			$steps = $plugin->runs->steps( $run['run_id'] );
			if ( ! in_array( (string) ( $found['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
				break;
			}
			// Mindestabstand bei Textfocus: der Rest läuft über den Cronjob.
			$volumes = array_values( array_filter( $steps, static fn( array $s ): bool => 'volumes' === $s['step'] ) )[0] ?? array();
			if ( 'pending' === ( $volumes['status'] ?? '' ) && (int) ( json_decode( (string) $volumes['output'], true )['calls'] ?? 0 ) > 0 ) {
				break;
			}
		}
		$found = $plugin->runs->find( $run['run_id'] );
		foreach ( $plugin->runs->steps( $run['run_id'] ) as $step ) {
			WP_CLI::log( sprintf( '%-8s %-8s %s', $step['step'], $step['status'], mb_substr( (string) $step['output'], 0, 300 ) ) );
		}
		WP_CLI::success( sprintf( 'Ablauf %d: %s.', $run['run_id'], (string) ( $found['status'] ?? '' ) ) );
	}

	/**
	 * Schließt Keywords aus, nimmt sie auf oder gibt sie an den automatischen Filter zurück.
	 *
	 * ## OPTIONS
	 *
	 * <decision>
	 * : manual (ausschließen), include (aufnehmen, auch gegen den Filter) oder auto (der Filter entscheidet).
	 *
	 * <id>...
	 * : Keyword-IDs (siehe wp lsec keywords table).
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function decide( array $args, array $assoc_args ): void {
		$decision = (string) array_shift( $args );
		$done     = 0;
		foreach ( $args as $id ) {
			try {
				$done += $this->repository->decide_exclusion( (int) $id, $decision, get_current_user_id() ) ? 1 : 0;
			} catch ( \InvalidArgumentException $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}
		$this->plugin()->logger->audit(
			'Keyword-Ausschluss entschieden',
			array(
				'decision' => $decision,
				'ids'      => array_map( 'intval', $args ),
				'user_id'  => get_current_user_id(),
				'via'      => 'cli',
			)
		);
		WP_CLI::success( sprintf( '%d Keywords: %s.', $done, $decision ) );
	}

	/**
	 * Holt verwandte Keywords von Textfocus (tf_related, 1 Credit) und speichert sie.
	 *
	 * Danach prüft ein Abgleich Orte, Namen und Ausschlüsse.
	 *
	 * ## OPTIONS
	 *
	 * <keyword>
	 * : Ausgangs-Keyword.
	 *
	 * [--force]
	 * : Zwischenspeicher (14 Tage) umgehen.
	 *
	 * [--ignore-typos]
	 * : Trotz Hinweis auf eine Schreibvariante abfragen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec keywords related flächenreinigung
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function related( array $args, array $assoc_args ): void {
		$sleep = static function ( int $seconds ): void {
			WP_CLI::log( sprintf( 'Warte %d Sekunden (Mindestabstand zwischen Textfocus-Aufrufen) …', $seconds ) );
			sleep( $seconds );
		};
		try {
			$out = ( new RelatedKeywords( $this->plugin() ) )->fetch( (string) ( $args[0] ?? '' ), ! empty( $assoc_args['force'] ), ! empty( $assoc_args['ignore-typos'] ), $sleep );
		} catch ( TextfocusException $e ) {
			WP_CLI::error( $e->error_code . ': ' . $this->plugin()->logger->redact( $e->getMessage() ) );
		} catch ( RetryableException $e ) {
			WP_CLI::error( 'Vorübergehend (' . $e->error_code . '): ' . $this->plugin()->logger->redact( $e->getMessage() ) );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( null !== $out['typo'] ) {
			WP_CLI::error( sprintf( 'Mögliche Schreibvariante: „%s“ → meinten Sie „%s“? Keine Credits verbraucht. Mit --ignore-typos trotzdem abfragen.', $out['keyword'], $out['typo'] ) );
		}
		WP_CLI::success(
			sprintf(
				'%d verwandte Keywords gespeichert (%s).%s',
				$out['saved'],
				$out['cached'] ? 'aus dem Zwischenspeicher, keine Credits' : '1 Credit',
				null === $out['run_id'] ? ' Ein Abgleich läuft schon; Ausschlüsse folgen beim nächsten.' : ' Ausschlüsse prüft Ablauf ' . $out['run_id'] . ' über den Cronjob.'
			)
		);
	}

	/**
	 * Plugin für Abläufe.
	 */
	private function plugin(): Plugin {
		if ( null === $this->plugin ) {
			WP_CLI::error( 'Plugin nicht verfügbar.' );
		}
		return $this->plugin;
	}
}
