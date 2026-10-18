<?php
/**
 * Protokoll-Attrappe für Tests.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Support;

use Lsec\Integrations\AI\AiCallLog;
use Lsec\Integrations\AI\AiResponse;

/**
 * Merkt sich protokollierte Aufrufe im Speicher.
 */
final class MemoryAiCallLog implements AiCallLog {

	/**
	 * Einträge.
	 *
	 * @var list<array{task: string, status: string, cost: float|null, run_id: int|null, model: string}>
	 */
	public array $entries = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param string          $task         Aufgabe.
	 * @param string          $provider     Anbieter.
	 * @param string          $model        Modell.
	 * @param string          $status       Status.
	 * @param AiResponse|null $response     Antwort.
	 * @param string          $request_hash Fingerabdruck.
	 * @param int|null        $run_id       Ablauf.
	 * @param int|null        $step_id      Schritt.
	 */
	public function record( string $task, string $provider, string $model, string $status, ?AiResponse $response, string $request_hash, ?int $run_id = null, ?int $step_id = null ): void {
		$this->entries[] = array(
			'task'   => $task,
			'status' => $status,
			'cost'   => $response?->cost,
			'run_id' => $run_id,
			'model'  => $model,
		);
	}
}
