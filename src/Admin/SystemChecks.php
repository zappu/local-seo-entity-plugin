<?php
/**
 * Sicherheitsprüfungen für Backend und WP-CLI.
 *
 * @package Lsec
 */

namespace Lsec\Admin;

use Lsec\Cli\GscCommand;
use Lsec\Core\Plugin;
use Lsec\Infrastructure\Security\SecretScan;
use Lsec\Infrastructure\Security\Secrets;
use Lsec\Rest\RestController;

/**
 * Schlüssel im Klartext (M8) und Zugriffsschutz der Schnittstellen (M15). Früher nur in
 * `wp lsec check`; seit 0.11.0 auch über den Knopf „Sicherheit prüfen“ auf der Seite Technik.
 */
final class SystemChecks {

	/**
	 * Sucht die eingestellten Schlüssel im Klartext in Datenbank und Protokollen. Liefert nur Fundorte
	 * und Anzahl, nie einen Schlüssel.
	 *
	 * @param Plugin   $plugin Plugin.
	 * @param string[] $extra  Weitere Protokolldateien.
	 * @return array{rows: list<array{geheimnis: string, quelle: string, fundorte: string}>, logs: list<string>, unreadable: list<string>, found: int}
	 */
	public static function secrets( Plugin $plugin, array $extra = array() ): array {
		$targets = array();
		foreach ( Secrets::NAMES as $name ) {
			$value = $plugin->secrets->get( $name );
			if ( null !== $value && '' !== $value ) {
				$targets[ $name ] = array(
					'source'  => $plugin->secrets->source( $name ),
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
				'geheimnis' => (string) $name,
				'quelle'    => (string) $target['source'],
				'fundorte'  => array() === $places ? 'keine' : implode( ', ', $places ),
			);
		}
		return array(
			'rows'       => $rows,
			'logs'       => array_values( array_filter( $logs, static fn( string $l ): bool => is_readable( $l ) ) ),
			'unreadable' => array_values( array_filter( $extra, static fn( string $l ): bool => ! is_readable( $l ) ) ),
			'found'      => $found,
		);
	}

	/**
	 * Prüft alle Routen unter lsec/v1 als Besucher, Abonnent und Administrator. Erwartet: 403 für
	 * Besucher und Abonnent, kein 403 für den Administrator.
	 *
	 * @param bool $dispatch Für Besucher und Abonnent die Anfrage wirklich stellen (WP-CLI). Ohne nur die
	 *                       Zugriffsprüfung der Route (Backend; ändert nichts und ruft keine Daten ab).
	 * @return array{rows: list<array{methode: string, route: string, besucher: string, abonnent: string, administrator: string}>, failed: int}
	 */
	public static function rest( bool $dispatch ): array {
		$server     = rest_get_server();
		$all        = $server->get_routes();
		$routes     = array_filter( array_keys( $all ), static fn( string $r ): bool => str_starts_with( $r, '/' . RestController::NAMESPACE . '/' ) );
		$current    = get_current_user_id();
		$subscriber = self::temporary_user( 'subscriber' );
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
		try {
			foreach ( $routes as $route ) {
				foreach ( $all[ $route ] as $handler ) {
					foreach ( array_keys( (array) $handler['methods'] ) as $method ) {
						$path = (string) preg_replace( '/\(\?P<[a-z_]+>[^)]+\)/', '1', $route );
						$row  = array(
							'methode' => (string) $method,
							'route'   => $path,
						);
						foreach ( $users as $label => $user_id ) {
							wp_set_current_user( $user_id );
							if ( $dispatch && ( 'administrator' !== $label || 'GET' === $method ) ) {
								$request = new \WP_REST_Request( $method, $path );
								if ( 'POST' === $method ) {
									$request->set_param( 'decision', 'rejected' );
								}
								$status = $server->dispatch( $request )->get_status();
							} else {
								// Nur die Zugriffsprüfung auswerten, ohne Daten zu ändern.
								$status = self::permission_status( $handler );
							}
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
		} finally {
			wp_set_current_user( $current );
			if ( $subscriber > 0 ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $subscriber );
			}
		}
		return array(
			'rows'   => $rows,
			'failed' => $failed,
		);
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
	 * Ergebnis der Zugriffsprüfung als HTTP-Status.
	 *
	 * @param array<string, mixed> $handler Routen-Handler.
	 */
	private static function permission_status( array $handler ): int {
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
	private static function temporary_user( string $role ): int {
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
