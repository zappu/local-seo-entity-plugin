<?php
/**
 * Protokoll der KI-Aufrufe.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

/**
 * Speichert Verbrauch und Status jedes KI-Aufrufs (Tabelle lsec_ai_calls).
 */
interface AiCallLog {

	/**
	 * Protokolliert einen Aufruf.
	 *
	 * @param string          $task          Aufgabe.
	 * @param string          $provider      Anbieter.
	 * @param string          $model         Modell.
	 * @param string          $status        ok, retryable_error, fatal_error, invalid_output.
	 * @param AiResponse|null $response      Antwort, falls vorhanden.
	 * @param string          $request_hash  Fingerabdruck der Anfrage.
	 * @param int|null        $run_id        Ablauf.
	 * @param int|null        $step_id       Schritt.
	 */
	public function record( string $task, string $provider, string $model, string $status, ?AiResponse $response, string $request_hash, ?int $run_id = null, ?int $step_id = null ): void;
}
