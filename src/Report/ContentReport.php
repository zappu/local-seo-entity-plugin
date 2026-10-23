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
	 * @return array{content: array<string, mixed>, entities: array<string, mixed>, improve: list<array<string, mixed>>, generated_at: string}
	 */
	public static function build(): array {
		return array(
			'content'      => self::content(),
			'entities'     => self::entities(),
			'improve'      => self::improve(),
			'generated_at' => gmdate( 'c' ),
		);
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
	 * @return list<array{source: string, page_url: string, post_id: int, title: string, keyword: string, position: float|null, impressions: int|null, clicks: int|null, reason: string, topic_id: int}>
	 */
	public static function improve(): array {
		global $wpdb;
		$out  = array();
		$seen = array();
		$t    = Schema::table( $wpdb->prefix, 'topics' );
		$k    = Schema::table( $wpdb->prefix, 'keywords' );
		$km   = Schema::table( $wpdb->prefix, 'keyword_metrics' );
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
			$out[]        = array(
				'source'      => 'gsc',
				'page_url'    => $local,
				'post_id'     => $post_id,
				'title'       => $post_id > 0 ? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) : '',
				'keyword'     => (string) $row['keyword'],
				'position'    => $position,
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'reason'      => sprintf( 'Position %s für „%s“ bei %d Impressionen: %s', number_format( $position, 1, ',', '' ), $row['keyword'], (int) $row['impressions'], $position <= 10.0 ? 'auf Seite 1, aber nicht oben – Titel, Beschreibung und Inhalt zur Anfrage schärfen' : 'knapp hinter Seite 1 – Inhalt zur Anfrage ausbauen' ),
				'topic_id'    => 0,
			);
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
				);
			}
		}
		return $out;
	}

	/**
	 * Hinweise als CSV (Semikolon, UTF-8 mit BOM für Excel).
	 *
	 * @param list<array<string, mixed>> $items Hinweise.
	 */
	public static function csv( array $items ): string {
		$lines = array( 'Seite;Titel;Keyword;Position;Impressionen;Klicks;Hinweis' );
		foreach ( $items as $item ) {
			$cells   = array( $item['page_url'], $item['title'], $item['keyword'], null === $item['position'] ? '' : number_format( (float) $item['position'], 1, ',', '' ), (string) ( $item['impressions'] ?? '' ), (string) ( $item['clicks'] ?? '' ), $item['reason'] );
			$lines[] = implode( ';', array_map( static fn( $c ): string => '"' . str_replace( '"', '""', (string) $c ) . '"', $cells ) );
		}
		return "\u{FEFF}" . implode( "\r\n", $lines ) . "\r\n";
	}
}
