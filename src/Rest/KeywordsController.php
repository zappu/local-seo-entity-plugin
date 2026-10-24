<?php
/**
 * REST-Routen der Keywords-Seite (Stufe C).
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Admin\DashboardData;
use Lsec\Cli\GscCommand;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\ServiceArea;
use Lsec\Integrations\Textfocus\ProviderCallRepository;
use Lsec\Integrations\Textfocus\TextfocusException;
use Lsec\Integrations\Textfocus\TextfocusService;
use Lsec\Research\Import\ImportProfile;
use Lsec\Research\Import\KeywordImporter;
use Lsec\Research\Import\TableReader;
use Lsec\Research\Keywords\KeywordRepository;
use Lsec\Research\Keywords\KeywordSyncWorkflow;
use Lsec\Research\Keywords\RelatedKeywords;
use Lsec\Workflow\RetryableException;

/**
 * Tabelle, Überblick, Ausschlüsse, Abgleich, verwandte Keywords und Import mit Vorschau und
 * Spaltenzuordnung. Alle Routen verlangen lsec_manage; Pflichtfelder werden im Callback geprüft (M15).
 */
final class KeywordsController {

	/**
	 * Größte Importdatei in Bytes.
	 */
	private const MAX_UPLOAD = 10 * 1024 * 1024;

	/**
	 * Bezeichnungen der Importfelder.
	 */
	private const FIELD_LABELS = array(
		'keyword'     => 'Keyword',
		'volume'      => 'Suchvolumen',
		'difficulty'  => 'Schwierigkeit',
		'cpc'         => 'CPC',
		'competition' => 'Wettbewerb',
		'intent'      => 'Suchintention',
		'trend'       => 'Verlauf',
	);

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Registriert die Routen.
	 *
	 * @param callable $permission Zugriffsprüfung.
	 */
	public function register( callable $permission ): void {
		$route = static function ( string $path, string $method, callable $callback ) use ( $permission ): void {
			register_rest_route(
				RestController::NAMESPACE,
				$path,
				array(
					'methods'             => $method,
					'callback'            => $callback,
					'permission_callback' => $permission,
				)
			);
		};
		$route( '/keywords/table', 'GET', array( $this, 'table' ) );
		$route( '/keywords/overview', 'GET', array( $this, 'overview' ) );
		$route( '/keywords/(?P<id>\d+)/exclusion', 'POST', array( $this, 'exclusion' ) );
		$route( '/keywords/exclusion', 'POST', array( $this, 'exclusion_many' ) );
		$route( '/keywords/sync', 'POST', array( $this, 'sync' ) );
		$route( '/keywords/related', 'POST', array( $this, 'related' ) );
		$route( '/keywords/import/preview', 'POST', array( $this, 'preview' ) );
		$route( '/keywords/import', 'POST', array( $this, 'import' ) );
	}

	/**
	 * Tabelle.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 */
	public function table( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			( new KeywordRepository() )->table(
				array(
					'search' => (string) $request->get_param( 'search' ),
					'source' => (string) $request->get_param( 'source' ),
					'status' => (string) ( $request->get_param( 'status' ) ?? 'active' ),
					'sort'   => (string) ( $request->get_param( 'sort' ) ?? 'volume' ),
					'limit'  => (int) ( $request->get_param( 'limit' ) ?? 100 ),
					'offset' => (int) ( $request->get_param( 'offset' ) ?? 0 ),
				)
			)
		);
	}

	/**
	 * Überblick: Anzahl, letzter Abgleich, Search Console, Textfocus, Einstellungen.
	 */
	public function overview(): \WP_REST_Response {
		$run  = $this->plugin->runs->latest_of_type( KeywordSyncWorkflow::TYPE );
		$sync = null;
		if ( null !== $run ) {
			$steps = array();
			foreach ( (array) $run['steps'] as $step ) {
				$output                          = json_decode( (string) $step['output'], true );
				$steps[ (string) $step['step'] ] = array(
					'status' => (string) $step['status'],
					'output' => is_array( $output ) ? $output : null,
				);
			}
			$context = json_decode( (string) $run['context'], true );
			$sync    = array(
				'id'            => (int) $run['id'],
				'status'        => (string) $run['status'],
				'reason'        => is_array( $context ) ? (string) ( $context['reason'] ?? '' ) : '',
				'error_code'    => (string) $run['error_code'],
				'error_message' => $this->plugin->logger->redact( (string) $run['error_message'] ),
				'created_at'    => self::iso( (string) $run['created_at'] ),
				'finished_at'   => null === $run['finished_at'] ? null : self::iso( (string) $run['finished_at'] ),
				'steps'         => $steps,
			);
		}
		$area = ServiceArea::from_settings();
		return new \WP_REST_Response(
			array(
				'summary'   => ( new KeywordRepository() )->summary(),
				'sync'      => $sync,
				'gsc'       => array(
					'property'    => (string) Settings::get( 'gsc_property' ),
					'credentials' => is_readable( GscCommand::credentials_file() ),
					'last_import' => DashboardData::last_gsc_import(),
				),
				'textfocus' => array(
					'configured'   => 'none' !== $this->plugin->secrets->source( 'textfocus_api_key' ),
					'daily_budget' => (int) Settings::get( 'textfocus_daily_budget' ),
					'used_today'   => ( new ProviderCallRepository() )->credits_today( TextfocusService::PROVIDER ),
					'lang'         => (string) Settings::get( 'textfocus_lang' ),
				),
				'settings'  => array(
					'analysis'     => (bool) Settings::get( 'keyword_analysis' ),
					'auto_volumes' => (bool) Settings::get( 'keyword_auto_volumes' ),
					'service_area' => null === $area ? '' : $area->label,
				),
				'fields'    => self::FIELD_LABELS,
			)
		);
	}

	/**
	 * Ausschluss entscheiden: manual, include oder auto.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function exclusion( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$decision = (string) $request->get_param( 'decision' );
		try {
			$found = ( new KeywordRepository() )->decide_exclusion( (int) $request['id'], $decision, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		if ( ! $found ) {
			return new \WP_Error( 'lsec_not_found', 'Keyword nicht gefunden.', array( 'status' => 404 ) );
		}
		$this->plugin->logger->audit(
			'Keyword-Ausschluss entschieden',
			array(
				'keyword_id' => (int) $request['id'],
				'decision'   => $decision,
				'user_id'    => get_current_user_id(),
			)
		);
		if ( 'auto' === $decision ) {
			// Sofort neu bewerten, damit die Tabelle den Grund des Filters zeigt.
			$row = ( new KeywordRepository() )->for_filter( (int) $request['id'] - 1, 1, array( (int) $request['id'] ) )[0] ?? null;
			if ( null !== $row ) {
				try {
					$verdict = KeywordSyncWorkflow::exclusions( $this->plugin )->filter()->evaluate( $row['keyword'], $row['analysis'] );
					( new KeywordRepository() )->set_auto_exclusion( $row['id'], $verdict['exclusion'], $verdict['detail'] );
				} catch ( RetryableException $e ) {
					unset( $e ); // Der nächste Abgleich holt das nach.
				}
			}
		}
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Sammelentscheidung: mehrere Keywords ausschließen oder aufnehmen (Nachtrag 24.10.2026, 0.10.0).
	 *
	 * @param \WP_REST_Request $request Anfrage mit ids (höchstens 500) und decision (manual oder include).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function exclusion_many( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$decision = (string) $request->get_param( 'decision' );
		$ids      = array_values( array_unique( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( ! in_array( $decision, array( 'manual', 'include' ), true ) || array() === $ids || count( $ids ) > 500 ) {
			return new \WP_Error( 'lsec_invalid', 'Entscheidung manual oder include und 1 bis 500 Keywords angeben.', array( 'status' => 400 ) );
		}
		$repo    = new KeywordRepository();
		$changed = array();
		foreach ( $ids as $id ) {
			if ( $repo->decide_exclusion( $id, $decision, get_current_user_id() ) ) {
				$changed[] = $id;
			}
		}
		$this->plugin->logger->audit(
			'Keyword-Ausschluss entschieden',
			array(
				'keyword_ids' => $changed,
				'decision'    => $decision,
				'user_id'     => get_current_user_id(),
			)
		);
		return new \WP_REST_Response(
			array(
				'changed'  => count( $changed ),
				'decision' => $decision,
			)
		);
	}

	/**
	 * Startet den Abgleich.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync(): \WP_REST_Response|\WP_Error {
		$run = KeywordSyncWorkflow::start( $this->plugin, array( 'reason' => 'manual' ) );
		if ( null === $run ) {
			return new \WP_Error( 'lsec_running', 'Es läuft schon ein Keyword-Abgleich.', array( 'status' => 409 ) );
		}
		$this->plugin->logger->audit(
			'Keyword-Abgleich gestartet',
			array(
				'run_id'  => $run['run_id'],
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( $run );
	}

	/**
	 * Verwandte Keywords von Textfocus (1 Credit).
	 *
	 * @param \WP_REST_Request $request Anfrage mit keyword, ignore_typos.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function related( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$out = ( new RelatedKeywords( $this->plugin ) )->fetch( (string) $request->get_param( 'keyword' ), false, (bool) $request->get_param( 'ignore_typos' ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( TextfocusException $e ) {
			return new \WP_Error( 'lsec_textfocus', $this->plugin->logger->redact( $e->getMessage() ), array( 'status' => 'budget_exceeded' === $e->error_code ? 429 : 400 ) );
		} catch ( RetryableException $e ) {
			$message = 'rate_limited' === $e->error_code ? 'Textfocus verlangt eine Pause zwischen zwei Abfragen. Bitte in einer Minute noch einmal.' : $this->plugin->logger->redact( $e->getMessage() );
			return new \WP_Error( 'lsec_retry', $message, array( 'status' => 503 ) );
		}
		return new \WP_REST_Response( $out );
	}

	/**
	 * Lädt eine Datei hoch und zeigt Kopfzeile, erste Zeilen und die erkannte Zuordnung.
	 *
	 * @param \WP_REST_Request $request Anfrage mit Datei „file“.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new \WP_Error( 'lsec_invalid', 'Keine Datei empfangen.', array( 'status' => 400 ) );
		}
		$name = sanitize_file_name( (string) $file['name'] );
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt', 'xlsx' ), true ) ) {
			return new \WP_Error( 'lsec_invalid', 'Erlaubt sind CSV- und Excel-Dateien (.csv, .txt, .xlsx).', array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD ) {
			return new \WP_Error( 'lsec_invalid', 'Die Datei ist größer als 10 MB.', array( 'status' => 400 ) );
		}
		$dir = self::upload_dir();
		if ( null === $dir ) {
			return new \WP_Error( 'lsec_upload', 'Der Upload-Ordner ist nicht beschreibbar.', array( 'status' => 500 ) );
		}
		$token = bin2hex( random_bytes( 16 ) );
		$path  = $dir . '/' . $token . '.' . $ext;
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $path ) ) {
			return new \WP_Error( 'lsec_upload', 'Die Datei konnte nicht gespeichert werden.', array( 'status' => 500 ) );
		}
		set_transient(
			'lsec_import_' . $token,
			array(
				'path' => $path,
				'name' => $name,
				'user' => get_current_user_id(),
			),
			HOUR_IN_SECONDS
		);
		try {
			$table   = TableReader::read( $path, $name );
			$profile = ImportProfile::detect( $table['header'], 'xlsx' === $ext ? 'xlsx' : 'csv' );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			self::discard( $token );
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 422 ) );
		}
		return new \WP_REST_Response(
			array(
				'token'   => $token,
				'name'    => $name,
				'header'  => $table['header'],
				'sample'  => array_slice( $table['rows'], 0, 5 ),
				'rows'    => count( $table['rows'] ),
				'profile' => array(
					'id'     => $profile->id,
					'label'  => $profile->label,
					'source' => $profile->source,
				),
				'mapping' => $profile->mapping,
				'fields'  => self::FIELD_LABELS,
			)
		);
	}

	/**
	 * Importiert die hochgeladene Datei mit der gewählten Zuordnung und startet die Prüfung der Ausschlüsse.
	 *
	 * @param \WP_REST_Request $request Anfrage mit token, mapping (Feld → Spalte), source.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$token = (string) $request->get_param( 'token' );
		$info  = preg_match( '/^[a-f0-9]{32}$/', $token ) ? get_transient( 'lsec_import_' . $token ) : false;
		if ( ! is_array( $info ) || ! is_readable( (string) $info['path'] ) ) {
			return new \WP_Error( 'lsec_invalid', 'Die Vorschau ist abgelaufen. Bitte die Datei noch einmal hochladen.', array( 'status' => 400 ) );
		}
		$mapping = array();
		foreach ( (array) $request->get_param( 'mapping' ) as $field => $column ) {
			$mapping[ (string) $field ] = sanitize_text_field( (string) $column );
		}
		if ( '' === ( $mapping['keyword'] ?? '' ) ) {
			return new \WP_Error( 'lsec_invalid', 'Bitte die Spalte mit den Keywords wählen.', array( 'status' => 400 ) );
		}
		try {
			$result = KeywordImporter::from_file( (string) $info['path'], (string) $info['name'], '', '', sanitize_text_field( (string) $request->get_param( 'source' ) ), $mapping );
			$saved  = ( new KeywordRepository() )->save_import( $result, 'de_DE', '', get_current_user_id() );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 422 ) );
		}
		self::discard( $token );
		$run = KeywordSyncWorkflow::start(
			$this->plugin,
			array(
				'gsc'    => false,
				'reason' => 'import',
			)
		);
		$this->plugin->logger->audit(
			'Keywords importiert',
			array(
				'import_id' => $saved['import_id'],
				'source'    => $result->profile->source,
				'user_id'   => get_current_user_id(),
			)
		);
		return new \WP_REST_Response(
			$saved + array(
				'rows_total'   => $result->rows_total,
				'rows_skipped' => $result->rows_skipped,
				'notes'        => $result->notes,
				'source'       => $result->profile->source,
				'run_id'       => null === $run ? null : $run['run_id'],
			)
		);
	}

	/**
	 * Ordner für Uploads; alte Dateien (über einen Tag) werden dabei entfernt.
	 */
	private static function upload_dir(): ?string {
		$base = wp_upload_dir();
		if ( ! empty( $base['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( (string) $base['basedir'] ) . 'lsec-import';
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Stille.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		foreach ( (array) glob( $dir . '/*.{csv,txt,xlsx}', GLOB_BRACE ) as $old ) {
			if ( is_string( $old ) && filemtime( $old ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}
		return $dir;
	}

	/**
	 * Entfernt Datei und Vermerk eines Uploads.
	 *
	 * @param string $token Kennung.
	 */
	private static function discard( string $token ): void {
		$info = get_transient( 'lsec_import_' . $token );
		if ( is_array( $info ) && is_string( $info['path'] ?? null ) ) {
			wp_delete_file( $info['path'] );
		}
		delete_transient( 'lsec_import_' . $token );
	}

	/**
	 * UTC-Zeit aus der Datenbank als ISO 8601.
	 *
	 * @param string $utc Zeit.
	 */
	private static function iso( string $utc ): string {
		return gmdate( 'c', (int) strtotime( $utc . ' UTC' ) );
	}
}
