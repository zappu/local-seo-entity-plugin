<?php
/**
 * WP-CLI: Unternehmensprofil.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Entities\BusinessProfile;
use Lsec\Entities\ProfileAssistant;
use WP_CLI;

/**
 * Unternehmen, Standorte, Leistungen, Marken und Wettbewerber (Spezifikation 4.2).
 */
final class ProfileCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Zeigt das Profil.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table oder json. JSON lässt sich bearbeiten und mit save wieder einlesen.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function show( array $args, array $assoc_args ): void {
		$profile = ( new BusinessProfile() )->get();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$org = $profile['organization'];
		WP_CLI::log( 'Unternehmen: ' . ( '' !== $org['name'] ? $org['name'] . ' (' . $org['id'] . ')' : 'noch nicht angelegt' ) );
		$area = $profile['service_area'];
		WP_CLI::log( 'Servicegebiet: ' . ( is_array( $area ) && ! empty( $area['label'] ) ? $area['label'] . ( ! empty( $area['radius_km'] ) ? ', ' . $area['radius_km'] . ' km' : '' ) : 'keins' ) );
		$labels = array(
			'locations'   => 'Standorte',
			'services'    => 'Leistungen',
			'brands'      => 'Marken',
			'competitors' => 'Wettbewerber',
		);
		foreach ( $labels as $key => $label ) {
			WP_CLI::log( $label . ': ' . ( array() === $profile[ $key ] ? '–' : implode( ', ', array_column( $profile[ $key ], 'name' ) ) ) );
		}
	}

	/**
	 * Liest Seiten der Website und zeigt einen Vorschlag für das Profil (ein KI-Aufruf, wenige Cent).
	 *
	 * Gespeichert wird nichts; den Vorschlag übernimmt man unter Local SEO → Unternehmen oder per JSON und save.
	 *
	 * ## OPTIONS
	 *
	 * [<url>...]
	 * : Adressen, auch Pfade wie /impressum. Ohne Angabe die gespeicherte Liste, sonst die Startseite.
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec profile suggest / /impressum /kontakt /leistungen
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function suggest( array $args, array $assoc_args ): void {
		$urls      = array() !== $args
			? ProfileAssistant::parse_urls( implode( "\n", $args ), home_url( '/' ) )
			: array_values( array_map( 'strval', (array) \Lsec\Core\Settings::get( 'profile_source_urls' ) ) );
		$assistant = new ProfileAssistant( $this->plugin->http );
		$read      = $assistant->read( $urls );
		foreach ( $read['pages'] as $page ) {
			WP_CLI::log( sprintf( 'Gelesen: %s (%d Zeichen, %s)', $page['url'], $page['chars'], $page['source'] ) );
		}
		foreach ( $read['failed'] as $url ) {
			WP_CLI::warning( 'Nicht lesbar: ' . $url );
		}
		if ( '' === $read['text'] ) {
			WP_CLI::error( 'Kein Text gelesen.' );
		}
		try {
			$site   = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) . ' (' . home_url( '/' ) . ')';
			$result = ProfileAssistant::analyze( $this->plugin->ai->service(), $read['text'], $site );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $this->plugin->logger->redact( $e->getMessage() ) );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$p = $result['proposal'];
		WP_CLI::log( 'Unternehmen: ' . $p['organization']['name'] . ( '' !== $p['organization']['phone'] ? ' · ' . $p['organization']['phone'] : '' ) . ( '' !== $p['organization']['email'] ? ' · ' . $p['organization']['email'] : '' ) );
		foreach ( $p['locations'] as $loc ) {
			WP_CLI::log( sprintf( 'Standort: %s, %s, %s %s', $loc['name'], $loc['street'], $loc['postal_code'], $loc['city'] ) );
		}
		WP_CLI::log( 'Leistungen: ' . implode( ', ', array_column( $p['services'], 'name' ) ) );
		WP_CLI::log( 'Marken: ' . implode( ', ', array_column( $p['brands'], 'name' ) ) );
		WP_CLI::log( 'Servicegebiet: ' . $p['service_area'] );
		foreach ( $result['dropped'] as $item ) {
			WP_CLI::log( 'Verworfen: ' . $item );
		}
		WP_CLI::log( 'Kosten: ' . ( null === $result['cost'] ? 'unbekannt' : '$' . number_format( $result['cost'], 4 ) ) );
	}

	/**
	 * Speichert das Profil aus einer JSON-Datei (Aufbau wie show --format=json).
	 *
	 * Einträge, die in der Datei fehlen, werden abgelehnt, nicht gelöscht.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON-Datei.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec profile show --format=json > profil.json
	 *     wp lsec profile save profil.json
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function save( array $args, array $assoc_args ): void {
		$path = (string) ( $args[0] ?? '' );
		$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'Datei fehlt oder ist kein JSON: ' . $path );
		}
		try {
			$out = ( new BusinessProfile() )->save( $data, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->plugin->logger->audit(
			'Unternehmensprofil gespeichert',
			$out + array(
				'user_id' => get_current_user_id(),
				'via'     => 'cli',
			)
		);
		WP_CLI::success( sprintf( 'Profil gespeichert: %d Einträge, %d entfernt.', $out['saved'], $out['removed'] ) );
	}
}
