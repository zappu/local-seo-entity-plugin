<?php
/**
 * Bericht eines Ablaufs content_production.
 *
 * @package Lsec
 */

namespace Lsec\Production;

use Lsec\Core\Plugin;
use Lsec\Integrations\AI\AiCallRepository;

/**
 * Gemeinsame Daten für WP-CLI und Oberfläche: Status, Beitrag, Kosten je Aufruf, Prüfergebnis
 * (Grundlage für „Kosten je Beitrag gemessen“ und den Modellvergleich in Stufe E).
 */
final class ProductionReport {

	/**
	 * Bericht.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param int    $id     Ablauf.
	 * @return array<string, mixed>|null
	 */
	public static function for_run( Plugin $plugin, int $id ): ?array {
		$run = $plugin->runs->find( $id );
		if ( null === $run || ContentProductionWorkflow::TYPE !== $run['type'] ) {
			return null;
		}
		$input   = json_decode( (string) $run['context'], true );
		$outputs = array();
		foreach ( $plugin->runs->steps( $id ) as $step ) {
			$decoded                           = json_decode( (string) $step['output'], true );
			$outputs[ (string) $step['step'] ] = is_array( $decoded ) ? $decoded : array();
		}
		$quality = $outputs['quality_check'] ?? array();
		$report  = isset( $quality['report_id'] ) ? ( new QualityReportRepository() )->find( (int) $quality['report_id'] ) : null;
		$repo    = new AiCallRepository();
		$calls   = $repo->for_run( $id );
		$post_id = (int) ( $outputs['create_post']['post_id'] ?? 0 );
		return array(
			'run'        => $id,
			'status'     => (string) $run['status'],
			'step'       => (string) $run['current_step'],
			'error'      => $plugin->logger->redact( (string) $run['error_message'] ),
			'topic'      => (int) ( is_array( $input ) ? ( $input['topic_id'] ?? 0 ) : 0 ),
			'created_at' => gmdate( 'c', (int) strtotime( (string) $run['created_at'] . ' UTC' ) ),
			'model'      => implode( ', ', array_values( array_unique( array_map( static fn( array $c ): string => (string) $c['model'], $calls ) ) ) ),
			'cost_usd'   => round( $repo->cost_of_run( $id ), 4 ),
			'post_id'    => $post_id,
			'edit_url'   => $post_id > 0 ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
			'preview'    => $post_id > 0 ? (string) get_preview_post_link( $post_id ) : '',
			'title'      => (string) ( $outputs['enrich']['draft']['title'] ?? '' ),
			'words'      => $report['words'] ?? null,
			'score'      => $report['score'] ?? null,
			'passed'     => $report['passed'] ?? null,
			'scores'     => $report['review'] ?? null,
			'checks'     => $report['checks'] ?? array(),
			'blocking'   => $report['blocking'] ?? array(),
			'warnings'   => $report['warnings'] ?? array(),
			'dropped'    => array_merge( (array) ( $outputs['create_brief']['dropped'] ?? array() ), (array) ( $outputs['generate_draft']['dropped'] ?? array() ) ),
			'notes'      => (array) ( $outputs['enrich']['notes'] ?? array() ),
			'calls'      => array_map(
				static fn( array $c ): array => array(
					'task'  => (string) $c['task'],
					'model' => (string) $c['model'],
					'in'    => (int) $c['input_tokens'],
					'out'   => (int) $c['output_tokens'],
					'cost'  => null === $c['cost_estimate'] ? null : round( (float) $c['cost_estimate'], 4 ),
				),
				$calls
			),
		);
	}

	/**
	 * Neuester Ablauf content_production eines Themas.
	 *
	 * @param int $topic_id Thema.
	 */
	public static function latest_run_id( int $topic_id ): int {
		global $wpdb;
		$table = \Lsec\Infrastructure\Database\Schema::table( $wpdb->prefix, 'runs' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND subject_type = 'topic' AND subject_id = %d ORDER BY id DESC LIMIT 1", ContentProductionWorkflow::TYPE, $topic_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
