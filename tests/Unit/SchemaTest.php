<?php
/**
 * Tests für Schema.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit;

use Lsec\Infrastructure\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Die SQL-Anweisungen müssen den Formatregeln von dbDelta() folgen.
 */
final class SchemaTest extends TestCase {

	/**
	 * Anweisungen für ein Testpräfix.
	 *
	 * @return array<string, string>
	 */
	private function statements(): array {
		return Schema::statements( 'wp_', 'DEFAULT CHARSET=utf8mb4' );
	}

	public function test_every_table_has_a_statement(): void {
		$this->assertSame( Schema::TABLES, array_keys( $this->statements() ) );
	}

	public function test_statements_follow_dbdelta_rules(): void {
		foreach ( $this->statements() as $table => $sql ) {
			$this->assertStringStartsWith( 'CREATE TABLE wp_lsec_' . $table . ' (', $sql, $table );
			$this->assertMatchesRegularExpression( '/PRIMARY KEY  \(/', $sql, $table . ': PRIMARY KEY braucht zwei Leerzeichen.' );
			$this->assertStringNotContainsString( '`', $sql, $table . ': keine Backticks.' );
			$this->assertDoesNotMatchRegularExpression( '/\bINDEX\b/', $sql, $table . ': Indizes als KEY.' );
			$this->assertStringEndsWith( 'DEFAULT CHARSET=utf8mb4;', $sql, $table );
		}
	}

	public function test_run_table_enforces_idempotency(): void {
		$this->assertStringContainsString( 'UNIQUE KEY idempotency_key (idempotency_key)', $this->statements()['runs'] );
		$this->assertStringContainsString( 'UNIQUE KEY run_step (run_id,step)', $this->statements()['run_steps'] );
	}

	public function test_relation_edges_are_unique(): void {
		$this->assertStringContainsString( 'UNIQUE KEY edge (source_entity_id,relation_type,target_entity_id)', $this->statements()['relations'] );
	}

	public function test_search_volume_is_nullable(): void {
		// Fehlende Volumina werden als "unbekannt" gespeichert, nie als 0 (Spezifikation 6.6).
		$this->assertStringContainsString( 'search_volume int(10) unsigned NULL', $this->statements()['keyword_metrics'] );
	}
}
