<?php
/**
 * Bilderzeugung über die Bild-Schnittstelle von OpenRouter.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Infrastructure\Http\HttpTransport;

/**
 * Nachtrag 24.10.2026 (0.10.0): Findet die Produktion kein passendes Bild in der Mediathek, erzeugt sie eines.
 * POST https://openrouter.ai/api/v1/images mit model, prompt, aspect_ratio und (falls das Modell es kennt)
 * resolution; die Antwort enthält das Bild als Base64 in data[0].b64_json und die Kosten in usage.cost.
 * Welche Parameter ein Modell kennt, steht in GET /api/v1/images/models (supported_parameters).
 *
 * Die Klasse kennt WordPress nicht.
 */
final class OpenRouterImageClient {

	public const ENDPOINT = 'https://openrouter.ai/api/v1/images';

	public const MODELS = 'https://openrouter.ai/api/v1/images/models';

	/**
	 * Konstruktor.
	 *
	 * @param HttpTransport $http     HTTP.
	 * @param string        $api_key  Schlüssel.
	 * @param string        $model    Bildmodell, z. B. black-forest-labs/flux-3-image.
	 * @param string        $site_url Website (Kopfzeile HTTP-Referer).
	 */
	public function __construct(
		private readonly HttpTransport $http,
		private readonly string $api_key,
		public readonly string $model,
		private readonly string $site_url = ''
	) {}

	/**
	 * Anfragerumpf. Parameter, die das Modell nicht kennt, bleiben weg (sonst lehnen manche Anbieter ab).
	 *
	 * @param string            $prompt       Bildauftrag.
	 * @param string            $aspect_ratio Seitenverhältnis, z. B. 16:9.
	 * @param string            $resolution   Auflösung, z. B. 1K; leer = Standard des Modells.
	 * @param list<string>|null $supported    Parameter des Modells; null = unbekannt (dann nur aspect_ratio).
	 * @return array<string, mixed>
	 */
	public function build_body( string $prompt, string $aspect_ratio, string $resolution, ?array $supported ): array {
		$body = array(
			'model'  => $this->model,
			'prompt' => $prompt,
			'n'      => 1,
		);
		if ( '' !== $aspect_ratio && ( null === $supported || in_array( 'aspect_ratio', $supported, true ) ) ) {
			$body['aspect_ratio'] = $aspect_ratio;
		}
		if ( '' !== $resolution && null !== $supported && in_array( 'resolution', $supported, true ) ) {
			$body['resolution'] = $resolution;
		}
		if ( null !== $supported && in_array( 'output_format', $supported, true ) ) {
			$body['output_format'] = 'webp';
		}
		return $body;
	}

	/**
	 * Erzeugt ein Bild.
	 *
	 * @param string            $prompt       Bildauftrag.
	 * @param string            $aspect_ratio Seitenverhältnis.
	 * @param string            $resolution   Auflösung.
	 * @param list<string>|null $supported    Parameter des Modells.
	 * @param int               $timeout      Zeitlimit in Sekunden.
	 * @return array{bytes: string, type: string, cost: float|null, model: string, duration_ms: int}
	 * @throws AiException                            Bei endgültigen Fehlern oder ohne Bild.
	 * @throws \Lsec\Workflow\RetryableException Bei vorübergehenden Fehlern.
	 */
	public function generate( string $prompt, string $aspect_ratio, string $resolution, ?array $supported, int $timeout = 180 ): array {
		$start  = microtime( true );
		$result = $this->http->request( 'POST', self::ENDPOINT, $this->headers(), Json::encode( $this->build_body( $prompt, $aspect_ratio, $resolution, $supported ) ), $timeout );
		$data   = Json::decode( $result['body'] );
		if ( 200 !== $result['status'] ) {
			AiException::from_http( 'openrouter', $result['status'], (string) ( $data['error']['message'] ?? $result['error'] ?? '' ) );
		}
		return self::parse( is_array( $data ) ? $data : array(), $this->model ) + array( 'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ) );
	}

	/**
	 * Liest die Antwort.
	 *
	 * @param array<string, mixed> $data  Antwort.
	 * @param string               $model Modell.
	 * @return array{bytes: string, type: string, cost: float|null, model: string}
	 * @throws AiException Ohne Bild.
	 */
	public static function parse( array $data, string $model ): array {
		$image = $data['data'][0] ?? null;
		$b64   = is_array( $image ) ? (string) ( $image['b64_json'] ?? '' ) : '';
		$type  = is_array( $image ) ? strtolower( (string) ( $image['media_type'] ?? '' ) ) : '';
		if ( '' === $b64 && is_array( $image ) && str_starts_with( (string) ( $image['url'] ?? '' ), 'data:' ) ) {
			$part = AiRequest::split_data_url( (string) $image['url'] );
			$b64  = $part['data'];
			$type = $part['type'];
		}
		$bytes = '' === $b64 ? '' : (string) base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( '' === $bytes ) {
			throw new AiException( 'invalid_response', 'openrouter: Antwort ohne Bild.' );
		}
		if ( ! in_array( $type, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
			$type = self::sniff( $bytes );
		}
		return array(
			'bytes' => $bytes,
			'type'  => $type,
			'cost'  => isset( $data['usage']['cost'] ) ? (float) $data['usage']['cost'] : null,
			'model' => (string) ( $data['model'] ?? $model ),
		);
	}

	/**
	 * Bildformat an den ersten Bytes.
	 *
	 * @param string $bytes Bild.
	 */
	public static function sniff( string $bytes ): string {
		if ( str_starts_with( $bytes, "\x89PNG" ) ) {
			return 'image/png';
		}
		if ( str_starts_with( $bytes, "\xFF\xD8" ) ) {
			return 'image/jpeg';
		}
		if ( str_starts_with( $bytes, 'RIFF' ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}
		return '';
	}

	/**
	 * Parameter eines Modells laut Modellliste.
	 *
	 * @param array<string, mixed> $catalog Antwort von GET /images/models.
	 * @param string               $model   Modell.
	 * @return list<string>|null Null, wenn das Modell fehlt.
	 */
	public static function supported_parameters( array $catalog, string $model ): ?array {
		foreach ( (array) ( $catalog['data'] ?? array() ) as $entry ) {
			$id = is_array( $entry ) ? (string) ( $entry['id'] ?? '' ) : '';
			if ( $id === $model ) {
				return array_values( array_map( 'strval', array_keys( (array) ( $entry['supported_parameters'] ?? array() ) ) ) );
			}
		}
		return null;
	}

	/**
	 * Modellliste (öffentlich, ohne Kosten).
	 *
	 * @return array<string, mixed>
	 */
	public function models(): array {
		$result = $this->http->request( 'GET', self::MODELS, $this->headers(), null, 30 );
		$data   = 200 === $result['status'] ? Json::decode( $result['body'] ) : null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Kopfzeilen.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			'X-Title'       => 'Local SEO Entity Plugin',
		);
		if ( '' !== $this->site_url ) {
			$headers['HTTP-Referer'] = $this->site_url;
		}
		return $headers;
	}
}
