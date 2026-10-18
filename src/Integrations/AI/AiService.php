<?php
/**
 * Fachliche Schnittstelle zur KI.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Workflow\RetryableException;

/**
 * Was die fachlichen Module aufrufen. Kümmert sich um:
 * - Prüfung gegen das JSON-Schema und genau einen Korrekturversuch (Spezifikation 8.2, M7)
 * - zweistufige Recherche: erst Websuche mit Quellen, dann Strukturierung ohne Werkzeuge (M7a)
 * - Protokoll von Verbrauch und Kosten in lsec_ai_calls
 */
final class AiService {

	/**
	 * Konstruktor.
	 *
	 * @param AiClient   $client Anbieter.
	 * @param AiCallLog  $log    Protokoll.
	 * @param PriceTable $prices Preistabelle.
	 */
	public function __construct(
		private readonly AiClient $client,
		private readonly AiCallLog $log,
		private readonly PriceTable $prices = new PriceTable()
	) {}

	/**
	 * Der verwendete Adapter.
	 */
	public function client(): AiClient {
		return $this->client;
	}

	/**
	 * Strukturierte Antwort nach JSON-Schema. Bei ungültiger Antwort genau ein Korrekturversuch.
	 *
	 * @param AiRequest $request Anfrage mit json_schema.
	 * @param int|null  $run_id  Ablauf.
	 * @param int|null  $step_id Schritt.
	 * @throws \InvalidArgumentException Ohne Schema.
	 * @throws AiException                Wenn auch der Korrekturversuch ungültig ist.
	 */
	public function structured( AiRequest $request, ?int $run_id = null, ?int $step_id = null ): AiResponse {
		if ( null === $request->json_schema ) {
			throw new \InvalidArgumentException( 'structured() braucht ein JSON-Schema.' );
		}

		$response = $this->call( $request, $run_id, $step_id );
		$errors   = $this->check( $response, $request->json_schema, $data );
		if ( array() === $errors ) {
			return $response->with_data( $data );
		}

		$this->log->record( $request->task, $this->client->provider(), $this->client->model(), 'invalid_output', $response, self::hash( $request ), $run_id, $step_id );

		$repair   = $request->with_user(
			$request->user . "\n\n---\nDeine vorige Antwort entsprach nicht dem verlangten JSON-Schema. Fehler:\n- "
			. implode( "\n- ", array_slice( $errors, 0, 10 ) )
			. "\nAntworte erneut, ausschließlich mit gültigem JSON nach dem Schema."
		);
		$response = $this->call( $repair, $run_id, $step_id );
		$errors   = $this->check( $response, $request->json_schema, $data );
		if ( array() === $errors ) {
			return $response->with_data( $data );
		}

		$this->log->record( $request->task, $this->client->provider(), $this->client->model(), 'invalid_output', $response, self::hash( $repair ), $run_id, $step_id );
		throw new AiException( 'invalid_output', 'Antwort auch nach Korrektur ungültig: ' . implode( '; ', array_slice( $errors, 0, 5 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Freier Text, optional mit Websuche.
	 *
	 * @param AiRequest $request Anfrage ohne Schema.
	 * @param int|null  $run_id  Ablauf.
	 * @param int|null  $step_id Schritt.
	 */
	public function text( AiRequest $request, ?int $run_id = null, ?int $step_id = null ): AiResponse {
		return $this->call( $request, $run_id, $step_id );
	}

	/**
	 * Zweistufige Recherche: Websuche mit Quellen, danach Strukturierung ohne Werkzeuge.
	 *
	 * @param string               $task        Aufgabe.
	 * @param string               $system      Systemanweisung.
	 * @param string               $question    Rechercheauftrag.
	 * @param array<string, mixed> $schema      Zielschema.
	 * @param string               $schema_name Name des Schemas.
	 * @param int|null             $run_id      Ablauf.
	 * @return array{research: AiResponse, result: AiResponse}
	 */
	public function research( string $task, string $system, string $question, array $schema, string $schema_name = 'result', ?int $run_id = null ): array {
		$research = $this->call(
			new AiRequest( $task . '_research', $system, $question, null, $schema_name, true, 3000 ),
			$run_id
		);

		$sources = '';
		foreach ( $research->sources as $index => $source ) {
			$sources .= sprintf( "[%d] %s – %s\n", $index + 1, $source['title'], $source['url'] );
		}

		$result = $this->structured(
			new AiRequest(
				$task,
				$system . "\nNutze ausschließlich die Rechercheergebnisse unten. Erfinde keine Fakten und keine Quellen.",
				"Rechercheergebnis:\n" . $research->text . "\n\nQuellen:\n" . ( '' !== $sources ? $sources : "(keine)\n" ),
				$schema,
				$schema_name
			),
			$run_id
		);

		return array(
			'research' => $research,
			'result'   => $result,
		);
	}

	/**
	 * Ein Aufruf mit Protokoll.
	 *
	 * @param AiRequest $request Anfrage.
	 * @param int|null  $run_id  Ablauf.
	 * @param int|null  $step_id Schritt.
	 * @throws RetryableException Bei vorübergehenden Fehlern.
	 * @throws AiException        Bei endgültigen Fehlern.
	 */
	private function call( AiRequest $request, ?int $run_id = null, ?int $step_id = null ): AiResponse {
		$hash = self::hash( $request );
		try {
			$response = $this->client->send( $request );
		} catch ( RetryableException $e ) {
			$this->log->record( $request->task, $this->client->provider(), $this->client->model(), 'retryable_error', null, $hash, $run_id, $step_id );
			throw $e;
		} catch ( AiException $e ) {
			$this->log->record( $request->task, $this->client->provider(), $this->client->model(), 'fatal_error', null, $hash, $run_id, $step_id );
			throw $e;
		}

		$cost = $this->prices->cost_of( $response );
		if ( $cost !== $response->cost ) {
			$response = new AiResponse( $response->provider, $response->model, $response->text, null, $response->sources, $response->input_tokens, $response->output_tokens, $cost, $response->duration_ms, $response->finish );
		}
		$this->log->record( $request->task, $this->client->provider(), $this->client->model(), 'ok', $response, $hash, $run_id, $step_id );
		return $response;
	}

	/**
	 * Prüft eine Antwort.
	 *
	 * @param AiResponse                $response Antwort.
	 * @param array<string, mixed>      $schema   Schema.
	 * @param array<string, mixed>|null $data     Ausgabe: dekodierte Daten.
	 * @return list<string> Fehler.
	 */
	private function check( AiResponse $response, array $schema, ?array &$data ): array {
		$data = null;
		if ( 'length' === $response->finish ) {
			return array( 'Antwort wurde wegen der Längenbegrenzung abgeschnitten.' );
		}
		$decoded = Json::extract( $response->text );
		if ( null === $decoded ) {
			return array( 'Antwort ist kein gültiges JSON.' );
		}
		$errors = JsonSchemaValidator::validate( $decoded, $schema );
		if ( array() === $errors ) {
			$data = $decoded;
		}
		return $errors;
	}

	/**
	 * Fingerabdruck einer Anfrage (ohne Inhalte zu speichern).
	 *
	 * @param AiRequest $request Anfrage.
	 */
	private static function hash( AiRequest $request ): string {
		return hash( 'sha256', $request->task . "\0" . $request->system . "\0" . $request->user . "\0" . Json::encode( $request->json_schema ?? array() ) );
	}
}
