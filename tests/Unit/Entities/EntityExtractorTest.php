<?php
/**
 * Tests für die Entity-Erkennung (M11).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Entities;

use Lsec\Content\ElementorReader;
use Lsec\Entities\EntityExtractor;
use Lsec\Integrations\AI\AiService;
use Lsec\Integrations\AI\OpenRouterClient;
use Lsec\Tests\Support\FakeTransport;
use Lsec\Tests\Support\MemoryAiCallLog;
use PHPUnit\Framework\TestCase;

/**
 * Vorschläge werden gegen den Text geprüft: erfundene Fundstellen fallen heraus.
 */
final class EntityExtractorTest extends TestCase {

	/**
	 * Text der Elementor-Testseite.
	 */
	private function text(): string {
		$data = ElementorReader::read( (string) file_get_contents( __DIR__ . '/../../fixtures/elementor/leistungsseite.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) $data['text'];
	}

	/**
	 * Antwort der KI.
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(): array {
		return array(
			'entities'    => array(
				array(
					'type'        => 'service',
					'name'        => 'Thermische Reinigung',
					'aliases'     => array( 'thermische Flächenreinigung', '' ),
					'description' => 'Reinigung mit Heißwasser',
					'confidence'  => 0.9,
					'evidence'    => array(
						array(
							'excerpt'  => 'Schonend mit Heißwasser & ohne Chemie.',
							'location' => 'body',
						),
						array(
							'excerpt'  => 'Wir sind seit 1985 Marktführer in Niedersachsen.',
							'location' => 'body',
						),
					),
				),
				array(
					'type'        => 'place',
					'name'        => 'Burgdorf',
					'aliases'     => array(),
					'description' => '',
					'confidence'  => 0.8,
					'evidence'    => array(
						array(
							'excerpt'  => 'Flächenreinigung  in Burgdorf',
							'location' => 'heading',
						),
					),
				),
				array(
					'type'        => 'place',
					'name'        => 'Region Hannover',
					'aliases'     => array(),
					'description' => '',
					'confidence'  => 0.85,
					'evidence'    => array(
						array(
							'excerpt'  => 'erfundenes Zitat',
							'location' => 'body',
						),
					),
				),
				array(
					'type'        => 'organization',
					'name'        => 'Kärcher',
					'aliases'     => array(),
					'description' => '',
					'confidence'  => 0.4,
					'evidence'    => array(),
				),
				array(
					'type'        => 'gefühl',
					'name'        => 'Zufriedenheit',
					'aliases'     => array(),
					'description' => '',
					'confidence'  => 0.4,
					'evidence'    => array(),
				),
				array(
					'type'        => 'place',
					'name'        => 'burgdorf',
					'aliases'     => array(),
					'description' => '',
					'confidence'  => 0.5,
					'evidence'    => array(),
				),
			),
			'ambiguities' => array(
				array(
					'name'   => 'Burgdorf',
					'reason' => 'Burgdorf gibt es mehrfach in Niedersachsen.',
				),
			),
		);
	}

	public function test_verification_against_text(): void {
		$result = EntityExtractor::verify( self::answer(), $this->text() );
		$names  = array_column( $result['entities'], 'name' );

		$this->assertSame( array( 'Thermische Reinigung', 'Burgdorf', 'Region Hannover' ), $names );

		// Erfundenes Zitat entfällt, echtes bleibt.
		$this->assertCount( 1, $result['entities'][0]['evidence'] );
		$this->assertSame( array( 'thermische Flächenreinigung' ), $result['entities'][0]['aliases'] );
		$this->assertSame( 'heading', $result['entities'][1]['evidence'][0]['location'] );

		// Ohne gültiges Zitat zählt der Name selbst, wenn er im Text steht.
		$this->assertStringContainsString( 'Region Hannover', $result['entities'][2]['evidence'][0]['excerpt'] );

		$rejected = array_column( $result['rejected'], 'reason', 'name' );
		$this->assertSame( 'keine Fundstelle im Text', $rejected['Kärcher'] );
		$this->assertStringContainsString( 'unbekannter Typ', $rejected['Zufriedenheit'] );
		$this->assertSame( 'Burgdorf', $result['ambiguities'][0]['name'] );
	}

	public function test_extract_calls_ai_with_strict_schema(): void {
		// Schema-gültige Antwort: der Typ „gefühl“ ist bei strict-Modus nicht möglich.
		$answer             = self::answer();
		$answer['entities'] = array_values( array_filter( $answer['entities'], static fn( array $e ): bool => 'gefühl' !== $e['type'] ) );
		$http               = new FakeTransport(
			array(
				FakeTransport::json(
					array(
						'model'   => 'x/model',
						'choices' => array(
							array(
								'finish_reason' => 'stop',
								'message'       => array( 'content' => (string) json_encode( $answer ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
							),
						),
						'usage'   => array(
							'prompt_tokens'     => 900,
							'completion_tokens' => 300,
							'cost'              => 0.004,
						),
					)
				),
			)
		);
		$log                = new MemoryAiCallLog();
		$ai                 = new AiService( new OpenRouterClient( $http, 'sk-or-v1-test', 'x/model', 'https://example.org' ), $log );
		$result             = ( new EntityExtractor( $ai ) )->extract( 'Flächenreinigung in Burgdorf', $this->text(), array( 'Thermische Reinigung' ) );

		$this->assertCount( 3, $result['entities'] );
		$this->assertSame( 0.004, $result['cost'] );
		$body = $http->requests[0]['body'];
		$this->assertSame( 'json_schema', $body['response_format']['type'] );
		$this->assertTrue( $body['response_format']['json_schema']['strict'] );
		$this->assertStringContainsString( 'Bekannte Entitäten', (string) $body['messages'][1]['content'] );
		$this->assertStringContainsString( 'Häufige Fragen', (string) $body['messages'][1]['content'] );
	}

	public function test_area_phrase_becomes_the_place(): void {
		$data   = array(
			'entities' => array(
				array(
					'type'        => 'service_area',
					'name'        => 'Raum Gehrden',
					'aliases'     => array(),
					'description' => '',
					'confidence'  => 0.8,
					'evidence'    => array(
						array(
							'excerpt'  => 'im Raum Gehrden',
							'location' => 'body',
						),
					),
				),
			),
		);
		$result = EntityExtractor::verify( $data, 'Wir reinigen Pflaster im Raum Gehrden und in Seelze.' );
		$this->assertSame( 'place', $result['entities'][0]['type'] );
		$this->assertSame( 'Gehrden', $result['entities'][0]['name'] );
		$this->assertSame( array( 'Raum Gehrden' ), $result['entities'][0]['aliases'] );
	}
}
