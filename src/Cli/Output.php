<?php
/**
 * Ausgabe von Tabellen in WP-CLI.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use WP_CLI;

/**
 * WP_CLI\Utils\format_items() schreibt JSON ohne Zeilenumbruch. Plugins, die beim Beenden etwas ausgeben
 * (z. B. der Elementor-Logger unter PHP 8.4), hängen ihre Meldung dann an dieselbe Zeile, und das JSON ist
 * maschinell nicht mehr lesbar. JSON wird deshalb hier mit Zeilenumbruch ausgegeben.
 */
final class Output {

	/**
	 * Gibt Zeilen aus.
	 *
	 * @param string                           $format table, csv, json, yaml, count oder ids.
	 * @param array<int, array<string, mixed>> $rows   Zeilen.
	 * @param string[]                         $fields Spalten.
	 */
	public static function items( string $format, array $rows, array $fields ): void {
		if ( 'json' === $format ) {
			$out = array();
			foreach ( $rows as $row ) {
				$item = array();
				foreach ( $fields as $field ) {
					$item[ $field ] = $row[ $field ] ?? null;
				}
				$out[] = $item;
			}
			WP_CLI::log( (string) wp_json_encode( $out ) );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}
}
