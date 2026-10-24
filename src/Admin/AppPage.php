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
	public const TOPICS_SLUG   = 'lsec-topics';
	public const STYLE_SLUG    = 'lsec-style';
	public const SETUP_SLUG    = 'lsec-setup';
	public const RUNS_SLUG     = 'lsec-runs';
	public const REPORT_SLUG   = 'lsec-report';
	public const MANUAL_SLUG   = 'lsec-manual';

	/**
	 * Seiten-Slugs und ihre Ansicht.
	 */
	private const VIEWS = array(
		self::SLUG          => 'dashboard',
		self::MANUAL_SLUG   => 'manual',
		self::SETUP_SLUG    => 'setup',
		self::PROFILE_SLUG  => 'profile',
		self::ENTITIES_SLUG => 'entities',
		self::KEYWORDS_SLUG => 'keywords',
		self::TOPICS_SLUG   => 'topics',
		self::STYLE_SLUG    => 'style',
		self::RUNS_SLUG     => 'runs',
		self::REPORT_SLUG   => 'report',
		self::SETTINGS_SLUG => 'settings',
		StatusPage::SLUG    => 'technik',
	);

	/**
	 * Registriert Menü und Skripte.
	 */
	public function register(): void {
		// Vor den übrigen Seiten, damit das Hauptmenü steht, wenn sie sich als Unterseiten eintragen.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
		// Reihenfolge im Menü: siehe MENU, danach Einstellungen (30) und Technik (40).
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LSEC_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Links in der Plugin-Liste: Handbuch und Dashboard.
	 *
	 * @param string[] $links Vorhandene Links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		if ( ! Capabilities::current_user_can_manage() ) {
			return $links;
		}
		return array_merge(
			array(
				'lsec-manual'    => '<a href="' . esc_url( self::manual_url() ) . '">' . esc_html__( 'Handbuch', 'local-seo-entity-plugin' ) . '</a>',
				'lsec-dashboard' => '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Dashboard', 'local-seo-entity-plugin' ) . '</a>',
			),
			$links
		);
	}

	/**
	 * Adresse des Handbuchs, wahlweise mit Abschnitt.
	 *
	 * @param string $section Anker ohne #.
	 */
	public static function manual_url( string $section = '' ): string {
		return admin_url( 'admin.php?page=' . self::MANUAL_SLUG ) . ( '' !== $section ? '#' . $section : '' );
	}

	/**
	 * Unterseiten in der Reihenfolge des Menüs (0.11.0, nach Häufigkeit): Überblick, tägliche Arbeit,
	 * Grundlagen, Einrichtung und Technik. Einstellungen und Technik hängen sich danach an (Priorität 30 und 40).
	 */
	private const MENU = array(
		self::SLUG          => 'Dashboard',
		self::MANUAL_SLUG   => 'Handbuch',
		self::TOPICS_SLUG   => 'Themen',
		self::REPORT_SLUG   => 'Bericht',
		self::KEYWORDS_SLUG => 'Keywords',
		self::ENTITIES_SLUG => 'Entitäten',
		self::PROFILE_SLUG  => 'Unternehmen',
		self::STYLE_SLUG    => 'Style Mimic',
		self::RUNS_SLUG     => 'Abläufe',
		self::SETUP_SLUG    => 'Einrichtung',
	);

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
		foreach ( self::MENU as $slug => $label ) {
			add_submenu_page(
				self::SLUG,
				'Local SEO – ' . $label,
				$label,
				Capabilities::MANAGE,
				$slug,
				array( $this, 'render' )
			);
		}
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
						'topics'   => admin_url( 'admin.php?page=' . self::TOPICS_SLUG ),
						'settings' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
						'setup'    => admin_url( 'admin.php?page=' . self::SETUP_SLUG ),
						'style'    => admin_url( 'admin.php?page=' . self::STYLE_SLUG ),
						'runs'     => admin_url( 'admin.php?page=' . self::RUNS_SLUG ),
						'report'   => admin_url( 'admin.php?page=' . self::REPORT_SLUG ),
						'manual'   => self::manual_url(),
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
			'topics'    => __( 'Local SEO – Themen', 'local-seo-entity-plugin' ),
			'settings'  => __( 'Local SEO – Einstellungen', 'local-seo-entity-plugin' ),
			'setup'     => __( 'Local SEO – Einrichtung', 'local-seo-entity-plugin' ),
			'style'     => __( 'Local SEO – Style Mimic', 'local-seo-entity-plugin' ),
			'runs'      => __( 'Local SEO – Abläufe', 'local-seo-entity-plugin' ),
			'report'    => __( 'Local SEO – Bericht', 'local-seo-entity-plugin' ),
			'manual'    => __( 'Local SEO – Handbuch', 'local-seo-entity-plugin' ),
		);
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html( $titles[ $view ] ?? __( 'Local SEO', 'local-seo-entity-plugin' ) ) . '</h1>';
		if ( 'manual' === $view ) {
			echo '<hr class="wp-header-end">';
			$this->render_manual();
			echo '</div>';
			return;
		}
		printf( ' <a class="page-title-action" href="%s">%s</a><hr class="wp-header-end">', esc_url( self::manual_url( Manual::SECTIONS[ $view ] ?? '' ) ), esc_html__( 'Handbuch', 'local-seo-entity-plugin' ) );
		if ( 'dashboard' === $view ) {
			printf(
				'<div class="notice notice-info lsec-manual-hint"><p><strong>%s</strong> %s <a href="%s">%s</a> · <a href="%s">%s</a></p></div>',
				esc_html__( 'Neu hier?', 'local-seo-entity-plugin' ),
				esc_html__( 'Das Handbuch erklärt Installation, Cronjob, Einrichtung und den täglichen Ablauf.', 'local-seo-entity-plugin' ),
				esc_url( self::manual_url() ),
				esc_html__( 'Handbuch lesen', 'local-seo-entity-plugin' ),
				esc_url( admin_url( 'admin.php?page=' . self::SETUP_SLUG ) ),
				esc_html__( 'Zur Einrichtung', 'local-seo-entity-plugin' )
			);
		}
		if ( ! is_readable( LSEC_DIR . 'assets/dist/admin.asset.php' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Die Oberfläche ist nicht gebaut (assets/dist fehlt). Im Plugin-Ordner npm ci && npm run build ausführen oder die ZIP-Datei installieren.', 'local-seo-entity-plugin' ) . '</p></div>';
		}
		printf( '<div id="lsec-app" class="lsec-app" data-view="%s"><noscript>%s</noscript></div></div>', esc_attr( $view ), esc_html__( 'Diese Seite braucht JavaScript.', 'local-seo-entity-plugin' ) );
	}

	/**
	 * Handbuch mit Inhaltsverzeichnis aus der Datei docs/handbuch.md.
	 */
	private function render_manual(): void {
		$html = Manual::html();
		if ( null === $html ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Das Handbuch fehlt (docs/handbuch.md). Bitte die ZIP-Datei des Plugins neu installieren.', 'local-seo-entity-plugin' ) . '</p></div>';
			return;
		}
		echo '<div class="lsec-manual">' . wp_kses_post( $html ) . '</div>';
	}

	/**
	 * Ansicht der aufgerufenen Seite oder null.
	 */
	private static function current_view(): ?string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::VIEWS[ $page ] ?? null;
	}
}
