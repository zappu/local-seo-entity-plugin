<?php
/**
 * WP-CLI: Keywords importieren und anzeigen.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Research\Import\KeywordImporter;
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
	 */
	public function __construct( private readonly KeywordRepository $repository ) {}

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
}
