<?php
/**
 * Tests für die Werkzeuge im Backend.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Admin;

use Lsec\Admin\Tools;
use Lsec\Research\Import\ImportProfile;
use PHPUnit\Framework\TestCase;

/**
 * Auswahl der Textfocus-Sprache und Erkennung der Entitäten-Tabelle.
 */
final class ToolsTest extends TestCase {

	private const LANGS = array(
		array(
			'id'     => 'at',
			'label'  => 'Deutsch (Österreich)',
			'locale' => 'de_AT',
		),
		array(
			'id'     => 'de',
			'label'  => 'Deutsch',
			'locale' => 'de_DE',
		),
		array(
			'id'     => 'en',
			'label'  => 'English',
			'locale' => 'en_US',
		),
	);

	public function test_sprache_nach_locale_der_website(): void {
		$this->assertSame( 'de', Tools::pick_lang( self::LANGS, 'de_DE' )['id'] ?? null );
		$this->assertSame( 'at', Tools::pick_lang( self::LANGS, 'de_AT' )['id'] ?? null );
		$this->assertSame( 'en', Tools::pick_lang( self::LANGS, 'en_GB' )['id'] ?? null );
	}

	public function test_sprache_faellt_auf_deutsch_zurueck(): void {
		$this->assertSame( 'de', Tools::pick_lang( self::LANGS, 'fr_FR' )['id'] ?? null );
		$this->assertNull( Tools::pick_lang( array(), 'de_DE' ) );
	}

	public function test_entitaeten_tabelle_wird_erkannt(): void {
		$this->assertTrue( ImportProfile::is_entity_table( array( 'Entity detected on the page', 'Wikidata ID', 'Type' ) ) );
		$this->assertFalse( ImportProfile::is_entity_table( array( 'Keyword', 'Volume' ) ) );
	}
}
