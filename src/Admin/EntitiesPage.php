<?php
/**
 * Backend-Seite für Entitäten.
 *
 * @package Lsec
 */

namespace Lsec\Admin;

use Lsec\Core\Plugin;
use Lsec\Entities\EntityRepository;
use Lsec\Infrastructure\Security\Capabilities;

/**
 * Liste der Entitäten mit Fundstellen; Bestätigen und Ablehnen werden mit Benutzer und Zeit gespeichert
 * und protokolliert (Prüfpunkt M11).
 */
final class EntitiesPage {

	public const SLUG   = 'lsec-entities';
	public const ACTION = 'lsec_entity_decision';

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Registriert Menü und Formularverarbeitung.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Untermenü.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AppPage::SLUG,
			__( 'Entitäten', 'local-seo-entity-plugin' ),
			__( 'Entitäten', 'local-seo-entity-plugin' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Verarbeitet Bestätigen/Ablehnen.
	 */
	public function handle(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'local-seo-entity-plugin' ), 403 );
		}
		$id = isset( $_POST['entity_id'] ) ? absint( $_POST['entity_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$status   = isset( $_POST['status_filter'] ) ? sanitize_key( wp_unslash( $_POST['status_filter'] ) ) : '';

		$done = false;
		if ( in_array( $decision, array( 'confirmed', 'rejected' ), true ) ) {
			$done = ( new EntityRepository() )->decide( $id, $decision, get_current_user_id() );
			if ( $done ) {
				$this->plugin->logger->audit(
					'Entität entschieden',
					array(
						'entity_id' => $id,
						'decision'  => $decision,
						'user_id'   => get_current_user_id(),
						'via'       => 'backend',
					)
				);
			}
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'status'  => $status,
					'decided' => $done ? $id : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Filter und Hinweis, keine Änderung.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$decided = isset( $_GET['decided'] ) ? absint( $_GET['decided'] ) : 0;
		// phpcs:enable
		$repo     = new EntityRepository();
		$entities = $repo->list( '', $status, 200 );
		$labels   = array(
			''           => __( 'Alle', 'local-seo-entity-plugin' ),
			'candidate'  => __( 'Kandidaten', 'local-seo-entity-plugin' ),
			'probable'   => __( 'Wahrscheinlich', 'local-seo-entity-plugin' ),
			'unresolved' => __( 'Ungeklärt', 'local-seo-entity-plugin' ),
			'confirmed'  => __( 'Bestätigt', 'local-seo-entity-plugin' ),
			'rejected'   => __( 'Abgelehnt', 'local-seo-entity-plugin' ),
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Entitäten', 'local-seo-entity-plugin' ) . '</h1>';
		if ( $decided > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: %d: ID */ __( 'Entscheidung für Entität %d gespeichert.', 'local-seo-entity-plugin' ), $decided ) ) . '</p></div>';
		}
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $labels as $key => $label ) {
			$url     = add_query_arg(
				array(
					'page'   => self::SLUG,
					'status' => $key,
				),
				admin_url( 'admin.php' )
			);
			$links[] = '<li><a href="' . esc_url( $url ) . '"' . ( $key === $status ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		echo implode( ' | ', $links ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oben escaped.

		echo '<table class="widefat striped" style="clear:both"><thead><tr>';
		foreach ( array( 'ID', 'Typ', 'Name', 'Status', 'Sicherheit', 'Fundstellen', 'Wikidata', 'Entscheidung' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $entities ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Keine Entitäten. Erkennung: wp lsec entities extract <Beitrags-ID>', 'local-seo-entity-plugin' ) . '</td></tr>';
		}
		foreach ( $entities as $entity ) {
			$id       = (int) $entity['id'];
			$external = json_decode( (string) $entity['external_ids'], true );
			$qid      = is_array( $external ) ? (string) ( $external['wikidata'] ?? '' ) : '';
			$mentions = $repo->mentions( $id );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $id ) . '</td>';
			echo '<td>' . esc_html( (string) $entity['type'] ) . '</td>';
			echo '<td><strong>' . esc_html( (string) $entity['canonical_name'] ) . '</strong>';
			foreach ( array_slice( $mentions, 0, 2 ) as $mention ) {
				echo '<br><small>„' . esc_html( mb_substr( (string) $mention['excerpt'], 0, 120 ) ) . '“ (' . esc_html( (string) $mention['object_type'] . ' ' . (string) $mention['object_id'] ) . ')</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $labels[ (string) $entity['status'] ] ?? (string) $entity['status'] );
			if ( ! empty( $entity['decided_at'] ) ) {
				$user = get_userdata( (int) $entity['decided_by'] );
				echo '<br><small>' . esc_html( ( $user ? $user->display_name : '?' ) . ', ' . get_date_from_gmt( (string) $entity['decided_at'], 'd.m.Y H:i' ) ) . '</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( null === $entity['confidence'] ? '' : number_format_i18n( (float) $entity['confidence'], 2 ) ) . '</td>';
			echo '<td>' . esc_html( (string) count( $mentions ) ) . '</td>';
			echo '<td>' . ( '' !== $qid ? '<a href="' . esc_url( 'https://www.wikidata.org/wiki/' . $qid ) . '" target="_blank" rel="noopener">' . esc_html( $qid ) . '</a>' : '' ) . '</td>';
			echo '<td>';
			foreach ( array(
				'confirmed' => __( 'Bestätigen', 'local-seo-entity-plugin' ),
				'rejected'  => __( 'Ablehnen', 'local-seo-entity-plugin' ),
			) as $decision => $label ) {
				if ( $decision === $entity['status'] ) {
					continue;
				}
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:4px">';
				wp_nonce_field( self::ACTION . '_' . $id );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
				echo '<input type="hidden" name="entity_id" value="' . esc_attr( (string) $id ) . '">';
				echo '<input type="hidden" name="decision" value="' . esc_attr( $decision ) . '">';
				echo '<input type="hidden" name="status_filter" value="' . esc_attr( $status ) . '">';
				submit_button( $label, 'confirmed' === $decision ? 'primary small' : 'secondary small', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
