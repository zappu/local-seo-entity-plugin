<?php
/**
 * Ergebnis eines Imports vor dem Speichern.
 *
 * @package Lsec
 */

namespace Lsec\Research\Import;

/**
 * Aufbereitete Keywords, Zähler und Hinweise.
 */
final class ImportResult {

	/**
	 * Keywords.
	 *
	 * @var array<int, array{keyword: string, normalized: string, intent: ?string, volume: ?int, difficulty: ?float, cpc: ?float, trend: ?array{labeled: bool, values: int[], min: int, max: int}}>
	 */
	public array $keywords = array();

	/**
	 * Hinweise für die Anzeige und lsec_imports.notes.
	 *
	 * @var string[]
	 */
	public array $notes = array();

	/**
	 * Datenzeilen der Datei.
	 *
	 * @var int
	 */
	public int $rows_total = 0;

	/**
	 * Übersprungene Zeilen (leer oder doppelt).
	 *
	 * @var int
	 */
	public int $rows_skipped = 0;

	/**
	 * Alle Volumina wurden als „unbekannt“ gespeichert.
	 *
	 * @var bool
	 */
	public bool $volumes_unknown = false;

	/**
	 * Dateiname.
	 *
	 * @var string
	 */
	public string $filename = '';

	/**
	 * Erkannte Kodierung.
	 *
	 * @var string
	 */
	public string $encoding = '';

	/**
	 * Erkanntes Trennzeichen.
	 *
	 * @var string
	 */
	public string $delimiter = '';

	/**
	 * Konstruktor.
	 *
	 * @param ImportProfile $profile Verwendete Vorlage.
	 */
	public function __construct( public readonly ImportProfile $profile ) {}
}
