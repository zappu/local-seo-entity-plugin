<?php
/**
 * Tests für die Search Console (M9).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Integrations;

use Lsec\Integrations\SearchConsole\SearchConsoleClient;
use Lsec\Integrations\SearchConsole\SearchConsoleException;
use Lsec\Integrations\SearchConsole\ServiceAccount;
use Lsec\Tests\Support\FakeTransport;
use Lsec\Workflow\RetryableException;
use PHPUnit\Framework\TestCase;

/**
 * Anmeldung per Dienstkonto, Abfrage und Zusammenfassung ohne echte Aufrufe.
 */
final class SearchConsoleTest extends TestCase {

	private const EMAIL = 'lsec-test@example-project.iam.gserviceaccount.com';

	/**
	 * Schlüsselpaar für den Test.
	 *
	 * @var array{private: string, public: string}
	 */
	private static array $keys;

	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $private );
		self::$keys = array(
			'private' => (string) $private,
			'public'  => (string) openssl_pkey_get_details( $key )['key'],
		);
	}

	/**
	 * Dienstkonto mit fester Uhr.
	 *
	 * @param FakeTransport $http HTTP.
	 * @param int           $now  Zeit.
	 */
	private function account( FakeTransport $http, int &$now ): ServiceAccount {
		return new ServiceAccount(
			self::EMAIL,
			self::$keys['private'],
			'https://oauth2.googleapis.com/token',
			$http,
			static function () use ( &$now ): int {
				return $now;
			}
		);
	}

	/**
	 * Antwort mit Zugriffstoken.
	 *
	 * @return array{status: int, body: string, error: null}
	 */
	private static function token(): array {
		return FakeTransport::json(
			array(
				'access_token' => 'ya29.test-token-abcdefghijklmnopqrstuvwxyz',
				'expires_in'   => 3599,
				'token_type'   => 'Bearer',
			)
		);
	}

	public function test_jwt_is_signed_with_rs256_and_has_readonly_scope(): void {
		$now = 1760800000;
		$jwt = $this->account( new FakeTransport( array() ), $now )->jwt( $now );

		$parts = explode( '.', $jwt );
		$this->assertCount( 3, $parts );
		$decode = static fn( string $s ): string => (string) base64_decode( strtr( $s, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$header = json_decode( $decode( $parts[0] ), true );
		$claims = json_decode( $decode( $parts[1] ), true );

		$this->assertSame( 'RS256', $header['alg'] );
		$this->assertSame( self::EMAIL, $claims['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/webmasters.readonly', $claims['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $claims['aud'] );
		$this->assertSame( $now + 3600, $claims['exp'] );
		$this->assertSame( 1, openssl_verify( $parts[0] . '.' . $parts[1], $decode( $parts[2] ), self::$keys['public'], OPENSSL_ALGO_SHA256 ) );
	}

	public function test_token_is_requested_once_and_reused(): void {
		$now  = 1760800000;
		$http = new FakeTransport( array( self::token() ) );
		$acc  = $this->account( $http, $now );

		$this->assertSame( 'ya29.test-token-abcdefghijklmnopqrstuvwxyz', $acc->access_token() );
		$now += 1800;
		$acc->access_token();
		$this->assertCount( 1, $http->requests );
		parse_str( (string) $http->requests[0]['raw'], $sent );
		$this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $sent['grant_type'] );
	}

	public function test_rejected_login_is_final(): void {
		$now  = 1760800000;
		$http = new FakeTransport(
			array(
				FakeTransport::json(
					array(
						'error'             => 'invalid_grant',
						'error_description' => 'Invalid JWT Signature.',
					),
					400
				),
			)
		);
		try {
			$this->account( $http, $now )->access_token();
			$this->fail( 'Fehler erwartet.' );
		} catch ( SearchConsoleException $e ) {
			$this->assertSame( 'auth_failed', $e->error_code );
			$this->assertStringContainsString( 'Invalid JWT Signature', $e->getMessage() );
		}
	}

	public function test_sites_and_search_analytics(): void {
		$now    = 1760800000;
		$http   = new FakeTransport(
			array(
				self::token(),
				FakeTransport::json(
					array(
						'siteEntry' => array(
							array(
								'siteUrl'         => 'sc-domain:flaechenreiniger-wassertechnik.de',
								'permissionLevel' => 'siteRestrictedUser',
							),
						),
					)
				),
				FakeTransport::json(
					array(
						'rows' => array(
							array(
								'keys'        => array( 'flächenreinigung hannover', 'https://flaechenreiniger-wassertechnik.de/' ),
								'clicks'      => 3,
								'impressions' => 40,
								'ctr'         => 0.075,
								'position'    => 8.0,
							),
							array(
								'keys'        => array( 'Flächenreinigung Hannover', 'https://flaechenreiniger-wassertechnik.de/leistungen/' ),
								'clicks'      => 1,
								'impressions' => 60,
								'ctr'         => 0.0167,
								'position'    => 12.0,
							),
							array(
								'keys'        => array( 'terrassenreinigung hemmingen', 'https://flaechenreiniger-wassertechnik.de/terrasse/' ),
								'clicks'      => 0,
								'impressions' => 15,
								'ctr'         => 0,
								'position'    => 21.5,
							),
						),
					)
				),
			)
		);
		$client = new SearchConsoleClient( $this->account( $http, $now ), $http );

		$this->assertSame( 'sc-domain:flaechenreiniger-wassertechnik.de', $client->sites()[0]['url'] );
		$rows = $client->search_analytics( 'sc-domain:flaechenreiniger-wassertechnik.de', '2026-09-18', '2026-10-15', 'deu' );

		$this->assertCount( 3, $rows );
		$request = $http->requests[2];
		$this->assertSame( 'https://searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Aflaechenreiniger-wassertechnik.de/searchAnalytics/query', $request['url'] );
		$this->assertSame( 'Bearer ya29.test-token-abcdefghijklmnopqrstuvwxyz', $request['headers']['Authorization'] );
		$this->assertSame( array( 'query', 'page' ), $request['body']['dimensions'] );
		$this->assertSame( 'deu', $request['body']['dimensionFilterGroups'][0]['filters'][0]['expression'] );

		$agg = SearchConsoleClient::aggregate( $rows );
		$this->assertSame( array( 'flächenreinigung hannover', 'terrassenreinigung hemmingen' ), array_keys( $agg ) );
		$first = $agg['flächenreinigung hannover'];
		$this->assertSame( 4, $first['clicks'] );
		$this->assertSame( 100, $first['impressions'] );
		$this->assertSame( 0.04, $first['ctr'] );
		$this->assertSame( 10.4, $first['position'] );
		$this->assertSame( 'https://flaechenreiniger-wassertechnik.de/', $first['page'] );
		$this->assertSame( 2, $first['pages'] );
	}

	public function test_missing_permission_and_rate_limit(): void {
		$now    = 1760800000;
		$http   = new FakeTransport(
			array(
				self::token(),
				FakeTransport::json(
					array(
						'error' => array(
							'code'    => 403,
							'message' => "User does not have sufficient permission for site 'sc-domain:example.de'.",
						),
					),
					403
				),
				FakeTransport::json( array( 'error' => array( 'message' => 'Quota exceeded' ) ), 429 ),
			)
		);
		$client = new SearchConsoleClient( $this->account( $http, $now ), $http );
		try {
			$client->sites();
			$this->fail( '403 erwartet.' );
		} catch ( SearchConsoleException $e ) {
			$this->assertSame( 'forbidden', $e->error_code );
			$this->assertStringContainsString( self::EMAIL, $e->getMessage() );
		}
		$this->expectException( RetryableException::class );
		$client->sites();
	}

	public function test_period_ends_three_days_ago(): void {
		$period = SearchConsoleClient::period( 28, (int) strtotime( '2026-10-18 12:00:00 UTC' ) );
		$this->assertSame( '2026-10-15', $period['end'] );
		$this->assertSame( '2026-09-18', $period['start'] );
	}

	public function test_key_file_validation(): void {
		$path = sys_get_temp_dir() . '/lsec-gsc-' . uniqid() . '.json';
		file_put_contents( $path, '{"type":"authorized_user"}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		try {
			ServiceAccount::from_file( $path, new FakeTransport( array() ) );
			$this->fail( 'Fehler erwartet.' );
		} catch ( SearchConsoleException $e ) {
			$this->assertSame( 'invalid_key', $e->error_code );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}
