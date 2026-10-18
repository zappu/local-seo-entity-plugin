<?php
/**
 * Tests für JsonSchemaValidator und Json.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\AI;

use Lsec\Integrations\AI\Json;
use Lsec\Integrations\AI\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Teilmenge von JSON-Schema, die alle drei Anbieter verstehen.
 */
final class JsonSchemaValidatorTest extends TestCase {

	/**
	 * Schema einer Entität wie in Spezifikation 8.3.
	 *
	 * @return array<string, mixed>
	 */
	private static function entity_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'       => array( 'type' => 'string' ),
				'type'       => array(
					'type' => 'string',
					'enum' => array( 'organization', 'place', 'service' ),
				),
				'confidence' => array( 'type' => 'number' ),
				'wikidata'   => array( 'type' => array( 'string', 'null' ) ),
				'aliases'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'name', 'type', 'confidence', 'wikidata', 'aliases' ),
			'additionalProperties' => false,
		);
	}

	public function test_valid_data_has_no_errors(): void {
		$data = array(
			'name'       => 'Uetze',
			'type'       => 'place',
			'confidence' => 1,
			'wikidata'   => null,
			'aliases'    => array(),
		);
		$this->assertSame( array(), JsonSchemaValidator::validate( $data, self::entity_schema() ) );
	}

	public function test_errors_are_reported_with_path(): void {
		$data   = array(
			'name'       => 'Uetze',
			'type'       => 'stadt',
			'confidence' => '0.9',
			'aliases'    => array( 'Ütze', 3 ),
			'extra'      => true,
		);
		$errors = JsonSchemaValidator::validate( $data, self::entity_schema() );
		$this->assertContains( '$.wikidata: fehlt', $errors );
		$this->assertContains( '$.type: Wert nicht erlaubt', $errors );
		$this->assertContains( '$.confidence: erwartet number, erhalten string', $errors );
		$this->assertContains( '$.aliases[1]: erwartet string, erhalten int', $errors );
		$this->assertContains( '$.extra: nicht erlaubt', $errors );
	}

	public function test_list_is_not_an_object(): void {
		$errors = JsonSchemaValidator::validate( array( 'a', 'b' ), self::entity_schema() );
		$this->assertSame( array( '$: erwartet object, erhalten array' ), $errors );
	}

	public function test_extract_handles_fences_and_surrounding_text(): void {
		$this->assertSame( array( 'a' => 1 ), Json::extract( "```json\n{\"a\":1}\n```" ) );
		$this->assertSame( array( 'a' => 1 ), Json::extract( 'Ergebnis: {"a":1} Ende.' ) );
		$this->assertNull( Json::extract( 'kein JSON' ) );
	}
}
