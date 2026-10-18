<?php
/**
 * Adapter für OpenRouter.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Infrastructure\Http\HttpTransport;

/**
 * Chat Completions über https://openrouter.ai/api/v1/chat/completions.
 *
 * - Strukturierte Ausgabe über response_format (json_schema, strict).
 * - Websuche über das Plugin "web"; Quellen kommen als Annotationen vom Typ url_citation.
 * - Kosten stehen direkt in usage.cost.
 * - Routing nur zu Anbietern, die keine Daten zum Training speichern (data_collection: deny),
 *   und nur zu Anbietern, die alle Parameter unterstützen (require_parameters).
 */
final class OpenRouterClient implements AiClient {

	public const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

	/**
	 * Konstruktor.
	 *
	 * @param HttpTransport $http     HTTP.
	 * @param string        $api_key  Schlüssel.
	 * @param string        $model    Modell, z. B. anthropic/claude-sonnet-5.5.
	 * @param string        $site_url Website (Kopfzeile HTTP-Referer).
	 */
	public function __construct(
		private readonly HttpTransport $http,
		private readonly string $api_key,
		private readonly string $model,
		private readonly string $site_url = ''
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function provider(): string {
		return 'openrouter';
	}

	/**
	 * {@inheritDoc}
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Baut den Anfragerumpf (öffentlich für Tests).
	 *
	 * @param AiRequest $request Anfrage.
	 * @return array<string, mixed>
	 */
	public function build_body( AiRequest $request ): array {
		$body = array(
			'model'      => $this->model,
			'messages'   => array(
				array(
					'role'    => 'system',
					'content' => $request->system,
				),
				array(
					'role'    => 'user',
					'content' => $request->user,
				),
			),
			'max_tokens' => $request->max_output_tokens,
			'provider'   => array(
				'data_collection'    => 'deny',
				'require_parameters' => true,
			),
		);
		if ( null !== $request->json_schema ) {
			$body['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => $request->schema_name,
					'strict' => true,
					'schema' => $request->json_schema,
				),
			);
		}
		if ( $request->web_search ) {
			$body['plugins'] = array(
				array(
					'id'          => 'web',
					'max_results' => max( 1, $request->web_max_results ),
				),
			);
		}
		return $body;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AiRequest $request Anfrage.
	 * @throws AiException Bei unbrauchbarer Antwort.
	 */
	public function send( AiRequest $request ): AiResponse {
		$start   = microtime( true );
		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			'X-Title'       => 'Local SEO Entity Plugin',
		);
		if ( '' !== $this->site_url ) {
			$headers['HTTP-Referer'] = $this->site_url;
		}
		$result   = $this->http->request( 'POST', self::ENDPOINT, $headers, Json::encode( $this->build_body( $request ) ), $request->timeout );
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );
		$data     = Json::decode( $result['body'] );

		if ( 200 !== $result['status'] ) {
			AiException::from_http( $this->provider(), $result['status'], (string) ( $data['error']['message'] ?? $result['error'] ?? '' ) );
		}
		if ( null === $data || ! isset( $data['choices'][0] ) ) {
			throw new AiException( 'invalid_response', 'openrouter: Antwort ohne choices.' );
		}

		$choice = $data['choices'][0];
		if ( isset( $choice['error'] ) ) {
			AiException::from_http( $this->provider(), (int) ( $choice['error']['code'] ?? 500 ), (string) ( $choice['error']['message'] ?? '' ) );
		}

		$sources = array();
		foreach ( (array) ( $choice['message']['annotations'] ?? array() ) as $annotation ) {
			$citation = $annotation['url_citation'] ?? $annotation;
			if ( ! empty( $citation['url'] ) ) {
				$sources[] = array(
					'url'   => (string) $citation['url'],
					'title' => (string) ( $citation['title'] ?? '' ),
				);
			}
		}

		return new AiResponse(
			$this->provider(),
			(string) ( $data['model'] ?? $this->model ),
			(string) ( $choice['message']['content'] ?? '' ),
			null,
			self::unique_sources( $sources ),
			isset( $data['usage']['prompt_tokens'] ) ? (int) $data['usage']['prompt_tokens'] : null,
			isset( $data['usage']['completion_tokens'] ) ? (int) $data['usage']['completion_tokens'] : null,
			isset( $data['usage']['cost'] ) ? (float) $data['usage']['cost'] : null,
			$duration,
			(string) ( $choice['finish_reason'] ?? 'stop' )
		);
	}

	/**
	 * Entfernt doppelte Quellen.
	 *
	 * @param array<int, array{url: string, title: string}> $sources Quellen.
	 * @return array<int, array{url: string, title: string}>
	 */
	public static function unique_sources( array $sources ): array {
		$seen = array();
		$out  = array();
		foreach ( $sources as $source ) {
			if ( isset( $seen[ $source['url'] ] ) ) {
				continue;
			}
			$seen[ $source['url'] ] = true;
			$out[]                  = $source;
		}
		return $out;
	}
}
