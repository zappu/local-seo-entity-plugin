<?php
/**
 * Liest die Entitäten-Tabelle einer Textfocus-Analyse.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Research\Import\NumberParser;
use Lsec\Research\Import\TableReader;
use Lsec\Research\Keywords\KeywordNormalizer;

/**
 * Excel-Export „Entities“ einer Seitenanalyse (geprüft am 18.10.2026). Spalten:
 * Entity detected on the page, Corresponding term, Type, English login, Relevance score, Wikidata ID, Wikipedia Link.
 *
 * Spezifikation 6.5/8.3: Von Textfocus erkannte Entitäten werden nur als Kandidaten übernommen. Die Wikidata-Zuordnung
 * von Textfocus ist ein Vorschlag (Beispiel aus dem Test: „Böden“ → Boden (Bodenkunde)) und wird nicht als
 * bestätigte externe ID gespeichert, sondern als Hinweis in den Eigenschaften.
 */
final class TextfocusEntityImport {

	/**
	 * Typen von Textfocus → Typen des Plugins.
	 */
	private const TYPES = array(
		'location'      => 'place',
		'organization'  => 'organization',
		'person'        => 'person',
		'product'       => 'product',
		'event'         => 'concept',
		'creative work' => 'concept',
		'other'         => 'concept',
	);

	/**
	 * Liest eine Datei.
	 *
	 * @param string $path     Pfad.
	 * @param string $filename Originaler Dateiname.
	 * @return array<int, array{name: string, entity: string, type: string, source_type: string, relevance: float|null, wikidata: string, wikipedia: string}>
	 * @throws \InvalidArgumentException Wenn die Datei keine Entitäten-Tabelle ist.
	 */
	public static function from_file( string $path, string $filename = '' ): array {
		$table = TableReader::read( $path, '' !== $filename ? $filename : $path );
		return self::parse( $table['header'], $table['rows'] );
	}

	/**
	 * Wandelt die Zeilen um.
	 *
	 * @param string[]        $header Kopfzeile.
	 * @param array<string[]> $rows   Zeilen.
	 * @return array<int, array{name: string, entity: string, type: string, source_type: string, relevance: float|null, wikidata: string, wikipedia: string}>
	 * @throws \InvalidArgumentException Wenn Spalten fehlen.
	 */
	public static function parse( array $header, array $rows ): array {
		$index = array();
		foreach ( $header as $i => $column ) {
			$index[ mb_strtolower( trim( $column ), 'UTF-8' ) ] = $i;
		}
		if ( ! isset( $index['entity detected on the page'], $index['type'] ) ) {
			throw new \InvalidArgumentException( 'Keine Entitäten-Tabelle von Textfocus. Erwartet werden die Spalten „Entity detected on the page“ und „Type“.' );
		}
		$cell = static fn( array $row, string $key ): string => isset( $index[ $key ] ) ? trim( (string) ( $row[ $index[ $key ] ] ?? '' ) ) : '';

		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$entity = $cell( $row, 'entity detected on the page' );
			$term   = $cell( $row, 'corresponding term' );
			$name   = '' !== $term ? $term : $entity;
			if ( '' === $name ) {
				continue;
			}
			$source_type = $cell( $row, 'type' );
			$type        = self::TYPES[ mb_strtolower( $source_type, 'UTF-8' ) ] ?? 'concept';
			$key         = $type . '|' . KeywordNormalizer::normalize( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$wikidata     = $cell( $row, 'wikidata id' );
			$wikipedia    = $cell( $row, 'wikipedia link' );
			$out[]        = array(
				'name'        => $name,
				'entity'      => $entity,
				'type'        => $type,
				'source_type' => $source_type,
				'relevance'   => NumberParser::float( $cell( $row, 'relevance score' ) ),
				'wikidata'    => preg_match( '/^Q\d+$/', $wikidata ) ? $wikidata : '',
				'wikipedia'   => '' === $wikipedia ? '' : ( preg_match( '#^https?://#', $wikipedia ) ? $wikipedia : 'https://' . $wikipedia ),
			);
		}
		return $out;
	}
}
