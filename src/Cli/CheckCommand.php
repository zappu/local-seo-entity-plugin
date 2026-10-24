<?php
/**
 * WP-CLI: Prüfungen für den Machbarkeitstest.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Admin\SystemChecks;
use Lsec\Content\ContentReader;
use Lsec\Core\Plugin;
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
		$extra  = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['log'] ?? '' ) ) ) ) );
		$result = SystemChecks::secrets( $this->plugin, $extra );
		foreach ( $result['unreadable'] as $file ) {
			WP_CLI::warning( 'Nicht lesbar: ' . $file );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'secrets' => $result['rows'],
						'logs'    => array_map( 'basename', $result['logs'] ),
						'found'   => $result['found'],
					)
				)
			);
			return;
		}
		if ( array() === $result['rows'] ) {
			WP_CLI::warning( 'Kein Geheimnis eingestellt; nichts zu prüfen.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $result['rows'], array( 'geheimnis', 'quelle', 'fundorte' ) );
		WP_CLI::log( 'Geprüfte Protokolle: ' . ( array() === $result['logs'] ? 'keine lesbaren' : implode( ', ', $result['logs'] ) ) );
		if ( $result['found'] > 0 ) {
			WP_CLI::error( sprintf( '%d Fundstelle(n). Die betroffenen Schlüssel beim Anbieter erneuern und die Fundstellen löschen.', $result['found'] ) );
		}
		WP_CLI::success( 'Kein Schlüssel im Klartext gefunden (M8).' );
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
		unset( $args );
		$result = SystemChecks::rest( true );
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $result['rows'], array( 'methode', 'route', 'besucher', 'abonnent', 'administrator' ) );
		if ( array() === $result['rows'] ) {
			WP_CLI::error( 'Keine Routen unter ' . RestController::NAMESPACE . ' gefunden.' );
		}
		if ( $result['failed'] > 0 ) {
			WP_CLI::error( $result['failed'] . ' Abweichung(en).' );
		}
		WP_CLI::success( count( $result['rows'] ) . ' Routen geschützt.' );
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
}
