<?php
/**
 * Tests für den Textfocus-Adapter (M16).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Integrations;

use Lsec\Integrations\Textfocus\TextfocusClient;
use Lsec\Integrations\Textfocus\TextfocusException;
use Lsec\Integrations\Textfocus\TextfocusService;
use Lsec\Tests\Support\FakeTransport;
use Lsec\Tests\Support\MemoryProviderCallLog;
use Lsec\Workflow\RetryableException;
use PHPUnit\Framework\TestCase;

/**
 * Budget, Abstand, Bündelung und Zwischenspeicher ohne echte Aufrufe.
 */
final class TextfocusServiceTest extends TestCase {

	private const KEY = 'tf-secret-123';

	/**
	 * Antwort im Format der Doku.
	 *
	 * @param mixed $result Ergebnis.
	 * @param int   $used   Verbrauchte Credits.
	 * @return array{status: int, body: string, error: null}
	 */
	private static function ok( mixed $result, int $used = 1 ): array {
		return FakeTransport::json(
			array(
				'version'    => '1.0',
				'params'     => array( 'key' => self::KEY ),
				'result'     => $result,
				'response'   => 200,
				'message'    => 'ok',
				'timing'     => 0.4,
				'creditUsed' => $used,
				'method'     => 'tf_keyword',
				'credits'    => 970,
			)
		);
	}

	/**
	 * Dienst mit Attrappen.
	 *
	 * @param FakeTransport         $http   HTTP.
	 * @param MemoryProviderCallLog $log    Protokoll.
	 * @param int                   $budget Budget.
	 * @param int                   $batch  Bündelgröße.
	 * @param array<int>            $slept  Wartezeiten.
	 */
	private function service( FakeTransport $http, MemoryProviderCallLog $log, int $budget = 25, int $batch = 10, array &$slept = array() ): TextfocusService {
		return new TextfocusService(
			new TextfocusClient( $http, self::KEY ),
			$log,
			$budget,
			60,
			$batch,
			static function ( int $s ) use ( &$slept, $log ): void {
				$slept[]   = $s;
				$log->now += $s;
			},
			static fn(): int => $log->now
		);
	}

	public function test_keyword_request_format_and_parsing(): void {
		$http = new FakeTransport(
			array(
				self::ok(
					array(
						'flächenreinigung'   => array(
							'id'          => 1,
							'lang'        => 'de-DE',
							'cost'        => 4.36,
							'concurrency' => 0.12,
							'volume'      => 110,
							'difficulty'  => 35,
						),
						'terrassenreinigung' => array(
							'volume' => 0,
						),
					)
				),
			)
		);
		$log  = new MemoryProviderCallLog();
		$out  = $this->service( $http, $log )->keywords( array( 'Flächenreinigung', 'terrassenreinigung' ), 'de-DE' );

		$this->assertSame( 'POST', $http->requests[0]['method'] );
		$this->assertSame( 'https://www.textfocus.net/apis/tf_keyword/', $http->requests[0]['url'] );
		parse_str( (string) $http->requests[0]['raw'], $sent );
		$this->assertSame( self::KEY, $sent['key'] );
		$this->assertSame( 'de-DE', $sent['lang'] );
		$this->assertSame( array( 'Flächenreinigung', 'terrassenreinigung' ), $sent['keyword'] );

		$this->assertSame( 110, $out['keywords']['flächenreinigung']['volume'] );
		$this->assertSame( 4.36, $out['keywords']['flächenreinigung']['cpc'] );
		$this->assertSame( 35.0, $out['keywords']['flächenreinigung']['difficulty'] );
		$this->assertSame( 0.12, $out['keywords']['flächenreinigung']['competition'] );
		$this->assertSame( 0, $out['keywords']['terrassenreinigung']['volume'] );
		$this->assertNull( $out['keywords']['terrassenreinigung']['cpc'] );

		$call = $log->calls[1];
		$this->assertSame( 'ok', $call['status'] );
		$this->assertSame( 1.0, $call['credits_used'] );
		$this->assertSame( 970.0, $call['credits_remaining'] );
		$this->assertStringNotContainsString( self::KEY, (string) $call['response_body'] );
	}

	public function test_batching_and_interval(): void {
		$http     = new FakeTransport( array( self::ok( array() ), self::ok( array() ), self::ok( array() ) ) );
		$log      = new MemoryProviderCallLog();
		$slept    = array();
		$keywords = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$keywords[] = 'keyword ' . $i;
		}
		$out = $this->service( $http, $log, 25, 5, $slept )->keywords( $keywords, 'de-DE' );

		$this->assertCount( 3, $http->requests );
		$this->assertSame( 3, $out['calls'] );
		$this->assertSame( array( 60, 60 ), $slept );
	}

	public function test_cache_hit_needs_no_request_and_no_credit(): void {
		$http = new FakeTransport( array( self::ok( array( 'a' => array( 'volume' => 5 ) ) ) ) );
		$log  = new MemoryProviderCallLog();
		$tf   = $this->service( $http, $log );

		$tf->keywords( array( 'a' ), 'de-DE' );
		$log->now += 3600;
		$out       = $tf->keywords( array( 'A ' ), 'DE-de' );

		$this->assertCount( 1, $http->requests );
		$this->assertSame( 1, $out['cached'] );
		$this->assertSame( 5, $out['keywords']['a']['volume'] );
		$this->assertSame( array( 'ok', 'cached' ), $log->statuses() );
		$this->assertSame( 24.0, $tf->budget_left() );
	}

	public function test_cache_expires_after_30_days(): void {
		$http = new FakeTransport( array( self::ok( array() ), self::ok( array() ) ) );
		$log  = new MemoryProviderCallLog();
		$tf   = $this->service( $http, $log );

		$tf->keywords( array( 'a' ), 'de-DE' );
		$log->now += 31 * 86400;
		$tf->keywords( array( 'a' ), 'de-DE' );
		$this->assertCount( 2, $http->requests );
	}

	public function test_force_bypasses_cache_and_sets_force_refetch(): void {
		$http = new FakeTransport( array( self::ok( array() ), self::ok( array() ) ) );
		$log  = new MemoryProviderCallLog();
		$tf   = $this->service( $http, $log );

		$tf->keywords( array( 'a' ), 'de-DE' );
		$tf->keywords( array( 'a' ), 'de-DE', true );
		$this->assertCount( 2, $http->requests );
		parse_str( (string) $http->requests[1]['raw'], $sent );
		$this->assertSame( '1', $sent['force_refetch'] );
	}

	public function test_budget_exceeded_stops_before_request(): void {
		$http = new FakeTransport( array( self::ok( array() ), self::ok( array() ) ) );
		$log  = new MemoryProviderCallLog();
		$tf   = $this->service( $http, $log, 1 );

		$tf->keywords( array( 'a' ), 'de-DE' );
		try {
			$tf->keywords( array( 'b' ), 'de-DE' );
			$this->fail( 'Budget hätte greifen müssen.' );
		} catch ( TextfocusException $e ) {
			$this->assertSame( 'budget_exceeded', $e->error_code );
		}
		$this->assertCount( 1, $http->requests );
		$this->assertSame( array( 'ok', 'budget_exceeded' ), $log->statuses() );
	}

	public function test_interval_without_waiting_is_retryable(): void {
		$http = new FakeTransport( array( self::ok( array() ) ) );
		$log  = new MemoryProviderCallLog();
		$tf   = $this->service( $http, $log );

		$tf->keywords( array( 'a' ), 'de-DE' );
		$log->now += 20;
		try {
			$tf->query(
				'tf_related',
				array(
					'lang'    => 'de-DE',
					'keyword' => 'b',
				),
				false,
				false
			);
			$this->fail( 'Abstand hätte greifen müssen.' );
		} catch ( RetryableException $e ) {
			$this->assertSame( 'rate_limited', $e->error_code );
			$this->assertStringContainsString( '40 Sekunden', $e->getMessage() );
		}
	}

	public function test_unauthorized_and_rate_limit_errors(): void {
		$http = new FakeTransport(
			array(
				FakeTransport::json(
					array(
						'response' => 401,
						'message'  => 'invalid key ' . self::KEY,
					),
					401
				),
				FakeTransport::json(
					array(
						'response' => 429,
						'message'  => 'Too many requests',
					),
					200
				),
			)
		);
		$log  = new MemoryProviderCallLog();
		$tf   = $this->service( $http, $log );

		try {
			$tf->account();
			$this->fail( '401 erwartet.' );
		} catch ( TextfocusException $e ) {
			$this->assertSame( 'unauthorized', $e->error_code );
		}
		try {
			$tf->keywords( array( 'a' ), 'de-DE' );
			$this->fail( '429 erwartet.' );
		} catch ( RetryableException $e ) {
			$this->assertSame( 'rate_limited', $e->error_code );
		}
		$this->assertSame( array( 'error', 'rate_limited' ), $log->statuses() );
		$this->assertSame( 25.0, $tf->budget_left() );
	}

	public function test_real_invalid_key_response(): void {
		// Echte Antwort vom 18.10.2026 auf einen falschen Schlüssel: HTTP 200, response 404.
		$http = new FakeTransport(
			array(
				array(
					'status' => 200,
					'body'   => '{"version":"1.0.4","params":{"key":"' . self::KEY . '","lang":"de","keyword":["a","b"]},"result":[],"response":404,"message":"api key invalid","timing":0,"creditUsed":0,"method":"tf_keyword"}',
				),
			)
		);
		try {
			$this->service( $http, new MemoryProviderCallLog() )->keywords( array( 'a', 'b' ), 'de-DE' );
			$this->fail( 'Fehler erwartet.' );
		} catch ( TextfocusException $e ) {
			$this->assertSame( 'unauthorized', $e->error_code );
			$this->assertStringNotContainsString( self::KEY, $e->getMessage() );
		}
	}

	public function test_account_is_free_and_not_stored(): void {
		$http = new FakeTransport(
			array(
				self::ok(
					array(
						'account' => array(
							'name'  => 'Thomas',
							'email' => 'x@example.org',
						),
						'pack'    => array(
							'name'         => 'Classic',
							'apiAccess'    => true,
							'rateMax'      => 30,
							'nbCompeteMax' => 10,
						),
						'credits' => array(
							'remaining'      => 812,
							'consumed30days' => 18,
						),
					),
					0
				),
			)
		);
		$log  = new MemoryProviderCallLog();
		$info = $this->service( $http, $log, 0 )->account();

		$this->assertTrue( $info['api_access'] );
		$this->assertSame( 'Classic', $info['pack'] );
		$this->assertSame( 812.0, $info['credits_remaining'] );
		$this->assertNull( $log->calls[1]['response_body'] );
		$this->assertSame( 0.0, $log->calls[1]['credits_used'] );
	}

	public function test_request_hash_ignores_order_and_case(): void {
		$this->assertSame(
			TextfocusService::request_hash(
				'tf_keyword',
				array(
					'lang'    => 'de-DE',
					'keyword' => array( 'B', 'a' ),
				)
			),
			TextfocusService::request_hash(
				'tf_keyword',
				array(
					'keyword' => array( 'a', 'b' ),
					'lang'    => 'DE-de',
				)
			)
		);
		$this->assertNotSame(
			TextfocusService::request_hash( 'tf_keyword', array( 'keyword' => 'a' ) ),
			TextfocusService::request_hash( 'tf_related', array( 'keyword' => 'a' ) )
		);
	}

	public function test_locale_from_code(): void {
		$this->assertSame( 'de_AT', TextfocusService::locale_from_code( 'de-AT' ) );
		$this->assertSame( 'de_DE', TextfocusService::locale_from_code( 'de' ) );
	}

	public function test_langs_structure_from_real_response(): void {
		// Aufbau der Antwort vom 18.10.2026 (Ausschnitt).
		$langs = TextfocusService::parse_langs(
			array(
				array(
					'id'               => 'ae',
					'language_code'    => 'ar',
					'country_iso_code' => 'AE',
					'language_name'    => 'Arabic',
					'location_name'    => 'United Arab Emirates',
				),
				array(
					'id'               => 'de',
					'language_code'    => 'de',
					'country_iso_code' => 'DE',
					'language_name'    => 'German',
					'location_name'    => 'Germany',
				),
			)
		);
		$this->assertSame(
			array(
				'id'     => 'de',
				'label'  => 'German – Germany',
				'locale' => 'de_DE',
			),
			$langs[1]
		);
		$this->assertSame( 'ar_AE', $langs[0]['locale'] );
	}
}
