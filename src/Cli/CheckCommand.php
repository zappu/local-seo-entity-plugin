<?php
/**
 * WP-CLI: Prüfungen für den Machbarkeitstest.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Content\ContentReader;
use Lsec\Core\Plugin;
use Lsec\Infrastructure\Security\SecretScan;
use Lsec\Infrastructure\Security\Secrets;
use Lsec\Rest\RestController;
use WP_CLI;

/**
 * Prüfpunkte, die sich ohne Kosten auf jeder Installation wiederholen lassen.
 */
final class CheckCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin|null $plugin Plugin (für die Geheimnis-Prüfung).
	 */
	public function __construct( private readonly ?Plugin $plugin = null ) {}

	/**
	 * Restprüfung M8: sucht die eingestellten Schlüssel im Klartext in der Datenbank und im Protokoll.
	 *
	 * Durchsucht alle Plugin-Tabellen, wp_options (ohne die verschlüsselte Ablage), wp_postmeta,
	 * wp_usermeta und die Tabellen von Action Scheduler sowie debug.log, das PHP-Fehlerprotokoll und
	 * unter Plesk die Fehlerprotokolle im Ordner logs neben dem Webroot (error_log, proxy_error_log).
	 * Gezeigt werden nur Fundorte und Anzahl, nie ein Schlüssel.
	 *
	 * ## OPTIONS
	 *
	 * [--log=<dateien>]
	 * : Weitere Protokolldateien, mehrere mit Komma getrennt.
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec check secrets
	 *     wp lsec check secrets --log=/var/log/php-fpm/www-error.log
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function secrets( array $args, array $assoc_args ): void {
		if ( null === $this->plugin ) {
			WP_CLI::error( 'Plugin nicht geladen.' );
		}
		$targets = array();
		foreach ( Secrets::NAMES as $name ) {
			$value = $this->plugin->secrets->get( $name );
			if ( null !== $value && '' !== $value ) {
				$targets[ $name ] = array(
					'source'  => $this->plugin->secrets->source( $name ),
					'needles' => SecretScan::needles( $value ),
				);
			}
		}
		$gsc = GscCommand::credentials_file();
		if ( is_readable( $gsc ) ) {
			$data = json_decode( (string) file_get_contents( $gsc ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $data ) && ! empty( $data['private_key'] ) ) {
				$targets['gsc_private_key'] = array(
					'source'  => 'datei',
					'needles' => SecretScan::pem_needles( (string) $data['private_key'] ),
				);
			}
		}

		// WP_DEBUG_LOG ist true (dann wp-content/debug.log) oder ein eigener Pfad.
		$debug_log = defined( 'WP_DEBUG_LOG' ) ? (string) constant( 'WP_DEBUG_LOG' ) : '';
		$extra     = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['log'] ?? '' ) ) ) ) );
		$logs      = array_values(
			array_unique(
				array_filter(
					array_merge(
						array(
							defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : '',
							str_contains( $debug_log, '/' ) ? $debug_log : '',
							(string) ini_get( 'error_log' ),
						),
						self::plesk_logs( dirname( untrailingslashit( ABSPATH ) ) . '/logs' ),
						$extra
					)
				)
			)
		);
		foreach ( $extra as $file ) {
			if ( ! is_readable( $file ) ) {
				WP_CLI::warning( 'Nicht lesbar: ' . $file );
			}
		}

		$rows  = array();
		$found = 0;
		foreach ( $targets as $name => $target ) {
			$places = array();
			foreach ( SecretScan::scan_database( $target['needles'] ) as $hit ) {
				$places[] = $hit['table'] . '.' . $hit['column'] . ' (' . $hit['rows'] . ')';
				$found   += $hit['rows'];
			}
			foreach ( $logs as $log ) {
				$lines = SecretScan::scan_file( $log, $target['needles'] );
				if ( null !== $lines && $lines > 0 ) {
					$places[] = basename( $log ) . ' (' . $lines . ' Zeilen)';
					$found   += $lines;
				}
			}
			$rows[] = array(
				'geheimnis' => $name,
				'quelle'    => $target['source'],
				'fundorte'  => array() === $places ? 'keine' : implode( ', ', $places ),
			);
		}

		$checked = array_values( array_filter( $logs, static fn( string $l ): bool => is_readable( $l ) ) );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'secrets' => $rows,
						'logs'    => array_map( 'basename', $checked ),
						'found'   => $found,
					)
				)
			);
			return;
		}
		if ( array() === $rows ) {
			WP_CLI::warning( 'Kein Geheimnis eingestellt; nichts zu prüfen.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'geheimnis', 'quelle', 'fundorte' ) );
		WP_CLI::log( 'Geprüfte Protokolle: ' . ( array() === $checked ? 'keine lesbaren' : implode( ', ', $checked ) ) );
		if ( $found > 0 ) {
			WP_CLI::error( sprintf( '%d Fundstelle(n). Die betroffenen Schlüssel beim Anbieter erneuern und die Fundstellen löschen.', $found ) );
		}
		WP_CLI::success( 'Kein Schlüssel im Klartext gefunden (M8).' );
	}

	/**
	 * Fehlerprotokolle von Plesk im Ordner logs neben dem Webroot (error_log, proxy_error_log und
	 * deren noch nicht gepackte Vorgänger). Der Benutzer des Abonnements darf sie lesen.
	 *
	 * @param string $dir Ordner.
	 * @return string[]
	 */
	private static function plesk_logs( string $dir ): array {
		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return array();
		}
		$files = glob( $dir . '/*error_log*' );
		$out   = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_file( $file ) && ! preg_match( '/\.(gz|zip|bz2)$/', $file ) ) {
				$out[] = $file;
			}
		}
		return $out;
	}

	/**
	 * Ruft alle Routen unter lsec/v1 als Besucher, Abonnent und Administrator auf (M15).
	 *
	 * Erwartet: 403 für Besucher und Abonnent, kein 403 für den Administrator.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function rest( array $args, array $assoc_args ): void {
		$server = rest_get_server();
		$routes = array_filter( array_keys( $server->get_routes() ), static fn( string $r ): bool => str_starts_with( $r, '/' . RestController::NAMESPACE . '/' ) );

		$subscriber = $this->temporary_user( 'subscriber' );
		$admins     = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$users      = array(
			'besucher'      => 0,
			'abonnent'      => $subscriber,
			'administrator' => (int) ( $admins[0] ?? 0 ),
		);

		$rows   = array();
		$failed = 0;
		foreach ( $routes as $route ) {
			foreach ( $server->get_routes()[ $route ] as $handler ) {
				foreach ( array_keys( (array) $handler['methods'] ) as $method ) {
					$path = (string) preg_replace( '/\(\?P<[a-z_]+>[^)]+\)/', '1', $route );
					$row  = array(
						'methode' => $method,
						'route'   => $path,
					);
					foreach ( $users as $label => $user_id ) {
						wp_set_current_user( $user_id );
						$request = new \WP_REST_Request( $method, $path );
						if ( 'POST' === $method ) {
							$request->set_param( 'decision', 'rejected' );
						}
						// Nur die Zugriffsprüfung auswerten, ohne für Administratoren Daten zu ändern.
						$status        = 'administrator' === $label && 'GET' !== $method ? $this->permission_status( $handler ) : $server->dispatch( $request )->get_status();
						$row[ $label ] = (string) $status;
						$expected_ok   = 'administrator' === $label ? 403 !== $status : 403 === $status;
						if ( ! $expected_ok ) {
							++$failed;
						}
					}
					$rows[] = $row;
				}
			}
		}
		wp_set_current_user( 0 );
		if ( $subscriber > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $subscriber );
		}
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'methode', 'route', 'besucher', 'abonnent', 'administrator' ) );
		if ( array() === $rows ) {
			WP_CLI::error( 'Keine Routen unter ' . RestController::NAMESPACE . ' gefunden.' );
		}
		if ( $failed > 0 ) {
			WP_CLI::error( $failed . ' Abweichung(en).' );
		}
		WP_CLI::success( count( $rows ) . ' Routen geschützt.' );
	}

	/**
	 * Liest den Text eines Beitrags wie für den Inhaltsindex (M14).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Beitrag oder Seite.
	 *
	 * [--format=<format>]
	 * : text oder json.
	 * ---
	 * default: text
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function content( array $args, array $assoc_args ): void {
		$post = get_post( (int) ( $args[0] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( 'Beitrag nicht gefunden.' );
		}
		$data = ContentReader::read( $post );
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			WP_CLI::log( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}
		WP_CLI::log( sprintf( 'Quelle: %s · %d Wörter · %d Überschriften', $data['source'], $data['words'], count( $data['headings'] ) ) );
		foreach ( $data['headings'] as $heading ) {
			WP_CLI::log( str_repeat( '  ', max( 0, $heading['level'] - 1 ) ) . 'H' . $heading['level'] . ' ' . $heading['text'] );
		}
		WP_CLI::log( '---' );
		WP_CLI::log( $data['text'] );
	}

	/**
	 * Ergebnis der Zugriffsprüfung als HTTP-Status.
	 *
	 * @param array<string, mixed> $handler Routen-Handler.
	 */
	private function permission_status( array $handler ): int {
		$result = call_user_func( $handler['permission_callback'] );
		if ( $result instanceof \WP_Error ) {
			$data = $result->get_error_data();
			return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403;
		}
		return true === $result ? 200 : 403;
	}

	/**
	 * Legt einen Prüfbenutzer an.
	 *
	 * @param string $role Rolle.
	 */
	private function temporary_user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'lsec_check_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password( 32 ),
				'role'       => $role,
			)
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
