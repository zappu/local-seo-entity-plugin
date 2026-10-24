<?php
/**
 * Content- und Entity-Bericht (Stufe F, Spezifikation 3 „MVP 1“ und Anforderung 12).
 *
 * @package Lsec
 */

namespace Lsec\Report;

use Lsec\Content\ContentIndex;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Production\QualityReportRepository;
use Lsec\Topics\TopicResearchWorkflow;

/**
 * Stellt zusammen:
 * - Inhalte: gelesene Inhalte, vom Plugin geschriebene Entwürfe mit Status, Prüfergebnis und Kosten.
 * - Entitäten: Anzahl nach Typ und Status, die häufigsten in eigenen Inhalten, Leistungen und Orte ohne Inhalt (ohne eigene Standorte und das Servicegebiet selbst).
 * - Hinweise „bestehende Seite verbessern“: verworfene Themen, für die schon eine passende Seite rankt
 *   (Überschneidung, Regel 1 in Abschnitt „Search Console als Signal“), und eigene Seiten auf Position 4 bis 20 in der Search Console.
 */
final class ContentReport {

	/**
	 * Positionen, auf denen sich der Ausbau einer bestehenden Seite besonders lohnt (knapp unter oder auf Seite 1).
	 */
	public const POSITION_MIN = 4.0;
	public const POSITION_MAX = 20.0;

	/**
	 * Mindestzahl an Impressionen für einen Hinweis aus der Search Console.
	 */
	public const MIN_IMPRESSIONS = 10;

	/**
	 * Der ganze Bericht.
	 *
	 * @return array{content: array<string, mixed>, entities: array<string, mixed>, improve: list<array<string, mixed>>, pages: list<array<string, mixed>>, brand_terms: string[], images: array<string, mixed>, generated_at: string}
	 */
	public static function build(): array {
		$improve = self::improve();
		return array(
			'content'      => self::content(),
			'entities'     => self::entities(),
			'improve'      => $improve,
			'pages'        => self::pages( $improve ),
			'brand_terms'  => self::brand_terms(),
			'images'       => self::images(),
			'generated_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Bilder der Mediathek (0.10.0): Zahlen und Einstellung.
	 *
	 * @return array<string, mixed>
	 */
	public static function images(): array {
		return \Lsec\Media\ImageLibrary::stats() + array( 'mode' => (string) \Lsec\Core\Settings::get( 'image_mode' ) );
	}

	/**
	 * Inhalte und Entwürfe des Plugins.
	 *
	 * @return array<string, mixed>
	 */
	public static function content(): array {
		global $wpdb;
		$stats   = ContentIndex::stats();
		$ids     = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_lsec_run_id' AND p.post_status <> 'trash' ORDER BY pm.post_id DESC LIMIT 200" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_query_meta_key
		$reports = new QualityReportRepository();
		$calls   = new AiCallRepository();
		$drafts  = array();
		$status  = array();
		$passed  = 0;
		$scores  = array();
		$cost    = 0.0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$report                       = $reports->latest_for_post( $id );
			$ok                           = null !== $report && ! empty( $report['passed'] );
			$score                        = null === $report || null === $report['score'] ? null : (float) $report['score'];
			$run                          = (int) get_post_meta( $id, '_lsec_run_id', true );
			$run_cost                     = $run > 0 ? $calls->cost_of_run( $run ) : 0.0;
			$status[ $post->post_status ] = ( $status[ $post->post_status ] ?? 0 ) + 1;
			$passed                      += $ok ? 1 : 0;
			$cost                        += $run_cost;
			if ( null !== $score ) {
				$scores[] = $score;
			}
			$image = '';
			if ( has_post_thumbnail( $id ) ) {
				$image = (string) get_post_meta( $id, \Lsec\Media\FeaturedImage::SOURCE, true );
				$image = in_array( $image, array( 'library', 'generated' ), true ) ? $image : 'kept';
			}
			$drafts[] = array(
				'post_id'  => $id,
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'status'   => (string) $post->post_status,
				'passed'   => $ok,
				'score'    => $score,
				'topic_id' => (int) get_post_meta( $id, '_lsec_topic_id', true ),
				'run_id'   => $run,
				'cost'     => round( $run_cost, 4 ),
				'date'     => (string) get_post_modified_time( 'c', true, $post ),
				'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
				'view_url' => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
				'image'    => $image,
			);

		}
		$count = count( $drafts );
		return array(
			'indexed'   => (int) $stats['indexed'],
			'published' => (int) $stats['published'],
			'drafts'    => array(
				'count'         => $count,
				'by_status'     => $status,
				'passed'        => $passed,
				'average_score' => array() === $scores ? null : round( array_sum( $scores ) / count( $scores ), 1 ),
				'cost'          => round( $cost, 2 ),
				'cost_per'      => 0 === $count ? null : round( $cost / $count, 3 ),
			),
			'items'     => array_slice( $drafts, 0, 50 ),
		);
	}

	/**
	 * Entitäten: Anzahl, häufigste und Lücken.
	 *
	 * @return array<string, mixed>
	 */
	public static function entities(): array {
		global $wpdb;
		$e      = Schema::table( $wpdb->prefix, 'entities' );
		$m      = Schema::table( $wpdb->prefix, 'mentions' );
		$counts = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( "SELECT type, status, COUNT(*) AS n FROM {$e} WHERE status <> 'merged' GROUP BY type, status", ARRAY_A ) as $row ) {
			$counts[ (string) $row['type'] ][ (string) $row['status'] ] = (int) $row['n'];
		}
		$top  = (array) $wpdb->get_results( "SELECT e.id, e.canonical_name AS name, e.type, e.status, COUNT(DISTINCT m.object_id) AS posts FROM {$e} e JOIN {$m} m ON m.entity_id = e.id AND m.object_type = 'post' WHERE e.status IN ('confirmed', 'probable') GROUP BY e.id, e.canonical_name, e.type, e.status ORDER BY posts DESC, e.canonical_name LIMIT 15", ARRAY_A );
		$gaps = (array) $wpdb->get_results( "SELECT e.id, e.canonical_name AS name, e.type, e.status FROM {$e} e LEFT JOIN {$m} m ON m.entity_id = e.id AND m.object_type = 'post' WHERE e.status = 'confirmed' AND ( e.type = 'service' OR ( e.type = 'place' AND e.origin <> 'business_profile' ) ) GROUP BY e.id, e.canonical_name, e.type, e.status HAVING COUNT(m.id) = 0 ORDER BY e.type, e.canonical_name LIMIT 40", ARRAY_A );
		// phpcs:enable
		$cast = static fn( array $r ): array => array(
			'id'     => (int) $r['id'],
			'name'   => (string) $r['name'],
			'type'   => (string) $r['type'],
			'status' => (string) $r['status'],
			'posts'  => (int) ( $r['posts'] ?? 0 ),
		);
		return array(
			'counts' => $counts,
			'top'    => array_map( $cast, $top ),
			'gaps'   => array_map( $cast, $gaps ),
		);
	}

	/**
	 * Hinweise „bestehende Seite verbessern“, nach Impressionen bzw. Priorität.
	 *
	 * Nachtrag 24.10. (0.10.5): je Zeile auch kind (content, brand, navigational), type (Seite, Beitrag, Produkt) und
	 * elementor; Marken- und Navigationssuchen bekommen ihren eigenen Hinweis.
	 *
	 * @return list<array{source: string, page_url: string, post_id: int, title: string, keyword: string, position: float|null, impressions: int|null, clicks: int|null, reason: string, topic_id: int, kind: string, type: string, elementor: bool}>
	 */
	public static function improve(): array {
		global $wpdb;
		$out   = array();
		$seen  = array();
		$brand = self::brand_terms();
		$t     = Schema::table( $wpdb->prefix, 'topics' );
		$k     = Schema::table( $wpdb->prefix, 'keywords' );
		$km    = Schema::table( $wpdb->prefix, 'keyword_metrics' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest = (string) $wpdb->get_var( "SELECT MAX(period_end) FROM {$km} WHERE source = 'gsc'" );
		$rows   = '' === $latest ? array() : (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT kw.keyword, m.page_url, m.position, m.impressions, m.clicks FROM {$km} m JOIN {$k} kw ON kw.id = m.keyword_id
				WHERE m.source = 'gsc' AND m.period_end = %s AND m.page_url <> '' AND m.position BETWEEN %f AND %f AND m.impressions >= %d AND ( kw.exclusion IS NULL OR kw.exclusion = '' )
				ORDER BY m.impressions DESC LIMIT 60",
				$latest,
				self::POSITION_MIN,
				self::POSITION_MAX,
				self::MIN_IMPRESSIONS
			),
			ARRAY_A
		);
		$topics = (array) $wpdb->get_results( "SELECT id, title, overlap FROM {$t} WHERE status = 'discarded' ORDER BY priority_score DESC LIMIT 50", ARRAY_A );
		// phpcs:enable
		foreach ( $rows as $row ) {
			$local = TopicResearchWorkflow::own_url( (string) $row['page_url'] );
			if ( null === $local ) {
				continue;
			}
			$post_id = url_to_postid( $local );
			$key     = $local . '|' . $row['keyword'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$position     = round( (float) $row['position'], 1 );
			$kind         = QueryKind::classify( (string) $row['keyword'], $brand );
			$out[]        = array(
				'source'      => 'gsc',
				'page_url'    => $local,
				'post_id'     => $post_id,
				'title'       => $post_id > 0 ? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) : '',
				'keyword'     => (string) $row['keyword'],
				'position'    => $position,
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'reason'      => sprintf( 'Position %s für „%s“ bei %d Impressionen: %s', number_format( $position, 1, ',', '' ), $row['keyword'], (int) $row['impressions'], self::advice( $kind, $position ) ),
				'topic_id'    => 0,
			) + self::page_info( $post_id ) + array( 'kind' => $kind );
		}
		foreach ( $topics as $topic ) {
			$overlap = json_decode( (string) $topic['overlap'], true );
			foreach ( (array) ( is_array( $overlap ) ? ( $overlap['items'] ?? array() ) : array() ) as $item ) {
				if ( empty( $item['fits'] ) ) {
					continue;
				}
				$local = (string) ( $item['url'] ?? '' );
				$key   = $local . '|' . (string) ( $item['keyword'] ?? '' );
				if ( '' === $local || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$post_id      = (int) ( $item['post_id'] ?? 0 );
				$out[]        = array(
					'source'      => 'topic',
					'page_url'    => $local,
					'post_id'     => $post_id,
					'title'       => $post_id > 0 ? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) : '',
					'keyword'     => (string) ( $item['keyword'] ?? '' ),
					'position'    => null,
					'impressions' => null,
					'clicks'      => null,
					'reason'      => sprintf( 'Thema „%s“ nicht neu geschrieben: %s – stattdessen diese Seite ausbauen', $topic['title'], (string) ( $item['reason'] ?? '' ) ),
					'topic_id'    => (int) $topic['id'],
				) + self::page_info( $post_id ) + array( 'kind' => QueryKind::CONTENT );
			}
		}
		return $out;
	}

	/**
	 * Hinweis je Art und Position.
	 *
	 * @param string $kind     Art der Anfrage.
	 * @param float  $position Position.
	 */
	public static function advice( string $kind, float $position ): string {
		if ( QueryKind::BRAND === $kind ) {
			return 'Suche nach dem eigenen Namen – mehr Text hilft hier nicht; Google-Unternehmensprofil, strukturierte Daten für Unternehmen und Personen (Rank Math) und Seitentitel mit vollem Namen und Ort prüfen';
		}
		if ( QueryKind::NAVIGATIONAL === $kind ) {
			return 'Navigationssuche – die gesuchte Angabe (Öffnungszeiten, Telefon, Adresse) gut sichtbar auf der Seite, in den strukturierten Daten und im Google-Unternehmensprofil';
		}
		return $position <= 10.0 ? 'auf Seite 1, aber nicht oben – Titel, Beschreibung und Inhalt zur Anfrage schärfen' : 'knapp hinter Seite 1 – Inhalt zur Anfrage ausbauen';
	}

	/**
	 * Art des Inhalts und Elementor.
	 *
	 * @param int $post_id Inhalt.
	 * @return array{type: string, elementor: bool}
	 */
	private static function page_info( int $post_id ): array {
		$types = array(
			'page'    => 'Seite',
			'post'    => 'Beitrag',
			'product' => 'Produkt',
		);
		$type  = $post_id > 0 ? (string) get_post_type( $post_id ) : '';
		return array(
			'type'      => $types[ $type ] ?? ( '' !== $type ? $type : 'Archiv' ),
			'elementor' => $post_id > 0 && 'builder' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
		);
	}

	/**
	 * Markenbegriffe: Einstellung brand_terms oder aus Unternehmensname und Personen (QueryKind).
	 *
	 * @return string[]
	 */
	public static function brand_terms(): array {
		$setting = trim( (string) \Lsec\Core\Settings::get( 'brand_terms' ) );
		if ( '' !== $setting ) {
			return QueryKind::parse_terms( $setting );
		}
		global $wpdb;
		$profile = ( new \Lsec\Entities\BusinessProfile() )->get();
		$e       = Schema::table( $wpdb->prefix, 'entities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$people = array_map( 'strval', (array) $wpdb->get_col( "SELECT canonical_name FROM {$e} WHERE type = 'person' AND ( status IN ('confirmed', 'probable') OR origin = 'business_profile' )" ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$places  = array_map( 'strval', (array) $wpdb->get_col( "SELECT canonical_name FROM {$e} WHERE type IN ('place', 'service_area') AND status IN ('confirmed', 'probable')" ) );
		$exclude = array_merge(
			$places,
			array_map( static fn( array $s ): string => (string) $s['name'], (array) ( $profile['services'] ?? array() ) ),
			array_map( static fn( array $s ): string => (string) $s['name'], (array) ( $profile['locations'] ?? array() ) )
		);
		return QueryKind::brand_terms( array_merge( array( (string) ( $profile['organization']['name'] ?? '' ) ), $people ), $exclude );
	}

	/**
	 * Hinweise je Seite: eine Gruppe je Seite und Art, mit allen Suchanfragen; Inhalt zuerst, nach Impressionen.
	 *
	 * @param list<array<string, mixed>> $items Hinweise aus improve().
	 * @return list<array<string, mixed>>
	 */
	public static function pages( array $items ): array {
		$groups = array();
		foreach ( $items as $item ) {
			$kind = (string) ( $item['kind'] ?? QueryKind::CONTENT );
			$key  = $kind . '|' . (string) $item['page_url'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'kind'        => $kind,
					'page_url'    => (string) $item['page_url'],
					'post_id'     => (int) $item['post_id'],
					'title'       => (string) $item['title'],
					'type'        => (string) ( $item['type'] ?? '' ),
					'elementor'   => ! empty( $item['elementor'] ),
					'impressions' => 0,
					'position'    => null,
					'queries'     => array(),
					'topics'      => array(),
				);
			}
			if ( 'topic' === (string) $item['source'] ) {
				$groups[ $key ]['topics'][] = (string) $item['reason'];
				continue;
			}
			$groups[ $key ]['queries'][]    = array(
				'keyword'     => (string) $item['keyword'],
				'position'    => $item['position'],
				'impressions' => (int) $item['impressions'],
			);
			$groups[ $key ]['impressions'] += (int) $item['impressions'];
			$best                           = $groups[ $key ]['position'];
			$groups[ $key ]['position']     = null === $best ? (float) $item['position'] : min( (float) $best, (float) $item['position'] );
		}
		$out = array();
		foreach ( $groups as $group ) {
			$advice = array();
			if ( array() !== $group['queries'] ) {
				$advice[] = ucfirst( self::advice( $group['kind'], (float) $group['position'] ) ) . '.';
			}
			if ( array() !== $group['topics'] ) {
				$advice[] = implode( ' ', array_map( static fn( string $t ): string => $t . '.', $group['topics'] ) );
			}
			if ( $group['elementor'] && QueryKind::CONTENT === $group['kind'] ) {
				$advice[] = 'Mit Elementor gebaut: Änderungen im Elementor-Editor.';
			}
			$group['advice'] = implode( ' ', $advice );
			$out[]           = $group;
		}
		$order = array(
			QueryKind::CONTENT      => 0,
			QueryKind::NAVIGATIONAL => 1,
			QueryKind::BRAND        => 2,
		);
		usort( $out, static fn( array $a, array $b ): int => array( $order[ $a['kind'] ] ?? 3, - $a['impressions'] ) <=> array( $order[ $b['kind'] ] ?? 3, - $b['impressions'] ) );
		return $out;
	}

	/**
	 * Hinweise als CSV (Semikolon, UTF-8 mit BOM für Excel).
	 *
	 * @param list<array<string, mixed>> $items Hinweise.
	 */
	public static function csv( array $items ): string {
		$kinds = array(
			QueryKind::CONTENT      => 'Inhalt',
			QueryKind::BRAND        => 'Markensuche',
			QueryKind::NAVIGATIONAL => 'Navigationssuche',
		);
		$lines = array( 'Seite;Titel;Art;Elementor;Keyword;Position;Impressionen;Klicks;Hinweis' );
		foreach ( $items as $item ) {
			$cells   = array( $item['page_url'], $item['title'], $kinds[ (string) ( $item['kind'] ?? '' ) ] ?? '', empty( $item['elementor'] ) ? '' : 'ja', $item['keyword'], null === $item['position'] ? '' : number_format( (float) $item['position'], 1, ',', '' ), (string) ( $item['impressions'] ?? '' ), (string) ( $item['clicks'] ?? '' ), $item['reason'] );
			$lines[] = implode( ';', array_map( static fn( $c ): string => '"' . str_replace( '"', '""', (string) $c ) . '"', $cells ) );
		}
		return "\u{FEFF}" . implode( "\r\n", $lines ) . "\r\n";
	}
}
