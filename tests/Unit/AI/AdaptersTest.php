<?php
/**
 * Tests der drei KI-Adapter mit HTTP-Attrappe.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\AI;

use Lsec\Integrations\AI\AiClient;
use Lsec\Integrations\AI\AiException;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiService;
use Lsec\Integrations\AI\AnthropicClient;
use Lsec\Integrations\AI\OpenAiClient;
use Lsec\Integrations\AI\OpenRouterClient;
use Lsec\Integrations\AI\PriceTable;
use Lsec\Tests\Support\FakeTransport;
use Lsec\Tests\Support\MemoryAiCallLog;
use Lsec\Workflow\RetryableException;
use PHPUnit\Framework\TestCase;

/**
 * Anfrageaufbau, Antwortauswertung und Fehlerklassen je Anbieter (M7, M7a, M7b).
 */
final class AdaptersTest extends TestCase {

	/**
	 * Schema für die Tests.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'districts' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'districts' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Antwort je Anbieter mit gegebenem Text.
	 *
	 * @param string $provider Anbieter.
	 * @param string $text     Text.
	 * @return array{status: int, body: string, error: null}
	 */
	private static function reply( string $provider, string $text ): array {
		return match ( $provider ) {
			'openrouter' => FakeTransport::json(
				array(
					'model'   => 'x/model',
					'choices' => array(
						array(
							'finish_reason' => 'stop',
							'message'       => array(
								'content'     => $text,
								'annotations' => array(
									array(
										'type'         => 'url_citation',
										'url_citation' => array(
											'url'   => 'https://www.hannover.de/',
											'title' => 'Hannover',
										),
									),
								),
							),
						),
					),
					'usage'   => array(
						'prompt_tokens'     => 100,
						'completion_tokens' => 20,
						'cost'              => 0.0012,
					),
				)
			),
			'anthropic'  => FakeTransport::json(
				array(
					'model'       => 'claude-x',
					'stop_reason' => 'end_turn',
					'content'     => array(
						array(
							'type'    => 'web_search_tool_result',
							'content' => array(
								array(
									'type'  => 'web_search_result',
									'url'   => 'https://www.hannover.de/',
									'title' => 'Hannover',
								),
							),
						),
						array(
							'type'      => 'text',
							'text'      => $text,
							'citations' => array(
								array(
									'type'  => 'web_search_result_location',
									'url'   => 'https://www.hannover.de/',
									'title' => 'Hannover',
								),
							),
						),
					),
					'usage'       => array(
						'input_tokens'  => 100,
						'output_tokens' => 20,
					),
				)
			),
			default      => FakeTransport::json(
				array(
					'model'  => 'gpt-x',
					'status' => 'completed',
					'output' => array(
						array( 'type' => 'web_search_call' ),
						array(
							'type'    => 'message',
							'content' => array(
								array(
									'type'        => 'output_text',
									'text'        => $text,
									'annotations' => array(
										array(
											'type'  => 'url_citation',
											'url'   => 'https://www.hannover.de/',
											'title' => 'Hannover',
										),
									),
								),
							),
						),
					),
					'usage'  => array(
						'input_tokens'  => 100,
						'output_tokens' => 20,
					),
				)
			),
		};
	}

	/**
	 * Adapter je Anbieter.
	 *
	 * @param string        $provider Anbieter.
	 * @param FakeTransport $http     HTTP.
	 */
	private static function client( string $provider, FakeTransport $http ): AiClient {
		return match ( $provider ) {
			'openrouter' => new OpenRouterClient( $http, 'sk-or-v1-test', 'x/model', 'https://example.org' ),
			'anthropic'  => new AnthropicClient( $http, 'sk-ant-test', 'claude-x' ),
			default      => new OpenAiClient( $http, 'sk-test', 'gpt-x' ),
		};
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function providers(): array {
		return array(
			'OpenRouter' => array( 'openrouter' ),
			'Anthropic'  => array( 'anthropic' ),
			'OpenAI'     => array( 'openai' ),
		);
	}

	/**
	 * M7b: derselbe fachliche Aufruf liefert über jeden Adapter dasselbe Ergebnis.
	 *
	 * @dataProvider providers
	 */
	public function test_same_structured_result_through_every_adapter( string $provider ): void {
		$http    = new FakeTransport( array( self::reply( $provider, '{"districts":["Linden","List"]}' ) ) );
		$log     = new MemoryAiCallLog();
		$service = new AiService( self::client( $provider, $http ), $log );

		$response = $service->structured( new AiRequest( 'test', 'System', 'Frage', self::schema(), 'districts' ) );

		$this->assertSame( array( 'districts' => array( 'Linden', 'List' ) ), $response->data );
		$this->assertSame( 100, $response->input_tokens );
		$this->assertSame( 20, $response->output_tokens );
		$this->assertCount( 1, $log->entries );
		$this->assertSame( 'ok', $log->entries[0]['status'] );
	}

	/**
	 * @dataProvider providers
	 */
	public function test_request_body_contains_schema_and_secret_only_in_headers( string $provider ): void {
		$http = new FakeTransport( array( self::reply( $provider, '{"districts":[]}' ) ) );
		self::client( $provider, $http )->send( new AiRequest( 'test', 'System', 'Frage', self::schema(), 'districts' ) );

		$request = $http->requests[0];
		$body    = (string) json_encode( $request['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString( '"districts"', $body );
		$this->assertStringNotContainsString( 'test"', $body, 'Der API-Schlüssel gehört nicht in den Rumpf.' );

		match ( $provider ) {
			'openrouter' => $this->assertSame( 'json_schema', $request['body']['response_format']['type'] ),
			'anthropic'  => $this->assertSame( 'json_schema', $request['body']['output_config']['format']['type'] ),
			default      => $this->assertSame( 'json_schema', $request['body']['text']['format']['type'] ),
		};
	}

	/**
	 * @dataProvider providers
	 */
	public function test_web_search_returns_sources( string $provider ): void {
		$http     = new FakeTransport( array( self::reply( $provider, 'Linden ist ein Stadtteil von Hannover.' ) ) );
		$response = self::client( $provider, $http )->send( new AiRequest( 'test', 'System', 'Frage', null, 'r', true ) );

		$this->assertSame( array( array( 'url' => 'https://www.hannover.de/', 'title' => 'Hannover' ) ), $response->sources ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$body = $http->requests[0]['body'];
		match ( $provider ) {
			'openrouter' => $this->assertSame( 'web', $body['plugins'][0]['id'] ),
			'anthropic'  => $this->assertSame( 'web_search', $body['tools'][0]['name'] ),
			default      => $this->assertSame( 'web_search', $body['tools'][0]['type'] ),
		};
	}

	/**
	 * Spezifikation 8.1b: Die Trefferzahl der Websuche wird an jeden Anbieter weitergegeben.
	 *
	 * @dataProvider providers
	 */
	public function test_web_search_limit_reaches_the_provider( string $provider ): void {
		$http = new FakeTransport( array( self::reply( $provider, 'x' ), self::reply( $provider, 'x' ) ) );
		self::client( $provider, $http )->send( new AiRequest( 'test', 'System', 'Frage', null, 'r', true ) );
		self::client( $provider, $http )->send( new AiRequest( 'test', 'System', 'Frage', null, 'r', true, 3000, 120, 8 ) );

		$first  = $http->requests[0]['body'];
		$second = $http->requests[1]['body'];
		match ( $provider ) {
			'openrouter' => $this->assertSame( array( 3, 8 ), array( $first['plugins'][0]['max_results'], $second['plugins'][0]['max_results'] ) ),
			'anthropic'  => $this->assertSame( array( 3, 8 ), array( $first['tools'][0]['max_uses'], $second['tools'][0]['max_uses'] ) ),
			default      => $this->assertSame( array( 'low', 'high' ), array( $first['tools'][0]['search_context_size'], $second['tools'][0]['search_context_size'] ) ),
		};
	}

	/**
	 * Nachtrag zu Anforderung 14: Die Recherche läuft mit dem Recherchemodell, die Strukturierung mit dem Hauptmodell.
	 */
	public function test_research_model_is_used_only_for_the_search_step(): void {
		$main     = new FakeTransport( array( self::reply( 'openrouter', '{"districts":["Linden"]}' ) ) );
		$research = new FakeTransport( array( self::reply( 'openrouter', 'Stadtteil Linden.' ) ) );
		$service  = new AiService(
			new OpenRouterClient( $main, 'sk-or-v1-test', 'main/model', '' ),
			new MemoryAiCallLog(),
			new PriceTable(),
			null,
			new OpenRouterClient( $research, 'sk-or-v1-test', 'cheap/model', '' ),
			2
		);
		$service->research( 'districts', 'System', 'Welche Stadtteile?', self::schema(), 'districts' );

		$this->assertCount( 1, $research->requests );
		$this->assertSame( 'cheap/model', $research->requests[0]['body']['model'] );
		$this->assertSame( 2, $research->requests[0]['body']['plugins'][0]['max_results'] );
		$this->assertCount( 1, $main->requests );
		$this->assertSame( 'main/model', $main->requests[0]['body']['model'] );
		$this->assertArrayNotHasKey( 'plugins', $main->requests[0]['body'] );
	}

	/**
	 * M7a: Recherche mit Websuche, danach Strukturierung ohne Werkzeuge; Quellen werden weitergereicht.
	 *
	 * @dataProvider providers
	 */
	public function test_two_step_research( string $provider ): void {
		$http    = new FakeTransport(
			array(
				self::reply( $provider, 'Stadtteile: Linden, List.' ),
				self::reply( $provider, '{"districts":["Linden","List"]}' ),
			)
		);
		$service = new AiService( self::client( $provider, $http ), new MemoryAiCallLog() );
		$result  = $service->research( 'districts', 'System', 'Welche Stadtteile?', self::schema(), 'districts' );

		$this->assertSame( array( 'Linden', 'List' ), $result['result']->data['districts'] );
		$this->assertSame( 'https://www.hannover.de/', $result['research']->sources[0]['url'] );

		$second = (string) json_encode( $http->requests[1]['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString( 'hannover.de', $second, 'Quellen gehen in den zweiten Aufruf.' );
		$this->assertArrayNotHasKey( 'plugins', $http->requests[1]['body'] );
		$this->assertArrayNotHasKey( 'tools', $http->requests[1]['body'] );
	}

	/**
	 * M7: ungültige Antwort wird einmal korrigiert.
	 */
	public function test_invalid_output_is_repaired_once(): void {
		$http    = new FakeTransport(
			array(
				self::reply( 'openrouter', 'Hier ist die Liste: Linden, List' ),
				self::reply( 'openrouter', '```json' . "\n" . '{"districts":["Linden"]}' . "\n```" ),
			)
		);
		$log     = new MemoryAiCallLog();
		$service = new AiService( self::client( 'openrouter', $http ), $log );
		$result  = $service->structured( new AiRequest( 'test', 'System', 'Frage', self::schema() ) );

		$this->assertSame( array( 'Linden' ), $result->data['districts'] );
		$this->assertCount( 2, $http->requests );
		$this->assertStringContainsString( 'entsprach nicht', $http->requests[1]['body']['messages'][1]['content'] );
		$this->assertSame( array( 'ok', 'invalid_output', 'ok' ), array_column( $log->entries, 'status' ) );
	}

	/**
	 * M7: bleibt die Antwort ungültig, wird sauber abgelehnt.
	 */
	public function test_invalid_output_twice_is_rejected(): void {
		$http    = new FakeTransport(
			array(
				self::reply( 'openrouter', '{"districts":"Linden"}' ),
				self::reply( 'openrouter', '{"falsch":true}' ),
			)
		);
		$service = new AiService( self::client( 'openrouter', $http ), new MemoryAiCallLog() );

		try {
			$service->structured( new AiRequest( 'test', 'System', 'Frage', self::schema() ) );
			$this->fail( 'Ausnahme erwartet' );
		} catch ( AiException $e ) {
			$this->assertSame( 'invalid_output', $e->error_code );
		}
		$this->assertCount( 2, $http->requests );
	}

	/**
	 * @dataProvider providers
	 */
	public function test_rate_limit_and_server_errors_are_retryable( string $provider ): void {
		foreach ( array( 0, 429, 500, 529 ) as $status ) {
			$http = new FakeTransport(
				array(
					array(
						'status' => $status,
						'body'   => '{"error":{"message":"busy"}}',
					),
				)
			);
			try {
				self::client( $provider, $http )->send( new AiRequest( 'test', 'S', 'U' ) );
				$this->fail( 'Ausnahme erwartet' );
			} catch ( RetryableException $e ) {
				$this->assertSame( 0 === $status ? 'network' : 'http_' . $status, $e->error_code );
			}
		}
	}

	/**
	 * @dataProvider providers
	 */
	public function test_auth_errors_are_final( string $provider ): void {
		$http = new FakeTransport(
			array(
				array(
					'status' => 401,
					'body'   => '{"error":{"message":"invalid key"}}',
				),
			)
		);
		$this->expectException( AiException::class );
		self::client( $provider, $http )->send( new AiRequest( 'test', 'S', 'U' ) );
	}

	public function test_cost_from_price_table_when_provider_gives_none(): void {
		$http     = new FakeTransport( array( self::reply( 'anthropic', '{"districts":[]}' ) ) );
		$log      = new MemoryAiCallLog();
		$prices   = new PriceTable( array( 'anthropic' => array( 'claude-x' => array( 'in' => 3, 'out' => 15 ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$service  = new AiService( self::client( 'anthropic', $http ), $log, $prices );
		$response = $service->structured( new AiRequest( 'test', 'S', 'U', self::schema() ) );

		$this->assertEqualsWithDelta( 0.0006, $response->cost, 0.0000001 );
		$this->assertEqualsWithDelta( 0.0006, $log->entries[0]['cost'], 0.0000001 );
	}

	public function test_openrouter_reports_its_own_cost_and_data_policy(): void {
		$http     = new FakeTransport( array( self::reply( 'openrouter', '{"districts":[]}' ) ) );
		$response = self::client( 'openrouter', $http )->send( new AiRequest( 'test', 'S', 'U', self::schema() ) );
		$this->assertSame( 0.0012, $response->cost );
		$this->assertSame( 'deny', $http->requests[0]['body']['provider']['data_collection'] );
		$this->assertSame( 'Bearer sk-or-v1-test', $http->requests[0]['headers']['Authorization'] );
	}

	public function test_schema_and_web_search_are_not_combined(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AiRequest( 'test', 'S', 'U', self::schema(), 'r', true );
	}

	public function test_openrouter_web_engine_and_citations(): void {
		$http   = new FakeTransport( array() );
		$client = new OpenRouterClient( $http, 'sk-or-v1-test', 'x/model', '' );
		$plain  = $client->build_body( new AiRequest( 'search', 'S', 'Q', null, 'result', true, 3000, 120, 3 ) );
		$exa    = $client->build_body( new AiRequest( 'search', 'S', 'Q', null, 'result', true, 3000, 120, 3, AiService::engine( 'exa' ) ) );
		$this->assertArrayNotHasKey( 'engine', $plain['plugins'][0] );
		$this->assertSame( 'exa', $exa['plugins'][0]['engine'] );
		$this->assertSame( '', AiService::engine( 'standard' ) );
		$this->assertSame( '', AiService::engine( 'unbekannt' ) );

		$http     = new FakeTransport(
			array(
				FakeTransport::json(
					array(
						'model'     => 'x/model',
						'choices'   => array(
							array(
								'finish_reason' => 'stop',
								'message'       => array( 'content' => 'Text' ),
							),
						),
						'citations' => array( 'https://www.uetze.de/', 'kein-link' ),
					)
				),
			)
		);
		$response = ( new OpenRouterClient( $http, 'sk-or-v1-test', 'x/model', '' ) )->send( new AiRequest( 'search', 'S', 'Q', null, 'result', true ) );
		$this->assertSame( array( 'https://www.uetze.de/' ), array_column( $response->sources, 'url' ) );
	}

	public function test_search_leaves_room_for_reasoning(): void {
		$http    = new FakeTransport( array( self::reply( 'openrouter', 'Recherche' ) ) );
		$service = new AiService( new OpenRouterClient( $http, 'sk-or-v1-test', 'x/model', '' ), new MemoryAiCallLog() );
		$service->search( 'topic_research_search', 'S', 'Q', null, 3, 'exa' );
		$this->assertSame( AiService::SEARCH_TOKENS, $http->requests[0]['body']['max_tokens'] );
		$this->assertSame( 'exa', $http->requests[0]['body']['plugins'][0]['engine'] );
	}
}
