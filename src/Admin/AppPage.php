<?php
/**
 * Seiten der Backend-Oberfläche (React).
 *
 * @package Lsec
 */

namespace Lsec\Admin;

use Lsec\Core\Settings;
use Lsec\Infrastructure\Security\Capabilities;

/**
 * Menü „Local SEO“ mit Dashboard und Einstellungen. Beide Seiten laden dieselbe React-App
 * aus assets/dist (gebaut mit @wordpress/scripts) und zeigen je nach data-view eine Ansicht.
 */
final class AppPage {

	public const SLUG          = 'lsec';
	public const SETTINGS_SLUG = 'lsec-settings';
	public const PROFILE_SLUG  = 'lsec-profile';
	public const ENTITIES_SLUG = 'lsec-entities';
	public const KEYWORDS_SLUG = 'lsec-keywords';

	/**
	 * Seiten-Slugs und ihre Ansicht.
	 */
	private const VIEWS = array(
		self::SLUG          => 'dashboard',
		self::PROFILE_SLUG  => 'profile',
		self::ENTITIES_SLUG => 'entities',
		self::KEYWORDS_SLUG => 'keywords',
		self::SETTINGS_SLUG => 'settings',
	);

	/**
	 * Registriert Menü und Skripte.
	 */
	public function register(): void {
		// Vor den übrigen Seiten, damit das Hauptmenü steht, wenn sie sich als Unterseiten eintragen.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
		// Reihenfolge im Menü: Dashboard, Unternehmen, Entitäten, Einstellungen (30), Technik (40).
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Menüeinträge.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Local SEO', 'local-seo-entity-plugin' ),
			__( 'Local SEO', 'local-seo-entity-plugin' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-location-alt',
			58
		);
		add_submenu_page(
			self::SLUG,
			__( 'Local SEO – Dashboard', 'local-seo-entity-plugin' ),
			__( 'Dashboard', 'local-seo-entity-plugin' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Local SEO – Unternehmen', 'local-seo-entity-plugin' ),
			__( 'Unternehmen', 'local-seo-entity-plugin' ),
			Capabilities::MANAGE,
			self::PROFILE_SLUG,
			array( $this, 'render' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Local SEO – Entitäten', 'local-seo-entity-plugin' ),
			__( 'Entitäten', 'local-seo-entity-plugin' ),
			Capabilities::MANAGE,
			self::ENTITIES_SLUG,
			array( $this, 'render' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Local SEO – Keywords', 'local-seo-entity-plugin' ),
			__( 'Keywords', 'local-seo-entity-plugin' ),
			Capabilities::MANAGE,
			self::KEYWORDS_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Unterseite Einstellungen.
	 */
	public function add_settings_menu(): void {
		add_submenu_page(
			self::SLUG,
			__( 'Local SEO – Einstellungen', 'local-seo-entity-plugin' ),
			__( 'Einstellungen', 'local-seo-entity-plugin' ),
			Capabilities::MANAGE,
			self::SETTINGS_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Lädt die App nur auf den eigenen Seiten.
	 */
	public function enqueue(): void {
		if ( null === self::current_view() ) {
			return;
		}
		$asset_file = LSEC_DIR . 'assets/dist/admin.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( 'lsec-admin', LSEC_URL . 'assets/dist/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script(
			'lsec-admin',
			'window.lsecAdmin = ' . wp_json_encode(
				array(
					'minConfidence' => (float) Settings::get( 'entity_min_confidence' ),
					'pages'         => array(
						'profile'  => admin_url( 'admin.php?page=' . self::PROFILE_SLUG ),
						'entities' => admin_url( 'admin.php?page=' . self::ENTITIES_SLUG ),
						'keywords' => admin_url( 'admin.php?page=' . self::KEYWORDS_SLUG ),
						'settings' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
					),
				)
			) . ';',
			'before'
		);
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'lsec-admin', LSEC_URL . 'assets/dist/admin.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( 'lsec-admin', 'rtl', 'replace' );
	}

	/**
	 * Gerüst der Seite; den Inhalt zeichnet die App.
	 */
	public function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'local-seo-entity-plugin' ), 403 );
		}
		$view   = self::current_view() ?? 'dashboard';
		$titles = array(
			'dashboard' => __( 'Local SEO – Dashboard', 'local-seo-entity-plugin' ),
			'profile'   => __( 'Local SEO – Unternehmen', 'local-seo-entity-plugin' ),
			'entities'  => __( 'Local SEO – Entitäten', 'local-seo-entity-plugin' ),
			'keywords'  => __( 'Local SEO – Keywords', 'local-seo-entity-plugin' ),
			'settings'  => __( 'Local SEO – Einstellungen', 'local-seo-entity-plugin' ),
		);
		echo '<div class="wrap"><h1>' . esc_html( $titles[ $view ] ) . '</h1>';
		if ( ! is_readable( LSEC_DIR . 'assets/dist/admin.asset.php' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Die Oberfläche ist nicht gebaut (assets/dist fehlt). Im Plugin-Ordner npm ci && npm run build ausführen oder die ZIP-Datei installieren.', 'local-seo-entity-plugin' ) . '</p></div>';
		}
		printf( '<div id="lsec-app" class="lsec-app" data-view="%s"><noscript>%s</noscript></div></div>', esc_attr( $view ), esc_html__( 'Diese Seite braucht JavaScript.', 'local-seo-entity-plugin' ) );
	}

	/**
	 * Ansicht der aufgerufenen Seite oder null.
	 */
	private static function current_view(): ?string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::VIEWS[ $page ] ?? null;
	}
}
