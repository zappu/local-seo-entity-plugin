<?php
/**
 * Strukturierte Daten aus den Entitäten (über Rank Math).
 *
 * @package Lsec
 */

namespace Lsec\Content;

use Lsec\Core\Settings;
use Lsec\Entities\BusinessProfile;

/**
 * Ergänzt das JSON-LD von Rank Math (Nachtrag 24.10.2026, 1.1.0, nach Casey Keith, „Step-by-Step Entity SEO“):
 *
 * - Beiträge des Plugins: `about` (Haupt-Entität des Themas) und `mentions` (weitere bestätigte Entitäten),
 *   jeweils mit `sameAs` auf Wikidata, wo eine ID hinterlegt ist. Nur Entitäten, deren Name oder Schreibweise
 *   im Text steht: Schema sagt nichts, was die Seite nicht sagt.
 * - Organisation (publisher, nicht bei Person): `knowsAbout` (Leistungen aus dem Profil) und `areaServed`
 *   (Servicegebiet), nur wenn Rank Math sie nicht schon setzt.
 *
 * Vorhandene Angaben von Rank Math oder von Hand werden nie überschrieben.
 */
final class EntitySchema {

	/**
	 * Höchstzahl der Entitäten in mentions.
	 */
	public const MAX_MENTIONS = 15;

	/**
	 * Typ in schema.org je Entitätstyp.
	 */
	public const TYPES = array(
		'organization' => 'Organization',
		'brand'        => 'Brand',
		'place'        => 'Place',
		'service_area' => 'Place',
		'service'      => 'Service',
		'product'      => 'Product',
		'person'       => 'Person',
		'concept'      => 'Thing',
	);

	/**
	 * Artikeltypen, an die about und mentions kommen.
	 */
	private const ARTICLES = array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle' );

	/**
	 * Registriert den Filter.
	 */
	public static function register(): void {
		add_filter( 'rank_math/json_ld', array( self::class, 'filter' ), 100, 2 );
		// Thoka Auto Tagger (ab 1.8.0) nimmt diese Begriffe als Schlagwörter, vor seiner eigenen Erkennung.
		add_filter( 'thoka_auto_tagger_priority_terms', array( self::class, 'auto_tagger_terms' ), 10, 2 );
	}

	/**
	 * Begriffe für den Auto Tagger: Haupt-Entitäten, dann weitere bestätigte Entitäten im Text, ohne das eigene
	 * Unternehmen. Abschaltbar mit der Einstellung auto_tagger_entities.
	 *
	 * @param mixed $terms Bisherige Begriffe.
	 * @param mixed $post  Beitrag.
	 * @return mixed
	 */
	public static function auto_tagger_terms( mixed $terms, mixed $post = null ): mixed {
		if ( ! is_array( $terms ) || ! $post instanceof \WP_Post || ! Settings::get( 'auto_tagger_entities' ) ) {
			return $terms;
		}
		try {
			return array_values( array_unique( array_merge( $terms, self::terms_for_post( $post->ID ) ) ) );
		} catch ( \Throwable $e ) {
			unset( $e );
			return $terms;
		}
	}

	/**
	 * Namen der Entitäten eines Beitrags für Schlagwörter (Filter lsec_post_entities für andere Plugins).
	 *
	 * @param int $post_id Beitrag.
	 * @return list<string>
	 */
	public static function terms_for_post( int $post_id ): array {
		$found = self::for_post( $post_id );
		$org   = ( new BusinessProfile() )->get()['organization']['name'] ?? '';
		$names = self::term_names( array_merge( $found['about'], $found['mentions'] ), (string) $org );
		$names = apply_filters( 'lsec_post_entities', $names, $post_id );
		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	/**
	 * Namen aus Knoten, ohne das eigene Unternehmen.
	 *
	 * @param list<array<string, mixed>> $nodes Knoten (about, dann mentions).
	 * @param string                     $org   Name des eigenen Unternehmens.
	 * @return list<string>
	 */
	public static function term_names( array $nodes, string $org ): array {
		$own   = self::fold( html_entity_decode( $org, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$names = array();
		foreach ( $nodes as $node ) {
			$name = (string) ( $node['name'] ?? '' );
			if ( '' === $name || ( 'Organization' === ( $node['@type'] ?? '' ) && '' !== $own && self::fold( $name ) === $own ) ) {
				continue;
			}
			$names[] = $name;
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Filter von Rank Math.
	 *
	 * @param mixed $data   Knoten des Graphen.
	 * @param mixed $jsonld Rank-Math-Objekt (ungenutzt).
	 * @return mixed
	 */
	public static function filter( mixed $data, mixed $jsonld = null ): mixed {
		unset( $jsonld );
		if ( ! is_array( $data ) ) {
			return $data;
		}
		try {
			if ( Settings::get( 'schema_entities' ) && is_singular( 'post' ) ) {
				$post_id = (int) get_queried_object_id();
				if ( $post_id > 0 && '' !== (string) get_post_meta( $post_id, '_lsec_topic_id', true ) ) {
					$found = self::for_post( $post_id );
					// Das eigene Unternehmen nicht als zweite Organisation, sondern als Verweis auf den Herausgeber.
					$found['mentions'] = self::link_publisher( $found['mentions'], is_array( $data['publisher'] ?? null ) ? $data['publisher'] : array() );
					foreach ( $data as $key => $node ) {
						if ( is_array( $node ) && self::is_type( $node, self::ARTICLES ) ) {
							$data[ $key ] = self::article( $node, $found['about'], $found['mentions'] );
						}
					}
				}
			}
			if ( Settings::get( 'schema_organization' ) && isset( $data['publisher'] ) && is_array( $data['publisher'] ) ) {
				$data['publisher'] = self::organization( $data['publisher'], self::services(), self::area() );
			}
		} catch ( \Throwable $e ) {
			// Strukturierte Daten dürfen die Seite nie stören.
			unset( $e );
		}
		return $data;
	}

	/**
	 * Knoten für eine Entität.
	 *
	 * @param array{type: string, name: string, wikidata?: string|null} $entity Entität.
	 * @return array<string, mixed>
	 */
	public static function node( array $entity ): array {
		$node = array(
			'@type' => self::TYPES[ $entity['type'] ] ?? 'Thing',
			// Namen aus Seitentiteln stehen teils mit HTML-Entitäten („&amp;“) in der Datenbank.
			'name'  => html_entity_decode( $entity['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		);
		$qid  = (string) ( $entity['wikidata'] ?? '' );
		if ( 1 === preg_match( '/^Q\d+$/', $qid ) ) {
			$node['sameAs'] = 'https://www.wikidata.org/wiki/' . $qid;
		}
		return $node;
	}

	/**
	 * Steht einer der Namen als ganzes Wort im Text? Groß- und Kleinschreibung egal.
	 *
	 * @param string   $text  Text ohne HTML.
	 * @param string[] $names Name und Schreibweisen.
	 * @return int|null Position des ersten Vorkommens oder null.
	 */
	public static function position( string $text, array $names ): ?int {
		$best = null;
		foreach ( $names as $name ) {
			$name = trim( $name );
			if ( mb_strlen( $name ) < 3 ) {
				continue;
			}
			if ( 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/iu', $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$pos  = (int) $m[0][1];
				$best = null === $best ? $pos : min( $best, $pos );
			}
		}
		return $best;
	}

	/**
	 * Wählt about und mentions: Haupt-Entitäten in about, die übrigen nach erstem Vorkommen in mentions.
	 * Nur Entitäten, die im Text stehen.
	 *
	 * @param string                                                                                     $text     Text ohne HTML.
	 * @param list<array{id: int, type: string, name: string, aliases: string[], wikidata: string|null}> $entities Kandidaten.
	 * @param int[]                                                                                      $primary  Haupt-Entitäten.
	 * @return array{about: list<array<string, mixed>>, mentions: list<array<string, mixed>>}
	 */
	public static function select( string $text, array $entities, array $primary ): array {
		$about    = array();
		$mentions = array();
		$seen     = array();
		foreach ( $entities as $entity ) {
			$key = mb_strtolower( ( self::TYPES[ $entity['type'] ] ?? 'Thing' ) . '|' . $entity['name'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$pos = self::position( $text, array_merge( array( $entity['name'] ), $entity['aliases'] ) );
			if ( null === $pos ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( in_array( $entity['id'], $primary, true ) ) {
				$about[] = self::node( $entity );
			} else {
				$mentions[ $pos * 1000 + count( $mentions ) ] = self::node( $entity );
			}
		}
		ksort( $mentions );
		return array(
			'about'    => $about,
			'mentions' => array_slice( array_values( $mentions ), 0, self::MAX_MENTIONS ),
		);
	}

	/**
	 * Ersetzt in mentions das eigene Unternehmen durch einen Verweis auf den Herausgeber (@id), damit Google
	 * nicht zwei Organisationen gleichen Namens sieht.
	 *
	 * @param list<array<string, mixed>> $mentions  Weitere Entitäten.
	 * @param array<string, mixed>       $publisher Knoten publisher von Rank Math.
	 * @return list<array<string, mixed>>
	 */
	public static function link_publisher( array $mentions, array $publisher ): array {
		$id   = (string) ( $publisher['@id'] ?? '' );
		$name = self::fold( html_entity_decode( (string) ( $publisher['name'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $id || '' === $name ) {
			return $mentions;
		}
		foreach ( $mentions as $i => $node ) {
			if ( 'Organization' === ( $node['@type'] ?? '' ) && self::fold( (string) ( $node['name'] ?? '' ) ) === $name ) {
				$mentions[ $i ] = array( '@id' => $id );
			}
		}
		return $mentions;
	}

	/**
	 * Name zum Vergleich: klein, ohne Satzzeichen und doppelte Leerzeichen.
	 *
	 * @param string $name Name.
	 */
	private static function fold( string $name ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $name ) ) ) );
	}

	/**
	 * Ergänzt einen Artikelknoten um about und mentions, ohne Vorhandenes zu überschreiben.
	 *
	 * @param array<string, mixed>       $node     Knoten.
	 * @param list<array<string, mixed>> $about    Haupt-Entitäten.
	 * @param list<array<string, mixed>> $mentions Weitere Entitäten.
	 * @return array<string, mixed>
	 */
	public static function article( array $node, array $about, array $mentions ): array {
		if ( array() !== $about && empty( $node['about'] ) ) {
			$node['about'] = 1 === count( $about ) ? $about[0] : $about;
		}
		if ( array() !== $mentions && empty( $node['mentions'] ) ) {
			$node['mentions'] = $mentions;
		}
		return $node;
	}

	/**
	 * Ergänzt die Organisation um knowsAbout und areaServed, ohne Vorhandenes zu überschreiben. Ist der
	 * Herausgeber eine Person, bleibt der Knoten unverändert.
	 *
	 * @param array<string, mixed>       $node     Knoten publisher.
	 * @param list<array<string, mixed>> $services Leistungen.
	 * @param list<array<string, mixed>> $area     Servicegebiet.
	 * @return array<string, mixed>
	 */
	public static function organization( array $node, array $services, array $area ): array {
		if ( self::is_type( $node, array( 'Person' ) ) ) {
			return $node;
		}
		if ( array() !== $services && empty( $node['knowsAbout'] ) ) {
			$node['knowsAbout'] = $services;
		}
		if ( array() !== $area && empty( $node['areaServed'] ) ) {
			$node['areaServed'] = 1 === count( $area ) ? $area[0] : $area;
		}
		return $node;
	}

	/**
	 * Ob der Knoten einen der Typen hat.
	 *
	 * @param array<string, mixed> $node  Knoten.
	 * @param string[]             $types Typen.
	 */
	private static function is_type( array $node, array $types ): bool {
		return array() !== array_intersect( array_map( 'strval', (array) ( $node['@type'] ?? array() ) ), $types );
	}

	/**
	 * Entitäten eines Beitrags, zwischengespeichert bis zur nächsten Änderung des Beitrags, höchstens eine Stunde.
	 *
	 * @param int $post_id Beitrag.
	 * @return array{about: list<array<string, mixed>>, mentions: list<array<string, mixed>>}
	 */
	public static function for_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'about'    => array(),
				'mentions' => array(),
			);
		}
		// Neu berechnet nach einer Änderung des Beitrags, sonst stündlich (neu bestätigte Entitäten).
		$hash   = md5( $post->post_modified_gmt . '|' . gmdate( 'YmdH' ) );
		$cached = get_post_meta( $post_id, '_lsec_schema_cache', true );
		if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash && isset( $cached['about'], $cached['mentions'] ) ) {
			return array(
				'about'    => (array) $cached['about'],
				'mentions' => (array) $cached['mentions'],
			);
		}
		$primary = array_map( 'intval', (array) json_decode( (string) get_post_meta( $post_id, '_lsec_primary_entities', true ), true ) );
		$text    = wp_strip_all_tags( $post->post_title . "\n" . $post->post_content );
		$found   = self::select( $text, self::confirmed(), $primary );
		update_post_meta(
			$post_id,
			'_lsec_schema_cache',
			$found + array( 'hash' => $hash )
		);
		return $found;
	}

	/**
	 * Bestätigte Entitäten mit Schreibweisen und Wikidata-ID (ohne Wettbewerber).
	 *
	 * @return list<array{id: int, type: string, name: string, aliases: string[], wikidata: string|null}>
	 */
	public static function confirmed(): array {
		global $wpdb;
		$table   = $wpdb->prefix . 'lsec_entities';
		$aliases = $wpdb->prefix . 'lsec_entity_aliases';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellennamen aus dem Präfix.
		$rows = (array) $wpdb->get_results( "SELECT e.id, e.type, e.canonical_name, e.external_ids, GROUP_CONCAT(a.alias SEPARATOR '\n') AS aliases FROM {$table} e LEFT JOIN {$aliases} a ON a.entity_id = e.id WHERE e.status = 'confirmed' AND e.merged_into IS NULL AND e.type <> 'competitor' GROUP BY e.id ORDER BY e.id LIMIT 2000", ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$ids   = json_decode( (string) $row['external_ids'], true );
			$out[] = array(
				'id'       => (int) $row['id'],
				'type'     => (string) $row['type'],
				'name'     => (string) $row['canonical_name'],
				'aliases'  => array_values( array_filter( explode( "\n", (string) $row['aliases'] ) ) ),
				'wikidata' => is_array( $ids ) && isset( $ids['wikidata'] ) ? (string) $ids['wikidata'] : null,
			);
		}
		return $out;
	}

	/**
	 * Leistungen aus dem Profil als Knoten.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function services(): array {
		$profile  = ( new BusinessProfile() )->get();
		$services = array();
		$wikidata = array();
		foreach ( self::confirmed() as $entity ) {
			$wikidata[ $entity['id'] ] = $entity['wikidata'];
		}
		foreach ( (array) ( $profile['services'] ?? array() ) as $service ) {
			$services[] = self::node(
				array(
					'type'     => 'concept',
					'name'     => (string) $service['name'],
					'wikidata' => $wikidata[ (int) $service['id'] ] ?? null,
				)
			);
		}
		return $services;
	}

	/**
	 * Servicegebiet als Ort mit Wikidata.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function area(): array {
		return self::area_nodes( (array) Settings::get( 'service_area' ) );
	}

	/**
	 * Servicegebiet als Knoten: der Ort und, bei einem Umkreis, ein Kreis um den Mittelpunkt (GeoCircle,
	 * Radius in Metern), damit „Uetze und 60 km Umkreis“ nicht nur als Uetze erscheint.
	 *
	 * @param array<string, mixed> $area Einstellung service_area.
	 * @return list<array<string, mixed>>
	 */
	public static function area_nodes( array $area ): array {
		if ( '' === (string) ( $area['label'] ?? '' ) ) {
			return array();
		}
		$qid    = (string) ( ( (array) ( $area['wikidata'] ?? array() ) )[0] ?? '' );
		$out    = array(
			self::node(
				array(
					'type'     => 'place',
					'name'     => (string) $area['label'],
					'wikidata' => $qid,
				)
			),
		);
		$center = (array) ( $area['center'] ?? array() );
		$radius = (float) ( $area['radius_km'] ?? 0 );
		if ( $radius > 0 && isset( $center[0], $center[1] ) && is_numeric( $center[0] ) && is_numeric( $center[1] ) ) {
			$out[] = array(
				'@type'       => 'GeoCircle',
				'geoMidpoint' => array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => round( (float) $center[0], 4 ),
					'longitude' => round( (float) $center[1], 4 ),
				),
				'geoRadius'   => (int) round( $radius * 1000 ),
			);
		}
		return $out;
	}
}
