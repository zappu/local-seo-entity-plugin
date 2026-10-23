<?php
/**
 * WP-CLI: Einrichtungsassistent.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Setup\SetupWizard;
use WP_CLI;

/**
 * Zeigt den Stand der Einrichtung und führt einzelne Schritte aus.
 */
final class SetupCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Stand der Einrichtung oder einen Schritt ausführen.
	 *
	 * ## OPTIONS
	 *
	 * [<schritt>]
	 * : website (Profil und Servicegebiet aus der Website), ki (erprobte Modelle), stil (Style Mimic) oder start (Index, Keywords, erste Themen). Ohne Angabe: Stand aller Schritte.
	 *
	 * [--radius=<km>]
	 * : Umkreis des Servicegebiets um den Firmensitz (Schritt website).
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--text=<datei>]
	 * : Stilvorgabe als Textdatei, z. B. ein Prompt aus einem anderen Schreibwerkzeug (Schritt stil). Ohne: aus den eigenen Seiten.
	 *
	 * [--force]
	 * : Vorhandene KI-Einstellungen durch die erprobten Werte ersetzen (Schritt ki).
	 *
	 * [--format=<format>]
	 * : table oder json (nur Stand).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec setup
	 *     wp lsec setup website --radius=40
	 *     wp lsec setup ki
	 *     wp lsec setup stil --text=stil.txt
	 *     wp lsec setup start
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$step = (string) ( $args[0] ?? '' );
		if ( '' === $step ) {
			$this->show( (string) ( $assoc_args['format'] ?? 'table' ) );
			return;
		}
		$options = array(
			'radius' => (float) ( $assoc_args['radius'] ?? SetupWizard::DEFAULT_RADIUS_KM ),
			'force'  => ! empty( $assoc_args['force'] ),
		);
		if ( ! empty( $assoc_args['text'] ) ) {
			$path = (string) $assoc_args['text'];
			if ( ! is_readable( $path ) ) {
				WP_CLI::error( 'Datei nicht lesbar: ' . $path );
			}
			$options['text'] = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		try {
			$result = SetupWizard::run( $this->plugin, $step, $options );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			WP_CLI::error( $this->plugin->logger->redact( $e->getMessage() ) );
		}
		$this->plugin->logger->audit(
			'Einrichtung: Schritt ausgeführt',
			array(
				'step' => $step,
				'cost' => $result['cost'],
				'via'  => 'cli',
			)
		);
		foreach ( $result['messages'] as $message ) {
			WP_CLI::log( $message );
		}
		WP_CLI::success( sprintf( 'Schritt %s erledigt (Kosten %s USD). Stand: wp lsec setup', $step, number_format( $result['cost'], 4, ',', '' ) ) );
	}

	/**
	 * Stand ausgeben.
	 *
	 * @param string $format table oder json.
	 */
	private function show( string $format ): void {
		$status = SetupWizard::status( $this->plugin );
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}
		WP_CLI::log( sprintf( 'Einrichtung: %d von %d Schritten erledigt', $status['done'], $status['total'] ) );
		$number = 0;
		foreach ( $status['steps'] as $step ) {
			++$number;
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '%d. %s – %s', $number, $step['title'], $step['done'] ? 'erledigt' : 'offen' ) );
			foreach ( (array) $step['items'] as $item ) {
				WP_CLI::log( sprintf( '   [%s] %s: %s', $item['ok'] ? 'x' : ( $item['optional'] ? '-' : ' ' ), $item['label'], $item['detail'] ) );
				if ( '' !== $item['paste'] ) {
					WP_CLI::log( '       ' . $item['paste'] );
				}
			}
			$gaps = array() !== array_filter( (array) $step['items'], static fn( array $i ): bool => ! $i['ok'] );
			if ( null !== $step['action'] && ( ! $step['done'] || ( 'ki' === $step['key'] && $gaps ) ) ) {
				WP_CLI::log( sprintf( '   → wp lsec setup %s  (%s)', $step['key'], $step['action']['cost'] ) );
			}
		}
	}
}
