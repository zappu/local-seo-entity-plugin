<?php
/**
 * Modellwahl je Aufruf (0.7.9).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Production;

use Lsec\Production\ContentProductionWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * Schreib- und Prüfmodell je Aufgabe; --writer, --model und das Prüfmodell des Befehls review haben Vorrang.
 */
final class ModelChoiceTest extends TestCase {

	private const SETTINGS = array(
		'draft'  => 'anthropic/claude-sonnet-5.5',
		'review' => 'openai/gpt-6-luna',
	);

	public function test_without_settings_everything_uses_main_model(): void {
		foreach ( array( 'brief', 'draft', 'review' ) as $task ) {
			$this->assertNull( ContentProductionWorkflow::model_for( $task, array(), array() ) );
		}
	}

	public function test_settings_apply_per_task(): void {
		$this->assertNull( ContentProductionWorkflow::model_for( 'brief', array(), self::SETTINGS ) );
		$this->assertSame( 'anthropic/claude-sonnet-5.5', ContentProductionWorkflow::model_for( 'draft', array(), self::SETTINGS ) );
		$this->assertSame( 'openai/gpt-6-luna', ContentProductionWorkflow::model_for( 'review', array(), array( 'review' => ' openai/gpt-6-luna ' ) ) );
	}

	public function test_model_option_applies_to_all_calls(): void {
		$input = array( 'model' => 'google/gemini-3-pro' );
		foreach ( array( 'brief', 'draft', 'review' ) as $task ) {
			$this->assertSame( 'google/gemini-3-pro', ContentProductionWorkflow::model_for( $task, $input, self::SETTINGS ) );
		}
	}

	public function test_own_options_win(): void {
		$input = array(
			'model'    => 'google/gemini-3-pro',
			'writer'   => 'anthropic/claude-sonnet-5.5',
			'reviewer' => 'openai/gpt-6-luna',
		);
		$this->assertSame( 'google/gemini-3-pro', ContentProductionWorkflow::model_for( 'brief', $input, array() ) );
		$this->assertSame( 'anthropic/claude-sonnet-5.5', ContentProductionWorkflow::model_for( 'draft', $input, array() ) );
		$this->assertSame( 'openai/gpt-6-luna', ContentProductionWorkflow::model_for( 'review', $input, array() ) );
	}
}
