<?php
/**
 * Anmeldung mit einem Google-Dienstkonto.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\SearchConsole;

use Lsec\Infrastructure\Http\HttpTransport;
use Lsec\Integrations\AI\Json;
use Lsec\Workflow\RetryableException;

/**
 * OAuth 2.0 für Dienstkonten ohne Bibliothek: signiertes JWT (RS256) gegen ein Zugriffstoken tauschen.
 * Der private Schlüssel bleibt in der Datei außerhalb des Webroots und wird nie protokolliert.
 */
final class ServiceAccount {

	public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	/**
	 * Zugriffstoken und Ablauf.
	 *
	 * @var array{token: string, expires: int}|null
	 */
	private ?array $token = null;

	/**
	 * Konstruktor.
	 *
	 * @param string        $client_email E-Mail-Adresse des Dienstkontos.
	 * @param string        $private_key  Privater Schlüssel (PEM).
	 * @param string        $token_uri    Token-Adresse.
	 * @param HttpTransport $http         HTTP.
	 * @param \Closure|null $clock        Uhr (Unix-Zeit), in Tests ersetzbar.
	 */
	public function __construct(
		public readonly string $client_email,
		#[\SensitiveParameter]
		private readonly string $private_key,
		private readonly string $token_uri,
		private readonly HttpTransport $http,
		private readonly ?\Closure $clock = null
	) {}

	/**
	 * Liest die JSON-Schlüsseldatei.
	 *
	 * @param string        $path Pfad.
	 * @param HttpTransport $http HTTP.
	 * @throws SearchConsoleException Wenn die Datei fehlt oder kein Dienstkontoschlüssel ist.
	 */
	public static function from_file( string $path, HttpTransport $http ): self {
		if ( '' === $path || ! is_readable( $path ) ) {
			throw new SearchConsoleException( 'not_configured', 'Schlüsseldatei des Dienstkontos nicht gefunden oder nicht lesbar: ' . ( '' === $path ? '(kein Pfad)' : $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || 'service_account' !== ( $data['type'] ?? '' ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			throw new SearchConsoleException( 'invalid_key', 'Die Datei ist kein JSON-Schlüssel eines Google-Dienstkontos.' );
		}
		return new self( (string) $data['client_email'], (string) $data['private_key'], (string) ( $data['token_uri'] ?? 'https://oauth2.googleapis.com/token' ), $http );
	}

	/**
	 * Gültiges Zugriffstoken, bei Bedarf neu angefordert.
	 *
	 * @throws SearchConsoleException Bei ungültigem Schlüssel oder abgelehnter Anmeldung.
	 * @throws RetryableException Bei Netzwerk- und Serverfehlern.
	 */
	public function access_token(): string {
		$now = null !== $this->clock ? (int) ( $this->clock )() : time();
		if ( null !== $this->token && $this->token['expires'] > $now + 60 ) {
			return $this->token['token'];
		}
		$response = $this->http->request(
			'POST',
			$this->token_uri,
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			http_build_query(
				array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $this->jwt( $now ),
				),
				'',
				'&'
			),
			30
		);
		$status   = $response['status'];
		$data     = json_decode( $response['body'], true );
		if ( 0 === $status || 429 === $status || $status >= 500 ) {
			throw new RetryableException( 0 === $status ? 'network' : 'http_' . $status, 'Google-Anmeldung: ' . ( 0 === $status ? 'Netzwerkfehler ' . (string) $response['error'] : 'HTTP ' . $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 200 !== $status || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$detail = is_array( $data ) ? trim( (string) ( $data['error_description'] ?? $data['error'] ?? '' ) ) : '';
			throw new SearchConsoleException( 'auth_failed', 'Google lehnt die Anmeldung des Dienstkontos ab (HTTP ' . $status . ( '' !== $detail ? ': ' . mb_substr( $detail, 0, 200 ) : '' ) . '). Ist der Schlüssel noch aktiv?' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$this->token = array(
			'token'   => (string) $data['access_token'],
			'expires' => $now + (int) ( $data['expires_in'] ?? 3600 ),
		);
		return $this->token['token'];
	}

	/**
	 * Signiertes JWT für den Token-Tausch.
	 *
	 * @param int $now Zeitpunkt.
	 * @throws SearchConsoleException Wenn der Schlüssel nicht lesbar ist.
	 */
	public function jwt( int $now ): string {
		$b64    = static fn( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$header = $b64(
			(string) Json::encode(
				array(
					'alg' => 'RS256',
					'typ' => 'JWT',
				)
			)
		);
		$claims = $b64(
			Json::encode(
				array(
					'iss'   => $this->client_email,
					'scope' => self::SCOPE,
					'aud'   => $this->token_uri,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$key    = openssl_pkey_get_private( $this->private_key );
		if ( false === $key ) {
			throw new SearchConsoleException( 'invalid_key', 'Der private Schlüssel in der Datei ist nicht lesbar.' );
		}
		$signature = '';
		if ( ! openssl_sign( $header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new SearchConsoleException( 'invalid_key', 'Signieren mit dem privaten Schlüssel fehlgeschlagen.' );
		}
		return $header . '.' . $claims . '.' . $b64( $signature );
	}
}
