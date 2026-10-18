<?php
/**
 * Selbsttest-Ablauf für den Machbarkeitstest.
 *
 * @package Lsec
 */

namespace Lsec\Workflow\Selftest;

use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Integrations\AI\AiClientFactory;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiService;
use Lsec\Integrations\AI\PriceTable;
use Lsec\Workflow\RunContext;
use Lsec\Workflow\StepHandler;
use Lsec\Workflow\StepResult;
use Lsec\Workflow\WorkflowDefinition;

/**
 * Vier Schritte, die Wiederaufnahme (M4) und Doppelschutz (M5) prüfbar machen:
 * prepare → compute → create_post → finalize.
 *
 * Jeder Schritt zählt seine Ausführungen in der Option lsec_selftest_counters.
 * Über den Startkontext "crash_at" lässt sich ein Prozessabbruch simulieren:
 * - "compute": Abbruch beim ersten Versuch von compute.
 * - "create_post": Abbruch direkt nach dem Anlegen des Beitrags, bevor der Schritt
 *   als erledigt gespeichert ist. Die Wiederholung darf keinen zweiten Beitrag anlegen.
 * Der Abbruch funktioniert nur in WP-CLI, damit er nie eine Webanfrage beendet.
 *
 * Mit "ai_calls" und "ai_cost" macht compute KI-Aufrufe über eine Attrappe mit festen Kosten.
 * Daran wird das KI-Budget geprüft: Ist es erreicht, wartet der Ablauf (Spezifikation 8.1b).
 */
final class SelftestWorkflow {

	public const TYPE        = 'selftest';
	public const COUNTER_OPT = 'lsec_selftest_counters';
	public const META_RUN    = '_lsec_run_uuid';

	/**
	 * Definition mit kurzer Sperrdauer, damit der Test nach einem Abbruch schnell weiterlaufen kann.
	 *
	 * @param int $lock_ttl Sperrdauer in Sekunden.
	 */
	public static function definition( int $lock_ttl = 10 ): WorkflowDefinition {
		return new WorkflowDefinition(
			self::TYPE,
			array(
				'prepare'     => new class() implements StepHandler {
					/**
					 * Schritt 1.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						SelftestWorkflow::count( $context );
						return StepResult::done( array( 'prepared_at' => gmdate( 'c' ) ) );
					}
				},
				'compute'     => new class() implements StepHandler {
					/**
					 * Schritt 2.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						SelftestWorkflow::count( $context );
						SelftestWorkflow::maybe_crash( $context, 'compute' );
						$calls = (int) ( $context->input['ai_calls'] ?? 0 );
						if ( $calls > 0 ) {
							$ai = new AiService( new SelftestAiClient( (float) ( $context->input['ai_cost'] ?? 0.1 ) ), new AiCallRepository(), new PriceTable(), AiClientFactory::budget() );
							for ( $i = 0; $i < $calls; $i++ ) {
								$ai->text( new AiRequest( 'selftest_budget', 'Selbsttest', 'Aufruf ' . ( $i + 1 ) ), $context->run_id );
							}
						}
						if ( ! empty( $context->input['retry_once'] ) && 1 === $context->attempt ) {
							return StepResult::retry( 'selftest_retry', 'Absichtlicher vorübergehender Fehler.' );
						}
						return StepResult::done( array( 'value' => 42 ) );
					}
				},
				'create_post' => new class() implements StepHandler {
					/**
					 * Schritt 3: legt genau einen Entwurf je Ablauf an.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						SelftestWorkflow::count( $context );
						$post_id = SelftestWorkflow::find_post( $context->run_uuid );
						if ( 0 === $post_id ) {
							$post_id = wp_insert_post(
								array(
									'post_title'  => 'LSEC Selbsttest ' . $context->run_uuid,
									'post_status' => 'draft',
									'post_type'   => 'post',
									'meta_input'  => array( SelftestWorkflow::META_RUN => $context->run_uuid ),
								),
								true
							);
							if ( is_wp_error( $post_id ) ) {
								return StepResult::fail( 'insert_post', $post_id->get_error_message() );
							}
						}
						SelftestWorkflow::maybe_crash( $context, 'create_post' );
						return StepResult::done( array( 'post_id' => (int) $post_id ) );
					}
				},
				'finalize'    => new class() implements StepHandler {
					/**
					 * Schritt 4.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						SelftestWorkflow::count( $context );
						return StepResult::done(
							array(
								'value'   => $context->output_of( 'compute' )['value'] ?? null,
								'post_id' => $context->output_of( 'create_post' )['post_id'] ?? null,
							)
						);
					}
				},
			),
			$lock_ttl
		);
	}

	/**
	 * Zählt eine Ausführung.
	 *
	 * @param RunContext $context Kontext.
	 */
	public static function count( RunContext $context ): void {
		$counters = get_option( self::COUNTER_OPT, array() );
		$counters = is_array( $counters ) ? $counters : array();

		$counters[ $context->run_id ][ $context->step ] = (int) ( $counters[ $context->run_id ][ $context->step ] ?? 0 ) + 1;
		update_option( self::COUNTER_OPT, $counters, false );
	}

	/**
	 * Simuliert einen Prozessabbruch beim ersten Versuch eines Schritts.
	 *
	 * @param RunContext $context Kontext.
	 * @param string     $where   Schrittname.
	 */
	public static function maybe_crash( RunContext $context, string $where ): void {
		if ( ( $context->input['crash_at'] ?? '' ) !== $where || 1 !== $context->attempt ) {
			return;
		}
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// Simulierter Absturz: Prozess endet ohne Aufräumen, Sperre und Schrittstatus bleiben stehen.
		exit( 3 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Beitrag dieses Ablaufs.
	 *
	 * @param string $run_uuid Ablauf-UUID.
	 */
	public static function find_post( string $run_uuid ): int {
		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'meta_key'         => self::META_RUN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'       => $run_uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		return array() === $ids ? 0 : (int) $ids[0];
	}

	/**
	 * Alle Beiträge dieses Ablaufs (für die Prüfung auf Dubletten).
	 *
	 * @param string $run_uuid Ablauf-UUID.
	 * @return list<int>
	 */
	public static function all_posts( string $run_uuid ): array {
		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'meta_key'         => self::META_RUN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'       => $run_uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		return array_map( 'intval', $ids );
	}
}
