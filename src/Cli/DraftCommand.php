<?php
/**
 * WP-CLI: Entwürfe.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Content\BlockBuilder;
use Lsec\Content\DraftWriter;
use Lsec\Integrations\AI\Json;
use WP_CLI;

/**
 * Entwürfe mit Blöcken und Rank-Math-Angaben (Prüfpunkte M12, M13).
 */
final class DraftCommand {

	/**
	 * Legt einen Testentwurf mit allen erlaubten Blocktypen an.
	 *
	 * Enthält einen internen Link auf den neuesten veröffentlichten Beitrag, einen Link auf eine
	 * nicht vorhandene Seite (wird entfernt), eine bestehende Kategorie und zwei Schlagwörter.
	 *
	 * ## OPTIONS
	 *
	 * [--porcelain]
	 * : Nur die Beitrags-ID ausgeben.
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function sample( array $args, array $assoc_args ): void {
		$latest   = get_posts(
			array(
				'post_status'    => 'publish',
				'post_type'      => array( 'post', 'page' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$link     = array() !== $latest ? (string) get_permalink( (int) $latest[0] ) : '';
		$category = (int) get_option( 'default_category', 1 );

		$draft = array(
			'title'    => 'Flächenreinigung in Burgdorf: Ablauf, Kosten, Tipps (Test lsec)',
			'excerpt'  => 'Testentwurf des Plugins Local SEO & Entity Content.',
			'content'  => array(
				array(
					'type' => 'paragraph',
					'text' => 'Moos und Algen auf Pflaster? <strong>Thermische Flächenreinigung</strong> reinigt schonend. ' . ( '' !== $link ? 'Mehr dazu in <a href="' . $link . '">unserem Beitrag</a>.' : '' ),
				),
				array(
					'type'  => 'heading',
					'level' => 2,
					'text'  => 'So läuft die Reinigung ab',
				),
				array(
					'type'    => 'list',
					'ordered' => true,
					'items'   => array( 'Fläche prüfen', 'Mit Heißwasser reinigen', 'Fugen neu verfüllen' ),
				),
				array(
					'type'  => 'heading',
					'level' => 3,
					'text'  => 'Was es kostet',
				),
				array(
					'type' => 'table',
					'head' => array( 'Fläche', 'Preis ab' ),
					'rows' => array(
						array( 'Terrasse bis 30 m²', '150 €' ),
						array( 'Einfahrt bis 60 m²', '280 €' ),
					),
				),
				array(
					'type'  => 'list',
					'items' => array( 'ohne Chemie', 'auch für <em>Naturstein</em>' ),
				),
				array(
					'type' => 'quote',
					'text' => 'Die Terrasse sieht aus wie neu.',
					'cite' => 'Kundin aus Burgdorf',
				),
				array( 'type' => 'separator' ),
				array(
					'type'    => 'details',
					'summary' => 'Wie lange dauert es?',
					'text'    => 'Meist einen Tag. <a href="' . home_url( '/gibt-es-nicht-lsec/' ) . '">Kaputter Link</a> wird entfernt.',
				),
			),
			'seo'      => array(
				'title'              => 'Flächenreinigung Burgdorf – Ablauf & Kosten %sep% %sitename%',
				'description'        => 'Thermische Flächenreinigung in Burgdorf: Ablauf, Preise und Tipps für Pflaster, Terrasse und Einfahrt.',
				'focus_keyword'      => 'Flächenreinigung Burgdorf',
				'secondary_keywords' => array( 'Terrassenreinigung', 'Pflasterreinigung' ),
				'slug'               => 'flaechenreinigung-burgdorf-test-lsec',
			),
			'taxonomy' => array(
				'category_ids'             => array( $category ),
				'new_category_suggestions' => array( 'Reinigung' ),
				'tags'                     => array( 'Flächenreinigung', 'Burgdorf' ),
			),
		);
		$this->create_and_report( $draft, ! empty( $assoc_args['porcelain'] ) );
	}

	/**
	 * Legt einen Entwurf aus einer JSON-Datei an (Aufbau wie Spezifikation 8.5, Inhalt als Knotenliste).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON-Datei.
	 *
	 * [--porcelain]
	 * : Nur die Beitrags-ID ausgeben.
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function create( array $args, array $assoc_args ): void {
		$raw   = is_readable( (string) $args[0] ) ? file_get_contents( (string) $args[0] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$draft = false === $raw ? null : Json::decode( $raw );
		if ( null === $draft ) {
			WP_CLI::error( 'Datei fehlt oder enthält kein JSON.' );
		}
		$this->create_and_report( $draft, ! empty( $assoc_args['porcelain'] ) );
	}

	/**
	 * Zeigt Blöcke und SEO-Angaben eines Entwurfs.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Beitrag.
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function show( array $args, array $assoc_args ): void {
		$post = get_post( (int) $args[0] );
		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( 'Beitrag nicht gefunden.' );
		}
		$blocks = array_values( array_filter( parse_blocks( $post->post_content ), static fn( array $b ): bool => null !== $b['blockName'] ) );
		$data   = array(
			'status'                  => $post->post_status,
			'blocks'                  => array_map( static fn( array $b ): string => (string) $b['blockName'], $blocks ),
			'roundtrip'               => serialize_blocks( parse_blocks( $post->post_content ) ) === $post->post_content,
			'categories'              => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'tags'                    => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
			'rank_math_title'         => (string) get_post_meta( $post->ID, 'rank_math_title', true ),
			'rank_math_description'   => (string) get_post_meta( $post->ID, 'rank_math_description', true ),
			'rank_math_focus_keyword' => (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
			'links'                   => preg_match_all( '#<a\s#i', $post->post_content ),
		);
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
			return;
		}
		foreach ( $data as $key => $value ) {
			WP_CLI::log( str_pad( $key, 25 ) . ( is_array( $value ) ? implode( ', ', $value ) : ( is_bool( $value ) ? ( $value ? 'ja' : 'nein' ) : (string) $value ) ) );
		}
	}

	/**
	 * Gemeinsame Ausgabe.
	 *
	 * @param array<string, mixed> $draft     Entwurf.
	 * @param bool                 $porcelain Nur ID.
	 */
	private function create_and_report( array $draft, bool $porcelain ): void {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$author = get_current_user_id() > 0 ? get_current_user_id() : (int) ( $admins[0] ?? 1 );
		try {
			$out = ( new DraftWriter( BlockBuilder::for_site() ) )->create( $draft, $author );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( $porcelain ) {
			WP_CLI::log( (string) $out['post_id'] );
			return;
		}
		WP_CLI::log( sprintf( 'Interne Links: %d gesetzt, %d entfernt%s', $out['links_kept'], count( $out['links_removed'] ), array() !== $out['links_removed'] ? ' (' . implode( ', ', $out['links_removed'] ) . ')' : '' ) );
		WP_CLI::log( 'Rank Math: ' . ( $out['rank_math'] ? 'Metafelder gesetzt' : 'nicht aktiv, Angaben als Vorschlag gespeichert' ) );
		if ( array() !== $out['suggested_categories'] ) {
			WP_CLI::log( 'Vorgeschlagene neue Kategorien (nicht angelegt): ' . implode( ', ', $out['suggested_categories'] ) );
		}
		WP_CLI::success( sprintf( 'Entwurf %d angelegt: %s', $out['post_id'], admin_url( 'post.php?post=' . $out['post_id'] . '&action=edit' ) ) );
	}
}
