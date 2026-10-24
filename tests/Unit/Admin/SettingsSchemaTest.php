<?php
/**
 * Tests für die Aufteilung der Einstellungen.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Admin;

use Lsec\Admin\SettingsSchema;
use Lsec\Core\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Jedes Feld steht genau einmal in einem Reiter.
 */
final class SettingsSchemaTest extends TestCase {

	public function test_jedes_feld_genau_einmal_in_einem_reiter(): void {
		$tabs = SettingsSchema::tab_keys();
		$this->assertSame( array(), array_keys( array_filter( array_count_values( $tabs ), static fn( int $n ): bool => $n > 1 ) ), 'doppelt' );
		$this->assertSame( array(), array_values( array_diff( SettingsSchema::keys(), $tabs ) ), 'fehlt in den Reitern' );
		$this->assertSame( array(), array_values( array_diff( $tabs, SettingsSchema::keys() ) ), 'unbekannt' );
	}

	public function test_alle_felder_sind_einstellungen(): void {
		foreach ( SettingsSchema::keys() as $key ) {
			$this->assertArrayHasKey( $key, Settings::DEFAULTS, $key );
		}
	}
}
