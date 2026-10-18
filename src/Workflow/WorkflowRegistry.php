<?php
/**
 * Verzeichnis der Ablauftypen.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Hält alle registrierten Ablaufdefinitionen.
 */
final class WorkflowRegistry {

	/**
	 * Definitionen nach Typ.
	 *
	 * @var array<string, WorkflowDefinition>
	 */
	private array $definitions = array();

	/**
	 * Registriert einen Ablauftyp.
	 *
	 * @param WorkflowDefinition $definition Definition.
	 */
	public function register( WorkflowDefinition $definition ): void {
		$this->definitions[ $definition->type ] = $definition;
	}

	/**
	 * Gibt es diesen Typ?
	 *
	 * @param string $type Ablauftyp.
	 */
	public function has( string $type ): bool {
		return isset( $this->definitions[ $type ] );
	}

	/**
	 * Definition eines Typs.
	 *
	 * @param string $type Ablauftyp.
	 */
	public function get( string $type ): WorkflowDefinition {
		if ( ! $this->has( $type ) ) {
			throw new \OutOfBoundsException( 'Unbekannter Ablauftyp: ' . $type ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $this->definitions[ $type ];
	}
}
