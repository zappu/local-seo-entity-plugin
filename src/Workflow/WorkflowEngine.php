<?php
/**
 * Ablaufsteuerung.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

use Lsec\Infrastructure\Logging\Logger;
use Lsec\Infrastructure\Queue\Queue;

/**
 * Startet Abläufe und führt pro Aufruf genau einen Schritt aus.
 *
 * Ablauf eines Aufrufs von process():
 * 1. Sperre holen; gelingt das nicht, arbeitet schon ein anderer Prozess daran.
 * 2. Den ersten nicht abgeschlossenen Schritt suchen. Abgeschlossene Schritte werden nie wiederholt.
 * 3. Versuch hochzählen, Schritt ausführen, Ergebnis speichern.
 * 4. Nächsten Schritt einplanen, später wiederholen oder den Ablauf beenden.
 * 5. Sperre freigeben.
 *
 * Bricht der Prozess mitten im Schritt ab, bleibt der Schritt auf "running" und die
 * Sperre läuft ab. Der Watchdog plant den Ablauf dann neu ein; der Schritt wird mit
 * dem nächsten Versuch wiederholt.
 */
final class WorkflowEngine {

	/**
	 * Konstruktor.
	 *
	 * @param WorkflowRegistry $registry Ablaufdefinitionen.
	 * @param RunRepository    $runs     Datenzugriff.
	 * @param Queue            $queue    Warteschlange.
	 * @param RetryPolicy      $retry    Wiederholungsregeln.
	 * @param Logger           $logger   Protokoll.
	 */
	public function __construct(
		private readonly WorkflowRegistry $registry,
		private readonly RunRepository $runs,
		private readonly Queue $queue,
		private readonly RetryPolicy $retry,
		private readonly Logger $logger
	) {}

	/**
	 * Startet einen Ablauf oder findet den vorhandenen mit gleichem Schlüssel.
	 *
	 * @param string               $type         Ablauftyp.
	 * @param string|null          $subject_type Gegenstand.
	 * @param int|null             $subject_id   ID des Gegenstands.
	 * @param array<string, mixed> $input        Startkontext.
	 * @param array<string, mixed> $key_extra    Zusätzliche Merkmale für den Idempotenz-Schlüssel.
	 * @return array{run_id: int, created: bool}
	 */
	public function start( string $type, ?string $subject_type, ?int $subject_id, array $input = array(), array $key_extra = array() ): array {
		$definition = $this->registry->get( $type );
		$key        = IdempotencyKey::for_run( $type, $subject_type, $subject_id, $key_extra );

		list( $run_id, $created ) = $this->runs->create(
			$type,
			$subject_type,
			$subject_id,
			$key,
			$input,
			$definition->steps(),
			$this->retry->max_attempts(),
			function_exists( 'get_current_user_id' ) ? get_current_user_id() : null
		);

		if ( $created ) {
			$this->queue->enqueue( $run_id );
			$this->logger->info(
				'Ablauf gestartet',
				array(
					'run_id' => $run_id,
					'type'   => $type,
				)
			);
		}

		return array(
			'run_id'  => $run_id,
			'created' => $created,
		);
	}

	/**
	 * Führt den nächsten offenen Schritt eines Ablaufs aus.
	 *
	 * @param int $run_id Ablauf-ID.
	 */
	public function process( int $run_id ): void {
		$run = $this->runs->find( $run_id );
		if ( null === $run ) {
			return;
		}

		$status = RunStatus::tryFrom( (string) $run['status'] );
		if ( null === $status || $status->is_terminal() || RunStatus::Waiting === $status ) {
			return;
		}

		if ( ! $this->registry->has( (string) $run['type'] ) ) {
			$this->fail_run( $run_id, 'unknown_type', 'Unbekannter Ablauftyp: ' . $run['type'] );
			return;
		}
		$definition = $this->registry->get( (string) $run['type'] );

		$token = $this->runs->acquire_lock( $run_id, $definition->lock_ttl );
		if ( null === $token ) {
			// Ein anderer Prozess arbeitet gerade an diesem Ablauf.
			return;
		}

		try {
			$this->process_locked( $run, $definition );
		} finally {
			$this->runs->release_lock( $run_id, $token );
		}
	}

	/**
	 * Schrittausführung bei gehaltener Sperre.
	 *
	 * @param array<string, mixed> $run        Ablaufzeile.
	 * @param WorkflowDefinition   $definition Definition.
	 */
	private function process_locked( array $run, WorkflowDefinition $definition ): void {
		$run_id  = (int) $run['id'];
		$steps   = $this->runs->steps( $run_id );
		$outputs = array();
		$next    = null;

		foreach ( $steps as $step ) {
			$step_status = StepStatus::tryFrom( (string) $step['status'] ) ?? StepStatus::Pending;
			if ( $step_status->is_complete() ) {
				$decoded                           = json_decode( (string) $step['output'], true );
				$outputs[ (string) $step['step'] ] = is_array( $decoded ) ? $decoded : array();
				continue;
			}
			$next = $step;
			break;
		}

		if ( null === $next ) {
			$this->runs->update_run(
				$run_id,
				array(
					'status'       => RunStatus::Succeeded->value,
					'current_step' => null,
					'finished_at'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$this->logger->info( 'Ablauf abgeschlossen', array( 'run_id' => $run_id ) );
			return;
		}

		$step_id   = (int) $next['id'];
		$step_name = (string) $next['step'];
		$attempt   = $this->runs->start_step( $step_id );

		$this->runs->update_run(
			$run_id,
			array(
				'status'       => RunStatus::Running->value,
				'current_step' => $step_name,
			)
		);

		if ( $attempt > (int) $next['max_attempts'] ) {
			$this->runs->finish_step( $step_id, StepStatus::Failed, null, 'max_attempts', 'Höchstzahl der Versuche erreicht.' );
			$this->fail_run( $run_id, 'max_attempts', 'Schritt ' . $step_name . ': Höchstzahl der Versuche erreicht.' );
			return;
		}

		$input   = json_decode( (string) $run['context'], true );
		$context = new RunContext(
			$run_id,
			(string) $run['uuid'],
			(string) $run['type'],
			$step_name,
			$attempt,
			is_array( $input ) ? $input : array(),
			$outputs
		);

		try {
			$result = $definition->handler( $step_name )->handle( $context );
		} catch ( WaitException $e ) {
			$result = StepResult::wait( $e->wait_code, $e->getMessage() );
		} catch ( RetryableException $e ) {
			$result = StepResult::retry( $e->error_code, $e->getMessage() );
		} catch ( \Throwable $e ) {
			$result = StepResult::fail( 'exception', get_class( $e ) . ': ' . $e->getMessage() );
		}

		$this->apply_result( $run_id, $step_id, $step_name, $attempt, (int) $next['max_attempts'], $result );
	}

	/**
	 * Speichert das Ergebnis und plant den weiteren Verlauf.
	 *
	 * @param int        $run_id       Ablauf-ID.
	 * @param int        $step_id      Schritt-ID.
	 * @param string     $step_name    Schrittname.
	 * @param int        $attempt      Versuch.
	 * @param int        $max_attempts Höchstzahl.
	 * @param StepResult $result       Ergebnis.
	 */
	private function apply_result( int $run_id, int $step_id, string $step_name, int $attempt, int $max_attempts, StepResult $result ): void {
		switch ( $result->outcome ) {
			case StepResult::DONE:
				$this->runs->finish_step( $step_id, StepStatus::Done, $result->output );
				$this->runs->update_run( $run_id, array( 'status' => RunStatus::Queued->value ) );
				$this->queue->enqueue( $run_id );
				return;

			case StepResult::RETRY:
				if ( $attempt < $max_attempts && $this->retry->can_retry( $attempt ) ) {
					$this->runs->finish_step( $step_id, StepStatus::Pending, null, $result->code, $result->message );
					$this->runs->update_run(
						$run_id,
						array(
							'status'        => RunStatus::Queued->value,
							'error_code'    => $result->code,
							'error_message' => $result->message,
						)
					);
					$delay = $this->retry->delay_after( $attempt );
					$this->queue->enqueue_in( $run_id, $delay );
					$this->logger->warning(
						'Schritt wird wiederholt',
						array(
							'run_id'  => $run_id,
							'step'    => $step_name,
							'attempt' => $attempt,
							'delay'   => $delay,
							'code'    => $result->code,
						)
					);
					return;
				}
				$this->runs->finish_step( $step_id, StepStatus::Failed, null, $result->code, $result->message );
				$this->fail_run( $run_id, (string) $result->code, (string) $result->message );
				return;

			case StepResult::WAIT:
				$this->runs->pause_step( $step_id, (string) $result->code, (string) $result->message );
				$this->runs->update_run(
					$run_id,
					array(
						'status'        => RunStatus::Waiting->value,
						'error_code'    => $result->code,
						'error_message' => $result->message,
					)
				);
				$this->logger->info(
					'Ablauf wartet',
					array(
						'run_id' => $run_id,
						'step'   => $step_name,
						'code'   => $result->code,
					)
				);
				return;

			case StepResult::BLOCK:
				$this->runs->finish_step( $step_id, StepStatus::Done, $result->output, $result->code, $result->message );
				$this->runs->update_run(
					$run_id,
					array(
						'status'        => RunStatus::Blocked->value,
						'error_code'    => $result->code,
						'error_message' => $result->message,
						'finished_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->logger->info(
					'Ablauf blockiert',
					array(
						'run_id' => $run_id,
						'code'   => $result->code,
					)
				);
				return;

			default:
				$this->runs->finish_step( $step_id, StepStatus::Failed, null, $result->code, $result->message );
				$this->fail_run( $run_id, (string) $result->code, (string) $result->message );
		}
	}

	/**
	 * Setzt einen wartenden Ablauf fort (von Hand oder durch den Cronjob).
	 *
	 * @param int $run_id Ablauf-ID.
	 * @return bool True, wenn der Ablauf gewartet hat und jetzt eingeplant ist.
	 */
	public function resume( int $run_id ): bool {
		if ( ! $this->runs->release_waiting( $run_id ) ) {
			return false;
		}
		$this->queue->enqueue( $run_id );
		$this->logger->info( 'Ablauf fortgesetzt', array( 'run_id' => $run_id ) );
		return true;
	}

	/**
	 * Setzt alle Abläufe fort, die mit einem Grund warten, sobald die Bedingung erfüllt ist.
	 *
	 * @param string              $code  Grund, z. B. ai_budget.
	 * @param callable(int): bool $ready Prüft je Ablauf, ob es weitergehen kann.
	 * @return list<int> Fortgesetzte Abläufe.
	 */
	public function resume_waiting( string $code, callable $ready ): array {
		$resumed = array();
		foreach ( $this->runs->waiting_run_ids( $code ) as $run_id ) {
			if ( $ready( $run_id ) && $this->resume( $run_id ) ) {
				$resumed[] = $run_id;
			}
		}
		return $resumed;
	}

	/**
	 * Beendet einen Ablauf als fehlgeschlagen.
	 *
	 * @param int    $run_id  Ablauf-ID.
	 * @param string $code    Fehlercode.
	 * @param string $message Meldung.
	 */
	private function fail_run( int $run_id, string $code, string $message ): void {
		$message = $this->logger->redact( $message );
		$this->runs->update_run(
			$run_id,
			array(
				'status'        => RunStatus::Failed->value,
				'error_code'    => $code,
				'error_message' => $message,
				'finished_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->logger->error(
			'Ablauf fehlgeschlagen',
			array(
				'run_id' => $run_id,
				'code'   => $code,
			)
		);
	}
}
