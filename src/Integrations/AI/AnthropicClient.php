<?php
/**
 * Adapter für die Anthropic Messages API.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Infrastructure\Http\HttpTransport;

/**
 * Messages API über https://api.anthropic.com/v1/messages.
 *
 * - Strukturierte Ausgabe über output_config.format (json_schema).
 * - Websuche über das Werkzeug web_search; Quellen aus Citations und Suchergebnissen.
 *   Strukturierte Ausgabe und Citations sind im selben Aufruf nicht kombinierbar,
 *   deshalb trennt AiService Recherche und Strukturierung.
 * - Kosten werden aus Token und Preistabelle berechnet.
 */
final class AnthropicClient implements AiClient {

	public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	public const VERSION  = '2023-06-01';

	/**
	 * Konstruktor.
	 *
	 * @param HttpTransport $http    HTTP.
	 * @param string        $api_key Schlüssel.
	 * @param string        $model   Modell, z. B. claude-sonnet-5-5.
	 */
	public function __construct(
		private readonly HttpTransport $http,
		private readonly string $api_key,
		private readonly string $model
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function provider(): string {
		return 'anthropic';
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
			'max_tokens' => $request->max_output_tokens,
			'system'     => $request->system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $request->user,
				),
			),
		);
		if ( null !== $request->json_schema ) {
			$body['output_config'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $request->json_schema,
				),
			);
		}
		if ( $request->web_search ) {
			$body['tools'] = array(
				array(
					'type'     => 'web_search_20250305',
					'name'     => 'web_search',
					'max_uses' => max( 1, $request->web_max_results ),
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
		$start    = microtime( true );
		$result   = $this->http->request(
			'POST',
			self::ENDPOINT,
			array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => self::VERSION,
				'content-type'      => 'application/json',
			),
			Json::encode( $this->build_body( $request ) ),
			$request->timeout
		);
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );
		$data     = Json::decode( $result['body'] );

		if ( 200 !== $result['status'] ) {
			AiException::from_http( $this->provider(), $result['status'], (string) ( $data['error']['message'] ?? $result['error'] ?? '' ) );
		}
		if ( null === $data || ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			throw new AiException( 'invalid_response', 'anthropic: Antwort ohne content.' );
		}

		$text    = '';
		$sources = array();
		foreach ( $data['content'] as $block ) {
			$type = $block['type'] ?? '';
			if ( 'text' === $type ) {
				$text .= (string) ( $block['text'] ?? '' );
				foreach ( (array) ( $block['citations'] ?? array() ) as $citation ) {
					if ( ! empty( $citation['url'] ) ) {
						$sources[] = array(
							'url'   => (string) $citation['url'],
							'title' => (string) ( $citation['title'] ?? '' ),
						);
					}
				}
			} elseif ( 'web_search_tool_result' === $type && is_array( $block['content'] ?? null ) ) {
				foreach ( $block['content'] as $hit ) {
					if ( ! empty( $hit['url'] ) ) {
						$sources[] = array(
							'url'   => (string) $hit['url'],
							'title' => (string) ( $hit['title'] ?? '' ),
						);
					}
				}
			}
		}

		$stop = (string) ( $data['stop_reason'] ?? 'end_turn' );
		if ( 'refusal' === $stop ) {
			throw new AiException( 'refusal', 'anthropic: Das Modell hat die Anfrage abgelehnt.' );
		}

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
			'max_tokens' === $stop ? 'length' : 'stop'
		);
	}
}
