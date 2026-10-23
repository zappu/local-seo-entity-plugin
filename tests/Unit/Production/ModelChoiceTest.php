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
 * Schreibmodell nur für den Text, --model und --writer haben Vorrang.
 */
final class ModelChoiceTest extends TestCase {

	public function test_without_setting_everything_uses_main_model(): void {
		$this->assertNull( ContentProductionWorkflow::model_for( false, array(), '' ) );
		$this->assertNull( ContentProductionWorkflow::model_for( true, array(), '' ) );
	}

	public function test_writing_model_only_for_text(): void {
		$this->assertNull( ContentProductionWorkflow::model_for( false, array(), 'anthropic/claude-sonnet-5.5' ) );
		$this->assertSame( 'anthropic/claude-sonnet-5.5', ContentProductionWorkflow::model_for( true, array(), ' anthropic/claude-sonnet-5.5 ' ) );
	}

	public function test_model_option_applies_to_all_calls(): void {
		$input = array( 'model' => 'openai/gpt-6-luna' );
		$this->assertSame( 'openai/gpt-6-luna', ContentProductionWorkflow::model_for( false, $input, 'anthropic/claude-sonnet-5.5' ) );
		$this->assertSame( 'openai/gpt-6-luna', ContentProductionWorkflow::model_for( true, $input, 'anthropic/claude-sonnet-5.5' ) );
	}

	public function test_writer_option_wins_for_text(): void {
		$input = array(
			'model'  => 'openai/gpt-6-luna',
			'writer' => 'anthropic/claude-sonnet-5.5',
		);
		$this->assertSame( 'openai/gpt-6-luna', ContentProductionWorkflow::model_for( false, $input, '' ) );
		$this->assertSame( 'anthropic/claude-sonnet-5.5', ContentProductionWorkflow::model_for( true, $input, '' ) );
	}
}
