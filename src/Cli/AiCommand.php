<?php
/**
 * WP-CLI: KI-Anbindung testen.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Integrations\AI\AiException;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\Json;
use Lsec\Research\SourceRepository;
use Lsec\Workflow\RetryableException;
use WP_CLI;

/**
 * Prüft die KI-Anbindung mit echten Aufrufen (Prüfpunkte M7, M7a, M7b).
 */
final class AiCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Testaufruf mit strukturierter Antwort, optional zweistufig mit Websuche.
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<provider>]
	 * : openrouter, anthropic oder openai. Standard: Einstellung ai_provider.
	 *
	 * [--model=<model>]
	 * : Modell. Standard: Einstellung ai_model.
	 *
	 * [--web]
	 * : Zweistufige Recherche mit Websuche (M7a).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec ai test
	 *     wp lsec ai test --web
	 *     wp lsec ai test --provider=anthropic --model=claude-sonnet-5-5
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function test( array $args, array $assoc_args ): void {
		$provider = isset( $assoc_args['provider'] ) ? (string) $assoc_args['provider'] : null;
		$model    = isset( $assoc_args['model'] ) ? (string) $assoc_args['model'] : null;

		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'city'      => array( 'type' => 'string' ),
				'districts' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name' => array( 'type' => 'string' ),
							'fact' => array( 'type' => 'string' ),
						),
						'required'             => array( 'name', 'fact' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'city', 'districts' ),
			'additionalProperties' => false,
		);
		$system = 'Du bist ein sorgfältiger Rechercheassistent für lokale Inhalte in Deutschland. Antworte auf Deutsch.';

		try {
			$service = $this->plugin->ai->service( $provider, $model );
			WP_CLI::log( sprintf( 'Anbieter: %s, Modell: %s', $service->client()->provider(), $service->client()->model() ) );

			if ( ! empty( $assoc_args['web'] ) ) {
				$out = $service->research(
					'selftest_research',
					$system,
					'Recherchiere im Web drei Stadtteile der Gemeinde Uetze (Region Hannover) und je eine belegbare Tatsache dazu.',
					$schema,
					'districts'
				);
				$this->print_response( 'Schritt 1: Recherche mit Websuche', $out['research'], false );
				foreach ( $out['research']->sources as $source ) {
					WP_CLI::log( '  Quelle: ' . $source['title'] . ' – ' . $source['url'] );
				}
				$ids = ( new SourceRepository() )->save_all( $out['research']->sources, 'ai_search' );
				WP_CLI::log( sprintf( '  %d Quelle(n) in lsec_sources gespeichert (IDs %s).', count( $ids ), implode( ', ', $ids ) ) );
				$this->print_response( 'Schritt 2: Strukturierung', $out['result'], true );
				if ( array() === $out['research']->sources ) {
					WP_CLI::warning( 'Die Recherche lieferte keine Quellen. Unterstützt das Modell Websuche?' );
				}
			} else {
				$response = $service->structured(
					new AiRequest(
						'selftest',
						$system,
						'Nenne drei Stadtteile von Hannover mit je einer kurzen, allgemein bekannten Tatsache.',
						$schema,
						'districts',
						false,
						800,
						60
					)
				);
				$this->print_response( 'Strukturierte Antwort', $response, true );
			}
			WP_CLI::success( 'KI-Anbindung funktioniert. Verbrauch steht in lsec_ai_calls.' );
		} catch ( RetryableException $e ) {
			WP_CLI::error( 'Vorübergehender Fehler (' . $e->error_code . '): ' . $this->plugin->logger->redact( $e->getMessage() ) );
		} catch ( AiException $e ) {
			WP_CLI::error( 'Fehler (' . $e->error_code . '): ' . $this->plugin->logger->redact( $e->getMessage() ) );
		}
	}

	/**
	 * Listet OpenRouter-Modelle mit strukturierter Ausgabe.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Filter auf Modell-ID oder Name, z. B. claude oder gpt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec ai models --search=claude
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function models( array $args, array $assoc_args ): void {
		$result = $this->plugin->http->request( 'GET', 'https://openrouter.ai/api/v1/models', array(), null, 30 );
		$data   = Json::decode( $result['body'] );
		if ( 200 !== $result['status'] || null === $data ) {
			WP_CLI::error( 'Modellliste nicht abrufbar (HTTP ' . $result['status'] . ').' );
		}
		$search = strtolower( (string) ( $assoc_args['search'] ?? '' ) );
		$rows   = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $model ) {
			$params = (array) ( $model['supported_parameters'] ?? array() );
			if ( ! in_array( 'structured_outputs', $params, true ) ) {
				continue;
			}
			$id = (string) ( $model['id'] ?? '' );
			if ( '' !== $search && ! str_contains( strtolower( $id . ' ' . ( $model['name'] ?? '' ) ), $search ) ) {
				continue;
			}
			$rows[] = array(
				'id'             => $id,
				'kontext'        => (string) ( $model['context_length'] ?? '' ),
				'usd_1m_eingabe' => isset( $model['pricing']['prompt'] ) ? (string) round( (float) $model['pricing']['prompt'] * 1000000, 2 ) : '',
				'usd_1m_ausgabe' => isset( $model['pricing']['completion'] ) ? (string) round( (float) $model['pricing']['completion'] * 1000000, 2 ) : '',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'kontext', 'usd_1m_eingabe', 'usd_1m_ausgabe' ) );
		WP_CLI::log( count( $rows ) . ' Modelle mit strukturierter Ausgabe.' );
	}

	/**
	 * Gibt eine Antwort aus.
	 *
	 * @param string                           $title    Überschrift.
	 * @param \Lsec\Integrations\AI\AiResponse $response Antwort.
	 * @param bool                             $as_json  Daten als JSON zeigen.
	 */
	private function print_response( string $title, \Lsec\Integrations\AI\AiResponse $response, bool $as_json ): void {
		WP_CLI::log( '== ' . $title . ' ==' );
		WP_CLI::log( $as_json ? (string) wp_json_encode( $response->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : mb_substr( $response->text, 0, 800 ) );
		WP_CLI::log(
			sprintf(
				'Modell %s · %s Eingabe-Token · %s Ausgabe-Token · Kosten %s · %d ms',
				$response->model,
				null === $response->input_tokens ? '?' : (string) $response->input_tokens,
				null === $response->output_tokens ? '?' : (string) $response->output_tokens,
				null === $response->cost ? 'unbekannt' : '$' . number_format( $response->cost, 5 ),
				$response->duration_ms
			)
		);
	}
}
