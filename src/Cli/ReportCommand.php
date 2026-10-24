<?php
/**
 * WP-CLI: Content- und Entity-Bericht.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Report\ContentReport;
use WP_CLI;

/**
 * Inhalte, Entitäten und Hinweise „bestehende Seite verbessern“.
 */
final class ReportCommand {

	/**
	 * Zeigt den Bericht.
	 *
	 * ## OPTIONS
	 *
	 * [--section=<teil>]
	 * : content, entities, improve oder images; ohne Angabe alle.
	 *
	 * [--format=<format>]
	 * : table, json oder csv (csv nur für improve).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec report
	 *     wp lsec report --section=improve --format=csv > massnahmen.csv
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$section = (string) ( $assoc_args['section'] ?? '' );
		$format  = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'csv' === $format ) {
			WP_CLI::line( rtrim( ContentReport::csv( ContentReport::improve() ) ) );
			return;
		}
		$report = ContentReport::build();
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( '' === $section ? $report : ( $report[ $section ] ?? null ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( '' === $section || 'images' === $section ) {
			$m = (array) $report['images'];
			WP_CLI::log( sprintf( 'Bilder: %d in der Mediathek · %d beschrieben, %d offen · frei als Beitragsbild %d · schon genutzt %d · KI-Bilder %d', $m['images'], $m['described'], $m['pending'], $m['free'], $m['used'], $m['generated'] ) );
		}
		if ( '' === $section || 'content' === $section ) {
			$c = $report['content'];
			$d = $c['drafts'];
			WP_CLI::log( sprintf( 'Inhalte: %d von %d gelesen · Entwürfe des Plugins: %d (%s) · Prüfung bestanden: %d · Ø Bewertung %s · Kosten %s USD (je Entwurf %s USD)', $c['indexed'], $c['published'], $d['count'], self::pairs( (array) $d['by_status'] ), $d['passed'], null === $d['average_score'] ? '–' : number_format( (float) $d['average_score'], 1, ',', '' ), number_format( (float) $d['cost'], 2, ',', '' ), null === $d['cost_per'] ? '–' : number_format( (float) $d['cost_per'], 2, ',', '' ) ) );
			Output::items(
				'table',
				array_map(
					static fn( array $i ): array => array(
						'beitrag'   => $i['post_id'],
						'titel'     => mb_substr( (string) $i['title'], 0, 60 ),
						'status'    => $i['status'],
						'prüfung'   => $i['passed'] ? 'bestanden' : 'blockiert',
						'bewertung' => $i['score'] ?? '',
						'kosten'    => $i['cost'],
					),
					(array) $c['items']
				),
				array( 'beitrag', 'titel', 'status', 'prüfung', 'bewertung', 'kosten' )
			);
		}
		if ( '' === $section || 'entities' === $section ) {
			$e = $report['entities'];
			foreach ( (array) $e['counts'] as $type => $by ) {
				WP_CLI::log( sprintf( 'Entitäten %s: %s', $type, self::pairs( (array) $by ) ) );
			}
			WP_CLI::log( 'Häufigste in eigenen Inhalten: ' . implode( ', ', array_map( static fn( array $x ): string => $x['name'] . ' (' . $x['posts'] . ')', (array) $e['top'] ) ) );
			WP_CLI::log( 'Leistungen und Orte ohne eigenen Inhalt: ' . ( array() === $e['gaps'] ? 'keine' : implode( ', ', array_column( (array) $e['gaps'], 'name' ) ) ) );
		}
		if ( '' === $section || 'improve' === $section ) {
			WP_CLI::log( sprintf( 'Bestehende Seiten verbessern: %d Hinweise', count( $report['improve'] ) ) );
			Output::items(
				'table',
				array_map(
					static fn( array $i ): array => array(
						'seite'        => self::path( (string) $i['page_url'] ),
						'keyword'      => $i['keyword'],
						'position'     => $i['position'] ?? '',
						'impressionen' => $i['impressions'] ?? '',
						'hinweis'      => mb_substr( (string) $i['reason'], 0, 90 ),
					),
					$report['improve']
				),
				array( 'seite', 'keyword', 'position', 'impressionen', 'hinweis' )
			);
		}
	}

	/**
	 * Pfad mit Abfrage („/?page_id=12“).
	 *
	 * @param string $url Adresse.
	 */
	private static function path( string $url ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return (string) wp_parse_url( $url, PHP_URL_PATH ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * „a: 1, b: 2“.
	 *
	 * @param array<string, int> $pairs Werte.
	 */
	private static function pairs( array $pairs ): string {
		$out = array();
		foreach ( $pairs as $key => $value ) {
			$out[] = $key . ': ' . $value;
		}
		return array() === $out ? '–' : implode( ', ', $out );
	}
}
