<?php
/**
 * WP-CLI: Prüfungen für den Machbarkeitstest.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Content\ContentReader;
use Lsec\Rest\RestController;
use WP_CLI;

/**
 * Prüfpunkte, die sich ohne Kosten auf jeder Installation wiederholen lassen.
 */
final class CheckCommand {

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
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'methode', 'route', 'besucher', 'abonnent', 'administrator' ) );
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
