<?php
/**
 * Links des Plugins in den Listen der Beiträge, Seiten und Produkte.
 *
 * @package Lsec
 */

namespace Lsec\Admin;

use Lsec\Content\ContentIndex;
use Lsec\Content\IndexItem;
use Lsec\Core\Plugin;
use Lsec\Entities\EntityExtractionWorkflow;
use Lsec\Infrastructure\Security\Capabilities;
use Lsec\Media\FeaturedImage;
use Lsec\Media\ImageLibrary;

/**
 * „Beitragsbild suchen“ (Beiträge ohne Beitragsbild) und „Entitäten erkennen“ (Beiträge, Seiten, Produkte)
 * in der Zeile unter dem Titel. Ersetzt wp lsec images assign und wp lsec entities extract --queue.
 * Nachtrag 24.10.2026 (0.11.0).
 */
final class PostActions {

	public const ACTION = 'lsec_post_tool';

	private const NOTICE = 'lsec_post_tool_notice_';

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Registriert Filter und Handler.
	 */
	public function register(): void {
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Links unter dem Titel.
	 *
	 * @param array<string, string> $actions Vorhandene Links.
	 * @param \WP_Post              $post    Beitrag.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( ! Capabilities::current_user_can_manage() || 'trash' === $post->post_status ) {
			return $actions;
		}
		if ( 'post' === $post->post_type && ! has_post_thumbnail( $post ) ) {
			$actions['lsec_image'] = sprintf( '<a href="%s">%s</a>', esc_url( self::url( 'image', $post->ID ) ), esc_html__( 'Beitragsbild suchen', 'local-seo-entity-plugin' ) );
		}
		if ( in_array( $post->post_type, array( 'post', 'page', 'product' ), true ) && 'publish' === $post->post_status ) {
			$actions['lsec_entities'] = sprintf( '<a href="%s">%s</a>', esc_url( self::url( 'entities', $post->ID ) ), esc_html__( 'Entitäten erkennen', 'local-seo-entity-plugin' ) );
		}
		return $actions;
	}

	/**
	 * Adresse einer Aktion mit Nonce.
	 *
	 * @param string $tool    image oder entities.
	 * @param int    $post_id Beitrag.
	 */
	public static function url( string $tool, int $post_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&tool=' . $tool . '&post=' . $post_id ), self::ACTION . '_' . $tool . '_' . $post_id );
	}

	/**
	 * Führt die Aktion aus und kehrt zur Liste zurück.
	 */
	public function handle(): void {
		$tool    = isset( $_GET['tool'] ) ? sanitize_key( wp_unslash( $_GET['tool'] ) ) : '';
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $tool . '_' . $post_id );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'local-seo-entity-plugin' ), 403 );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			wp_die( esc_html__( 'Beitrag nicht gefunden.', 'local-seo-entity-plugin' ), 404 );
		}
		try {
			$notice = 'image' === $tool ? $this->image( $post ) : $this->entities( $post );
		} catch ( \Throwable $e ) {
			$notice = array(
				'ok'      => false,
				'message' => $this->plugin->logger->redact( $e->getMessage() ),
			);
		}
		set_transient( self::NOTICE . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		$back = wp_get_referer();
		wp_safe_redirect( false !== $back ? $back : admin_url( 'edit.php?post_type=' . $post->post_type ) );
		exit;
	}

	/**
	 * Beitragsbild: zuerst offene Bilder der Mediathek beschreiben (höchstens zehn), dann wählen.
	 *
	 * @param \WP_Post $post Beitrag.
	 * @return array{ok: bool, message: string}
	 */
	private function image( \WP_Post $post ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$pending = ImageLibrary::pending_ids( ImageLibrary::BATCH );
		if ( array() !== $pending ) {
			$plugin   = $this->plugin;
			$ai       = ImageLibrary::lazy( static fn() => \Lsec\Media\ImageDescriptionWorkflow::vision( $plugin ) );
			$business = ImageLibrary::business();
			foreach ( $pending as $id ) {
				ImageLibrary::describe( (int) $id, $ai, $business );
			}
		}
		$result = FeaturedImage::assign( $this->plugin, $post->ID, null );
		$this->plugin->logger->audit(
			'Beitragsbild gesetzt',
			array(
				'post_id'  => $post->ID,
				'source'   => $result['source'],
				'image_id' => $result['image_id'],
				'user_id'  => get_current_user_id(),
			)
		);
		$title = '„' . get_the_title( $post ) . '“';
		if ( $result['image_id'] > 0 ) {
			return array(
				'ok'      => true,
				'message' => sprintf( 'Beitragsbild für %s gesetzt (%s). %s', $title, 'generated' === $result['source'] ? 'KI-Bild' : 'aus der Mediathek', $result['note'] ),
			);
		}
		return array(
			'ok'      => false,
			'message' => sprintf( 'Kein Beitragsbild für %s: %s', $title, $result['note'] ),
		);
	}

	/**
	 * Entity-Erkennung als Ablauf einplanen.
	 *
	 * @param \WP_Post $post Beitrag.
	 * @return array{ok: bool, message: string}
	 */
	private function entities( \WP_Post $post ): array {
		$item    = IndexItem::from_post( $post );
		$content = $item->read();
		$run     = EntityExtractionWorkflow::start_item( $this->plugin, $item, ContentIndex::hash( $item->title(), $content['text'] ), array( 'manual' => gmdate( 'c' ) ) );
		return array(
			'ok'      => true,
			'message' => sprintf( 'Entity-Erkennung für „%s“ eingeplant (Ablauf %d, etwa 0,04 USD). Die Vorschläge stehen nach dem nächsten Cronjob auf der Seite „Entitäten“.', get_the_title( $post ), (int) $run['run_id'] ),
		);
	}

	/**
	 * Meldung nach der Rückkehr zur Liste.
	 */
	public function notice(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p><strong>Local SEO:</strong> %s</p></div>', ! empty( $notice['ok'] ) ? 'success' : 'warning', esc_html( (string) $notice['message'] ) );
	}
}
