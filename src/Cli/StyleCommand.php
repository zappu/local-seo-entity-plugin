<?php
/**
 * WP-CLI: Style Mimic (Spezifikation 8.7).
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Production\StyleProfiler;
use Lsec\Production\StyleProfileRepository;
use WP_CLI;

/**
 * Profil aus eigenen Beiträgen erzeugen, anzeigen, von Hand setzen, Versionen wechseln.
 */
final class StyleCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Erzeugt ein Profil aus eigenen Beiträgen, Beispieltexten und festen Regeln (ein KI-Aufruf, wenige Cent).
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<ids>]
	 * : Beitrags-IDs, kommagetrennt (veröffentlicht), z. B. 12,48,51.
	 *
	 * [--samples=<file>]
	 * : Textdatei mit Beispieltexten, getrennt durch eine Zeile mit ---.
	 *
	 * [--rules=<regeln>]
	 * : Feste Regeln, getrennt durch |, z. B. "Anrede Sie|keine Ausrufezeichen in Überschriften".
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec style build --posts=12,48,51 --rules="Anrede Sie|keine Preise nennen"
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function build( array $args, array $assoc_args ): void {
		$posts   = array_filter( array_map( 'intval', explode( ',', (string) ( $assoc_args['posts'] ?? '' ) ) ) );
		$samples = array();
		if ( ! empty( $assoc_args['samples'] ) ) {
			$raw     = (string) file_get_contents( (string) $assoc_args['samples'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$parts   = preg_split( '/^\s*---\s*$/m', $raw );
			$samples = false === $parts ? array() : $parts;
		}
		$rules = array_filter( array_map( 'trim', explode( '|', (string) ( $assoc_args['rules'] ?? '' ) ) ) );
		try {
			$built = ( new StyleProfiler( $this->plugin->ai->service() ) )->build( $posts, $samples, $rules );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$version = ( new StyleProfileRepository() )->save( $built['profile'], $built['inputs'], get_current_user_id() );
		$this->plugin->logger->audit(
			'Style Mimic gespeichert',
			array(
				'version' => $version,
				'via'     => 'cli',
			)
		);
		$this->print( $built['profile'] );
		WP_CLI::success( sprintf( 'Version %d gespeichert und aktiv (Kosten %s USD).', $version, null === $built['cost'] ? 'unbekannt' : number_format( (float) $built['cost'], 4, ',', '' ) ) );
	}

	/**
	 * Zeigt das aktive Profil oder eine Version.
	 *
	 * ## OPTIONS
	 *
	 * [<version>]
	 * : Version; ohne Angabe die aktive.
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
	public function show( array $args, array $assoc_args ): void {
		$repo = new StyleProfileRepository();
		$row  = isset( $args[0] ) ? $repo->find( (int) $args[0] ) : $repo->active();
		if ( null === $row ) {
			WP_CLI::error( 'Kein Profil. Anlegen: wp lsec style build --posts=<ids>' );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			WP_CLI::line( (string) wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}
		WP_CLI::log( sprintf( 'Version %d%s · %s', $row['version'], $row['is_active'] ? ' (aktiv)' : '', $row['created_at'] ) );
		$this->print( (array) $row['profile'] );
	}

	/**
	 * Versionen.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function list( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( ( new StyleProfileRepository() )->versions() as $row ) {
			$rows[] = array(
				'version'  => $row['version'],
				'aktiv'    => $row['is_active'] ? 'ja' : '',
				'herkunft' => (string) ( $row['inputs']['origin'] ?? '' ),
				'beiträge' => implode( ',', (array) ( $row['inputs']['post_ids'] ?? array() ) ),
				'erstellt' => $row['created_at'],
			);
		}
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'version', 'aktiv', 'herkunft', 'beiträge', 'erstellt' ) );
	}

	/**
	 * Macht eine Version wieder aktiv.
	 *
	 * ## OPTIONS
	 *
	 * <version>
	 * : Version.
	 *
	 * @param string[] $args Argumente.
	 */
	public function activate( array $args ): void {
		if ( ! ( new StyleProfileRepository() )->activate( (int) ( $args[0] ?? 0 ) ) ) {
			WP_CLI::error( 'Version nicht gefunden.' );
		}
		WP_CLI::success( 'Version ' . (int) $args[0] . ' ist aktiv.' );
	}

	/**
	 * Speichert ein von Hand bearbeitetes Profil als neue Version.
	 *
	 * ## OPTIONS
	 *
	 * --file=<file>
	 * : JSON-Datei mit dem Profil (Felder wie in wp lsec style show --format=json, Schlüssel profile).
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function set( array $args, array $assoc_args ): void {
		$data = json_decode( (string) file_get_contents( (string) $assoc_args['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'Die Datei enthält kein gültiges JSON.' );
		}
		$profile = is_array( $data['profile'] ?? null ) ? $data['profile'] : $data;
		$version = ( new StyleProfileRepository() )->save( $profile, array( 'origin' => 'manual' ), get_current_user_id() );
		WP_CLI::success( 'Version ' . $version . ' gespeichert und aktiv.' );
	}

	/**
	 * Gibt ein Profil aus.
	 *
	 * @param array<string, mixed> $profile Profil.
	 */
	private function print( array $profile ): void {
		WP_CLI::log( StyleProfiler::prompt_text( $profile ) );
	}
}
