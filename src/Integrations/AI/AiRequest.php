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
	 */
	public function __construct(
		public readonly string $task,
		public readonly string $system,
		public readonly string $user,
		public readonly ?array $json_schema = null,
		public readonly string $schema_name = 'result',
		public readonly bool $web_search = false,
		public readonly int $max_output_tokens = 4000,
		public readonly int $timeout = 120
	) {
		if ( null !== $json_schema && $web_search ) {
			throw new \InvalidArgumentException( 'Websuche und JSON-Schema werden in getrennten Aufrufen verwendet.' );
		}
	}

	/**
	 * Kopie mit anderer Nutzernachricht (für den Korrekturversuch).
	 *
	 * @param string $user Neue Nutzernachricht.
	 */
	public function with_user( string $user ): self {
		return new self( $this->task, $this->system, $user, $this->json_schema, $this->schema_name, $this->web_search, $this->max_output_tokens, $this->timeout );
	}
}
