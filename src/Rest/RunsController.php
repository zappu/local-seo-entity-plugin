<?php
/**
 * REST: Seite „Abläufe“ (Stufe F).
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Core\Plugin;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Workflow\RunStatus;

/**
 * GET lsec/v1/runs (Liste mit Filtern), GET lsec/v1/runs/<id> (Schritte, KI-Aufrufe, Fehler).
 * Fortsetzen und Abbrechen: POST lsec/v1/runs/<id>/resume|cancel (RestController).
 */
final class RunsController {

	/**
	 * Abläufe je Seite.
	 */
	public const PER_PAGE = 25;

	/**
	 * Deutsche Bezeichnungen der Ablauftypen.
	 */
	public const TYPES = array(
		'content_index'      => 'Inhaltsindex',
		'entity_extraction'  => 'Entity-Erkennung',
		'keyword_sync'       => 'Keyword-Abgleich',
		'topic_research'     => 'Themenrecherche',
		'content_production' => 'Beitrag',
		'image_description'  => 'Bildbeschreibung',
		'selftest'           => 'Selbsttest',
	);

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Routen.
	 *
	 * @param callable $permission Berechtigung.
	 */
	public function register( callable $permission ): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/runs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'detail' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Liste.
	 *
	 * @param \WP_REST_Request $request Anfrage mit status, type, page.
	 */
	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$status = (string) $request->get_param( 'status' );
		$status = null === RunStatus::tryFrom( $status ) ? '' : $status;
		$type   = (string) $request->get_param( 'type' );
		$type   = isset( self::TYPES[ $type ] ) ? $type : '';
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$found  = $this->plugin->runs->search( $status, $type, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$calls  = new AiCallRepository();
		$items  = array();
		foreach ( $found['rows'] as $run ) {
			$items[] = $this->item( $run, $calls );
		}
		return new \WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $found['total'],
				'page'     => $page,
				'per_page' => self::PER_PAGE,
				'types'    => $found['types'],
				'labels'   => self::TYPES,
			)
		);
	}

	/**
	 * Einzelheiten eines Ablaufs.
	 *
	 * @param \WP_REST_Request $request Anfrage mit id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id  = (int) $request['id'];
		$run = $this->plugin->runs->find( $id );
		if ( null === $run ) {
			return new \WP_Error( 'lsec_not_found', 'Ablauf nicht gefunden.', array( 'status' => 404 ) );
		}
		$calls = new AiCallRepository();
		$steps = array();
		$post  = 0;
		foreach ( $this->plugin->runs->steps( $id ) as $step ) {
			$output = json_decode( (string) $step['output'], true );
			if ( 'create_post' === (string) $step['step'] && is_array( $output ) ) {
				$post = (int) ( $output['post_id'] ?? 0 );
			}
			$text    = is_array( $output ) ? (string) wp_json_encode( $output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) : (string) $step['output'];
			$steps[] = array(
				'step'          => (string) $step['step'],
				'status'        => (string) $step['status'],
				'attempt'       => (int) $step['attempt'],
				'started_at'    => self::iso( (string) ( $step['started_at'] ?? '' ) ),
				'finished_at'   => self::iso( (string) ( $step['finished_at'] ?? '' ) ),
				'error_code'    => (string) ( $step['error_code'] ?? '' ),
				'error_message' => $this->plugin->logger->redact( (string) ( $step['error_message'] ?? '' ) ),
				'output'        => mb_substr( $this->plugin->logger->redact( $text ), 0, 4000 ),
			);
		}
		$ai = array();
		foreach ( $calls->for_run( $id ) as $call ) {
			$ai[] = array(
				'task'   => (string) $call['task'],
				'model'  => (string) $call['model'],
				'status' => (string) $call['status'],
				'in'     => (int) $call['input_tokens'],
				'out'    => (int) $call['output_tokens'],
				'cost'   => null === $call['cost_estimate'] ? null : round( (float) $call['cost_estimate'], 4 ),
				'ms'     => (int) $call['duration_ms'],
			);
		}
		$item = $this->item( $run, $calls );
		if ( 0 === $post && 'post' === (string) $run['subject_type'] ) {
			$post = (int) $run['subject_id'];
		}
		$item['post_edit'] = $post > 0 ? (string) get_edit_post_link( $post, 'raw' ) : '';
		return new \WP_REST_Response(
			array(
				'run'   => $item,
				'steps' => $steps,
				'calls' => $ai,
			)
		);
	}

	/**
	 * Zeile eines Ablaufs.
	 *
	 * @param array<string, mixed> $run   Ablauf.
	 * @param AiCallRepository     $calls KI-Aufrufe.
	 * @return array<string, mixed>
	 */
	private function item( array $run, AiCallRepository $calls ): array {
		$status = RunStatus::tryFrom( (string) $run['status'] );
		return array(
			'id'            => (int) $run['id'],
			'type'          => (string) $run['type'],
			'type_label'    => self::TYPES[ (string) $run['type'] ] ?? (string) $run['type'],
			'subject'       => self::subject( (string) $run['subject_type'], (int) $run['subject_id'] ),
			'status'        => (string) $run['status'],
			'terminal'      => null !== $status && $status->is_terminal(),
			'step'          => (string) $run['current_step'],
			'cost'          => round( $calls->cost_of_run( (int) $run['id'] ), 4 ),
			'error_code'    => (string) $run['error_code'],
			'error_message' => mb_substr( $this->plugin->logger->redact( (string) $run['error_message'] ), 0, 300 ),
			'created_at'    => self::iso( (string) $run['created_at'] ),
			'updated_at'    => self::iso( (string) $run['updated_at'] ),
			'finished_at'   => self::iso( (string) ( $run['finished_at'] ?? '' ) ),
		);
	}

	/**
	 * Gegenstand als Text: „Thema 4: …“ oder „Beitrag 159: …“.
	 *
	 * @param string $type Typ.
	 * @param int    $id   ID.
	 */
	private static function subject( string $type, int $id ): string {
		global $wpdb;
		if ( '' === $type || $id <= 0 ) {
			return '';
		}
		if ( 'topic' === $type ) {
			$table = Schema::table( $wpdb->prefix, 'topics' );
			$title = (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return 'Thema ' . $id . ( '' !== $title ? ': ' . $title : '' );
		}
		if ( 'post' === $type ) {
			$title = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			return 'Inhalt ' . $id . ( '' !== $title ? ': ' . $title : '' );
		}
		return $type . ' ' . $id;
	}

	/**
	 * Zeit (UTC aus der Datenbank) als ISO 8601.
	 *
	 * @param string $value Datenbankwert.
	 */
	private static function iso( string $value ): string {
		return '' === $value || str_starts_with( $value, '0000' ) ? '' : gmdate( 'c', (int) strtotime( $value . ' UTC' ) );
	}
}
