<?php
/**
 * Tests für Ablaufdefinitionen und Status.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit;

use Lsec\Workflow\RunContext;
use Lsec\Workflow\RunStatus;
use Lsec\Workflow\StepHandler;
use Lsec\Workflow\StepResult;
use Lsec\Workflow\StepStatus;
use Lsec\Workflow\WorkflowDefinition;
use Lsec\Workflow\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Reihenfolge der Schritte, Registry und Statuslogik.
 */
final class WorkflowDefinitionTest extends TestCase {

	/**
	 * Einfacher Handler.
	 */
	private function handler(): StepHandler {
		return new class() implements StepHandler {
			/**
			 * Gibt immer "erledigt" zurück.
			 *
			 * @param RunContext $context Kontext.
			 */
			public function handle( RunContext $context ): StepResult {
				return StepResult::done( array( 'step' => $context->step ) );
			}
		};
	}

	public function test_steps_keep_order(): void {
		$def = new WorkflowDefinition(
			'demo',
			array(
				'a' => $this->handler(),
				'b' => $this->handler(),
				'c' => $this->handler(),
			)
		);
		$this->assertSame( array( 'a', 'b', 'c' ), $def->steps() );
		$this->assertSame( 600, $def->lock_ttl );
	}

	public function test_empty_definition_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new WorkflowDefinition( 'leer', array() );
	}

	public function test_unknown_step_and_type_throw(): void {
		$def = new WorkflowDefinition( 'demo', array( 'a' => $this->handler() ) );
		$reg = new WorkflowRegistry();
		$reg->register( $def );
		$this->assertTrue( $reg->has( 'demo' ) );
		$this->assertSame( $def, $reg->get( 'demo' ) );

		$this->expectException( \OutOfBoundsException::class );
		$reg->get( 'gibt-es-nicht' );
	}

	public function test_run_status_rules(): void {
		$this->assertTrue( RunStatus::Succeeded->is_terminal() );
		$this->assertTrue( RunStatus::Blocked->is_terminal() );
		$this->assertFalse( RunStatus::Queued->is_terminal() );
		$this->assertTrue( RunStatus::Running->is_resumable() );
		$this->assertFalse( RunStatus::Waiting->is_resumable() );
	}

	public function test_step_status_completion(): void {
		$this->assertTrue( StepStatus::Done->is_complete() );
		$this->assertTrue( StepStatus::Skipped->is_complete() );
		$this->assertFalse( StepStatus::Running->is_complete() );
		$this->assertFalse( StepStatus::Failed->is_complete() );
	}

	public function test_context_returns_previous_outputs(): void {
		$ctx = new RunContext( 1, 'uuid', 'demo', 'b', 1, array(), array( 'a' => array( 'value' => 42 ) ) );
		$this->assertSame( 42, $ctx->output_of( 'a' )['value'] );
		$this->assertSame( array(), $ctx->output_of( 'fehlt' ) );
	}
}
