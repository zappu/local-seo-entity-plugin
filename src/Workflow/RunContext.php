<?php
/**
 * Kontext eines laufenden Schritts.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Was ein StepHandler über den Ablauf wissen darf.
 */
final class RunContext {

	/**
	 * Konstruktor.
	 *
	 * @param int                                 $run_id   Interne Ablauf-ID.
	 * @param string                              $run_uuid Stabile Ablauf-UUID.
	 * @param string                              $type     Ablauftyp.
	 * @param string                              $step     Name des aktuellen Schritts.
	 * @param int                                 $attempt  Aktueller Versuch, ab 1.
	 * @param array<string, mixed>                $input    Kontext, mit dem der Ablauf gestartet wurde.
	 * @param array<string, array<string, mixed>> $outputs  Ergebnisse der bereits abgeschlossenen Schritte.
	 * @param array<string, mixed>                $state    Zwischenstand eines Schritts, der in Durchgängen läuft (StepResult::more).
	 */
	public function __construct(
		public readonly int $run_id,
		public readonly string $run_uuid,
		public readonly string $type,
		public readonly string $step,
		public readonly int $attempt,
		public readonly array $input,
		public readonly array $outputs,
		public readonly array $state = array()
	) {}

	/**
	 * Ergebnis eines früheren Schritts.
	 *
	 * @param string $step Name des Schritts.
	 * @return array<string, mixed>
	 */
	public function output_of( string $step ): array {
		return $this->outputs[ $step ] ?? array();
	}
}
