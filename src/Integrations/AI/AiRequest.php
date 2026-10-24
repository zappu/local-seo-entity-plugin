<?php
/**
 * KI-Anfrage.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

/**
 * Was die fachlichen Module an die KI schicken. Unabhängig vom Anbieter.
 */
final class AiRequest {

	/**
	 * Konstruktor.
	 *
	 * @param string                    $task              Aufgabe, z. B. entity_extraction (für Protokoll und Kosten).
	 * @param string                    $system            Systemanweisung.
	 * @param string                    $user              Nutzernachricht.
	 * @param array<string, mixed>|null $json_schema       Erwartete Struktur oder null für freien Text.
	 * @param string                    $schema_name       Name des Schemas (nur Buchstaben, Ziffern, _).
	 * @param bool                      $web_search        Websuche erlauben. Nie zusammen mit json_schema (siehe AiService::research()).
	 * @param int                       $max_output_tokens Obergrenze für die Antwort.
	 * @param int                       $timeout           Zeitlimit in Sekunden.
	 * @param int                       $web_max_results   Treffer bzw. Suchen der Websuche (Spezifikation 8.1b).
	 * @param string                    $web_engine        Suchmaschine bei OpenRouter (native, exa, parallel, perplexity); leer = Standard.
	 * @param string[]                  $images            Bilder als data-URL (data:image/jpeg;base64,…) für Modelle, die Bilder lesen (0.10.0).
	 * @throws \InvalidArgumentException Wenn Schema und Websuche zusammen verlangt werden.
	 */
	public function __construct(
		public readonly string $task,
		public readonly string $system,
		public readonly string $user,
		public readonly ?array $json_schema = null,
		public readonly string $schema_name = 'result',
		public readonly bool $web_search = false,
		public readonly int $max_output_tokens = 4000,
		public readonly int $timeout = 120,
		public readonly int $web_max_results = 3,
		public readonly string $web_engine = '',
		public readonly array $images = array()
	) {
		if ( null !== $json_schema && $web_search ) {
			throw new \InvalidArgumentException( 'Websuche und JSON-Schema werden in getrennten Aufrufen verwendet.' );
		}
	}

	/**
	 * Zerlegt eine data-URL in Medientyp und Base64-Daten.
	 *
	 * @param string $url data-URL (data:image/jpeg;base64,…).
	 * @return array{type: string, data: string}
	 */
	public static function split_data_url( string $url ): array {
		if ( preg_match( '#^data:([a-z0-9.+/-]+);base64,(.*)$#is', $url, $m ) ) {
			return array(
				'type' => strtolower( $m[1] ),
				'data' => $m[2],
			);
		}
		return array(
			'type' => '',
			'data' => '',
		);
	}

	/**
	 * Kopie mit anderer Nutzernachricht (für den Korrekturversuch).
	 *
	 * @param string $user Neue Nutzernachricht.
	 */
	public function with_user( string $user ): self {
		return new self( $this->task, $this->system, $user, $this->json_schema, $this->schema_name, $this->web_search, $this->max_output_tokens, $this->timeout, $this->web_max_results, $this->web_engine, $this->images );
	}
}
