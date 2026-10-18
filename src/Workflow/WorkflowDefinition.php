<?php
/**
 * Definition eines Ablauftyps.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Reihenfolge der Schritte und ihre Handler.
 */
final class WorkflowDefinition {

	/**
	 * Konstruktor.
	 *
	 * @param string                     $type     Ablauftyp.
	 * @param array<string, StepHandler> $handlers Schrittname => Handler, in Ausführungsreihenfolge.
	 * @param int                        $lock_ttl Sperrdauer in Sekunden; muss länger sein als der längste Schritt.
	 */
	public function __construct(
		public readonly string $type,
		private readonly array $handlers,
		public readonly int $lock_ttl = 600
	) {
		if ( array() === $handlers ) {
			throw new \InvalidArgumentException( 'Ein Ablauf braucht mindestens einen Schritt.' );
		}
	}

	/**
	 * Schrittnamen in Reihenfolge.
	 *
	 * @return list<string>
	 */
	public function steps(): array {
		return array_keys( $this->handlers );
	}

	/**
	 * Handler eines Schritts.
	 *
	 * @param string $step Schrittname.
	 */
	public function handler( string $step ): StepHandler {
		if ( ! isset( $this->handlers[ $step ] ) ) {
			throw new \OutOfBoundsException( 'Unbekannter Schritt: ' . $step ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $this->handlers[ $step ];
	}
}
