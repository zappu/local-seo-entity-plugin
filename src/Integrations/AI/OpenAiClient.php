<?php
/**
 * Adapter für die OpenAI Responses API.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Infrastructure\Http\HttpTransport;

/**
 * Responses API über https://api.openai.com/v1/responses.
 *
 * - Strukturierte Ausgabe über text.format (json_schema, strict). Im strikten Modus
 *   müssen alle Eigenschaften "required" sein und additionalProperties false.
 * - Websuche über das Werkzeug web_search; Quellen als Annotationen url_citation.
 * - Für Datenhaltung in Europa ein Projekt mit EU-Region verwenden (Einstellung ai_base_url).
 */
final class OpenAiClient implements AiClient {

	public const ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * Konstruktor.
	 *
	 * @param HttpTransport $http     HTTP.
	 * @param string        $api_key  Schlüssel.
	 * @param string        $model    Modell.
	 * @param string        $endpoint Abweichender Endpunkt, z. B. für EU-Datenhaltung.
	 */
	public function __construct(
		private readonly HttpTransport $http,
		private readonly string $api_key,
		private readonly string $model,
		private readonly string $endpoint = self::ENDPOINT
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function provider(): string {
		return 'openai';
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
			'model'             => $this->model,
			'instructions'      => $request->system,
			'input'             => $request->user,
			'max_output_tokens' => $request->max_output_tokens,
			'store'             => false,
		);
		if ( null !== $request->json_schema ) {
			$body['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => $request->schema_name,
					'schema' => $request->json_schema,
					'strict' => true,
				),
			);
		}
		if ( $request->web_search ) {
			$body['tools'] = array( array( 'type' => 'web_search' ) );
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
		$start    = microtime( true );
		$result   = $this->http->request(
			'POST',
			$this->endpoint,
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			Json::encode( $this->build_body( $request ) ),
			$request->timeout
		);
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );
		$data     = Json::decode( $result['body'] );

		if ( 200 !== $result['status'] ) {
			AiException::from_http( $this->provider(), $result['status'], (string) ( $data['error']['message'] ?? $result['error'] ?? '' ) );
		}
		if ( null === $data || ! isset( $data['output'] ) || ! is_array( $data['output'] ) ) {
			throw new AiException( 'invalid_response', 'openai: Antwort ohne output.' );
		}

		$text    = '';
		$sources = array();
		foreach ( $data['output'] as $item ) {
			if ( 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
				$part_type = $part['type'] ?? '';
				if ( 'refusal' === $part_type ) {
					throw new AiException( 'refusal', 'openai: Das Modell hat die Anfrage abgelehnt.' );
				}
				if ( 'output_text' !== $part_type ) {
					continue;
				}
				$text .= (string) ( $part['text'] ?? '' );
				foreach ( (array) ( $part['annotations'] ?? array() ) as $annotation ) {
					if ( 'url_citation' === ( $annotation['type'] ?? '' ) && ! empty( $annotation['url'] ) ) {
						$sources[] = array(
							'url'   => (string) $annotation['url'],
							'title' => (string) ( $annotation['title'] ?? '' ),
						);
					}
				}
			}
		}

		$incomplete = 'incomplete' === ( $data['status'] ?? '' );

		return new AiResponse(
			$this->provider(),
			(string) ( $data['model'] ?? $this->model ),
			$text,
			null,
			OpenRouterClient::unique_sources( $sources ),
			isset( $data['usage']['input_tokens'] ) ? (int) $data['usage']['input_tokens'] : null,
			isset( $data['usage']['output_tokens'] ) ? (int) $data['usage']['output_tokens'] : null,
			null,
			$duration,
			$incomplete ? 'length' : 'stop'
		);
	}
}
