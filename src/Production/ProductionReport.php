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
		$revised = ! empty( $outputs['revise']['revised'] );
		$quality = $revised ? $outputs['revise'] : ( $outputs['quality_check'] ?? array() );
		$report  = isset( $quality['report_id'] ) ? ( new QualityReportRepository() )->find( (int) $quality['report_id'] ) : null;
		$repo    = new AiCallRepository();
		$calls   = $repo->for_run( $id );
		$post_id = (int) ( $outputs['create_post']['post_id'] ?? 0 );
		$facts   = array_merge( (array) ( $outputs['gather_context']['claims'] ?? array() ), (array) ( $outputs['place_facts']['claims'] ?? array() ) );
		$region  = (string) ( $outputs['gather_context']['topic']['region'] ?? '' );
		$local   = static fn( array $c ): bool => '' !== $region && \Lsec\Entities\PlaceName::about( $c, $region );
		$draft   = $revised ? (array) $outputs['revise']['draft'] : (array) ( $outputs['enrich']['draft'] ?? array() );
		$used    = array_map( 'intval', (array) ( $draft['used_claim_ids'] ?? array() ) );
		$in_text = array_filter( $facts, static fn( array $c ): bool => in_array( (int) $c['id'], $used, true ) );
		return array(
			'run'                 => $id,
			'status'              => (string) $run['status'],
			'step'                => (string) $run['current_step'],
			'error'               => $plugin->logger->redact( (string) $run['error_message'] ),
			'topic'               => (int) ( is_array( $input ) ? ( $input['topic_id'] ?? 0 ) : 0 ),
			'created_at'          => gmdate( 'c', (int) strtotime( (string) $run['created_at'] . ' UTC' ) ),
			'model'               => implode( ', ', array_values( array_unique( array_map( static fn( array $c ): string => (string) $c['model'], $calls ) ) ) ),
			'cost_usd'            => round( $repo->cost_of_run( $id ), 4 ),
			'company_description' => '' !== (string) ( $outputs['gather_context']['company']['description'] ?? '' ),
			'own_pages'           => array_values( array_map( static fn( array $p ): string => (string) $p['title'], (array) ( $outputs['gather_context']['own_pages'] ?? array() ) ) ),
			'services'            => array_values( array_map( static fn( array $d ): string => (string) $d['name'], (array) ( $outputs['gather_context']['company']['service_details'] ?? array() ) ) ),
			'facts'               => array(
				'context'    => count( $facts ),
				'local'      => count( array_filter( $facts, $local ) ),
				'used'       => count( $in_text ),
				'used_local' => count( array_filter( $in_text, $local ) ),
				'region'     => (string) ( $outputs['gather_context']['topic']['region'] ?? '' ),
				'format'     => (string) ( $outputs['gather_context']['topic']['format'] ?? '' ),
			),
			'unpriced'            => count( array_filter( $calls, static fn( array $c ): bool => null === $c['cost_estimate'] && 'ok' === (string) $c['status'] ) ),
			'post_id'             => $post_id,
			'edit_url'            => $post_id > 0 ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
			'preview'             => $post_id > 0 ? (string) get_preview_post_link( $post_id ) : '',
			'title'               => (string) ( $draft['title'] ?? '' ),
			'place_research'      => array(
				'researched' => ! empty( $outputs['place_facts']['researched'] ),
				'reason'     => (string) ( $outputs['place_facts']['reason'] ?? '' ),
				'counts'     => (array) ( $outputs['place_facts']['counts'] ?? array() ),
				'facts'      => (array) ( $outputs['place_facts']['facts'] ?? array() ),
			),
			'revision'            => array(
				'revised' => $revised,
				'reason'  => (string) ( $outputs['revise']['reason'] ?? '' ),
				'before'  => (array) ( $outputs['revise']['before'] ?? array() ),
				'applied' => (array) ( $outputs['revise']['applied'] ?? array() ),
				'missed'  => (array) ( $outputs['revise']['missed'] ?? array() ),
			),
			'words'               => $report['words'] ?? null,
			'score'               => $report['score'] ?? null,
			'passed'              => $report['passed'] ?? null,
			'scores'              => $report['review'] ?? null,
			'checks'              => $report['checks'] ?? array(),
			'blocking'            => $report['blocking'] ?? array(),
			'warnings'            => $report['warnings'] ?? array(),
			'dropped'             => array_merge( (array) ( $outputs['create_brief']['dropped'] ?? array() ), (array) ( $outputs['generate_draft']['dropped'] ?? array() ) ),
			'notes'               => (array) ( $outputs['enrich']['notes'] ?? array() ),
			'calls'               => array_map(
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
