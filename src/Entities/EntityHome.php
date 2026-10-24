<?php
/**
 * Hauptseite je Entität (Entity Home).
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Content\ContentIndex;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Research\Keywords\KeywordFilter;
use Lsec\Topics\TopicResearchWorkflow;

/**
 * Je Entität des Unternehmens eine feste Hauptseite (Nachtrag 24.10.2026, 1.2.0, nach Casey Keith, „Step-by-Step
 * Entity SEO“, Baustein „Entity Home“): die eine eigene Seite, die eine Leistung, das Unternehmen, einen Standort
 * oder eine Person maßgeblich beschreibt.
 *
 * - Das Plugin schlägt sie vor (Titel, Adresse, Fundstellen der Entität, Impressionen in der Search Console),
 *   festgelegt wird sie von Hand wie eine Entität bestätigt.
 * - Entwürfe verlinken die Hauptseite ihrer Haupt-Entität; strukturierte Daten nennen sie als url der Entität.
 * - Der Bericht nennt Entitäten ohne Hauptseite und Suchanfragen, für die mehrere eigene Seiten erscheinen.
 *
 * Gespeichert in der Option lsec_entity_homes (Entität → Seite), damit die Ausgabe der strukturierten Daten auf
 * jeder Seite ohne Abfrage der Entitäten auskommt.
 */
final class EntityHome {

	public const OPTION = 'lsec_entity_homes';

	/**
	 * Typen, für die das Plugin Hauptseiten vorschlägt: das Unternehmen, seine Standorte, Leistungen und eigenen
	 * Marken (aus dem Profil) sowie bestätigte Personen und Produkte.
	 */
	public const TYPES = array( 'organization', 'place', 'service', 'brand', 'person', 'product' );

	/**
	 * Mindestpunktzahl für einen Vorschlag.
	 */
	public const MIN_SCORE = 5;

	/**
	 * Mindestzahl an Impressionen je Seite, damit sie als Konkurrenz zählt.
	 */
	public const MIN_IMPRESSIONS = 10;

	/**
	 * Pfade von Seiten über das Unternehmen.
	 */
	private const ABOUT = array( 'ueber-uns', 'uber-uns', 'about', 'unternehmen', 'firma', 'wir', 'praxis', 'team', 'philosophie' );

	/**
	 * Pfade von Kontakt- und Standortseiten.
	 */
	private const CONTACT = array( 'kontakt', 'standort', 'anfahrt', 'filiale', 'contact' );

	/**
	 * Alle Hauptseiten: Entität → Angaben.
	 *
	 * @return array<int, array{post_id: int, set_by: int, set_at: string, source: string}>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id => $home ) {
			if ( is_array( $home ) && (int) ( $home['post_id'] ?? 0 ) > 0 ) {
				$out[ (int) $id ] = array(
					'post_id' => (int) $home['post_id'],
					'set_by'  => (int) ( $home['set_by'] ?? 0 ),
					'set_at'  => (string) ( $home['set_at'] ?? '' ),
					'source'  => (string) ( $home['source'] ?? 'manual' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Veröffentlichte Hauptseiten der Entitäten.
	 *
	 * @param int[]|null $ids Entitäten (null = alle).
	 * @return array<int, array{post_id: int, title: string, url: string}>
	 */
	public static function homes( ?array $ids = null ): array {
		$out = array();
		$ids = null === $ids ? null : array_map( 'intval', $ids );
		foreach ( self::all() as $id => $home ) {
			if ( null !== $ids && ! in_array( $id, $ids, true ) ) {
				continue;
			}
			$page = self::page( $home['post_id'] );
			if ( null !== $page ) {
				$out[ $id ] = $page;
			}
		}
		return $out;
	}

	/**
	 * Entitäten, deren Hauptseite dieser Beitrag ist.
	 *
	 * @param int $post_id Beitrag.
	 * @return int[]
	 */
	public static function entities_for_post( int $post_id ): array {
		$out = array();
		foreach ( self::all() as $id => $home ) {
			if ( $home['post_id'] === $post_id ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Veröffentlichte Seite mit Titel und Adresse, sonst null.
	 *
	 * @param int $post_id Beitrag.
	 * @return array{post_id: int, title: string, url: string}|null
	 */
	public static function page( int $post_id ): ?array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}
		return array(
			'post_id' => $post_id,
			'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'     => (string) get_permalink( $post ),
		);
	}

	/**
	 * Legt die Hauptseite fest oder entfernt sie (post_id 0).
	 *
	 * @param int    $entity_id Entität.
	 * @param int    $post_id   Seite oder 0.
	 * @param int    $user_id   Benutzer.
	 * @param string $source    suggestion oder manual.
	 * @return array{post_id: int, title: string, url: string}|null Die Seite oder null nach dem Entfernen.
	 * @throws \InvalidArgumentException Bei unbekannter Entität oder Seite.
	 */
	public static function set( int $entity_id, int $post_id, int $user_id, string $source = 'manual' ): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'entities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus dem Präfix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, type, status FROM {$table} WHERE id = %d", $entity_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new \InvalidArgumentException( 'Entität nicht gefunden.' );
		}
		$homes = self::all();
		if ( 0 === $post_id ) {
			unset( $homes[ $entity_id ] );
			update_option( self::OPTION, $homes, true );
			return null;
		}
		if ( 'confirmed' !== $row['status'] || 'competitor' === $row['type'] ) {
			throw new \InvalidArgumentException( 'Eine Hauptseite bekommen nur bestätigte Entitäten, keine Wettbewerber.' );
		}
		$page = self::page( $post_id );
		if ( null === $page || ! in_array( (string) get_post_type( $post_id ), ContentIndex::post_types(), true ) ) {
			throw new \InvalidArgumentException( 'Hauptseite kann nur eine veröffentlichte Seite, ein Beitrag oder ein Produkt dieser Website sein.' );
		}
		$homes[ $entity_id ] = array(
			'post_id' => $post_id,
			'set_by'  => $user_id,
			'set_at'  => gmdate( 'Y-m-d H:i:s' ),
			'source'  => 'suggestion' === $source ? 'suggestion' : 'manual',
		);
		update_option( self::OPTION, $homes, true );
		return $page;
	}

	/**
	 * Nach dem Zusammenführen: Die Hauptseite einer Quelle geht an das Ziel, wenn es noch keine hat.
	 *
	 * @param int   $target  Ziel.
	 * @param int[] $sources Zusammengeführte Quellen.
	 */
	public static function merged( int $target, array $sources ): void {
		$homes = self::all();
		$found = false;
		foreach ( $sources as $source ) {
			if ( isset( $homes[ $source ] ) ) {
				$homes[ $target ] ??= $homes[ $source ];
				unset( $homes[ $source ] );
				$found = true;
			}
		}
		if ( $found ) {
			update_option( self::OPTION, $homes, true );
		}
	}

	/**
	 * Nach dem Löschen: Hauptseiten der Entitäten entfernen.
	 *
	 * @param int[] $ids Entitäten.
	 */
	public static function forget( array $ids ): void {
		$homes = self::all();
		$left  = array_diff_key( $homes, array_flip( $ids ) );
		if ( count( $left ) !== count( $homes ) ) {
			update_option( self::OPTION, $left, true );
		}
	}

	/**
	 * Übersicht für die Seite „Entitäten“ und den Bericht: Entitäten des Unternehmens mit Hauptseite oder
	 * Vorschlägen, dazu Suchanfragen mit mehreren eigenen Seiten.
	 *
	 * @return array{items: list<array<string, mixed>>, competing: list<array<string, mixed>>, counts: array{total: int, set: int, suggested: int, none: int, stale: int}}
	 */
	public static function overview(): array {
		$entities = self::eligible();
		$homes    = self::all();
		$pages    = self::candidates();
		$rows     = self::gsc_rows();
		$mentions = self::mention_counts( array_keys( $entities ) );
		$items    = array();
		$counts   = array(
			'total'     => count( $entities ),
			'set'       => 0,
			'suggested' => 0,
			'none'      => 0,
			'stale'     => 0,
		);
		foreach ( $entities as $id => $entity ) {
			$home = isset( $homes[ $id ] ) ? self::page( $homes[ $id ]['post_id'] ) : null;
			$item = $entity + array(
				'home'        => $home,
				'stale'       => isset( $homes[ $id ] ) && null === $home,
				'suggestions' => array(),
			);
			if ( null === $home ) {
				$item['suggestions'] = self::suggest( $entity, $pages, $mentions[ $id ] ?? array(), self::impressions( $entity, $rows ) );
			}
			if ( null !== $home ) {
				++$counts['set'];
			} elseif ( $item['stale'] ) {
				++$counts['stale'];
			} elseif ( array() !== $item['suggestions'] ) {
				++$counts['suggested'];
			} else {
				++$counts['none'];
			}
			$items[] = $item;
		}
		$home_ids = array();
		foreach ( $homes as $id => $home ) {
			$home_ids[ $id ] = $home['post_id'];
		}
		return array(
			'items'     => $items,
			// Orte und das Unternehmen selbst nicht: Suchen mit dem Ort oder dem Firmennamen zeigen zu Recht mehrere Seiten.
			'competing' => self::competition( $rows, array_values( array_filter( $entities, static fn( array $e ): bool => ! in_array( $e['type'], array( 'place', 'organization' ), true ) ) ), $home_ids ),
			'counts'    => $counts,
		);
	}

	/**
	 * Vorschläge für eine Entität (auch für die Auswahl im Detail).
	 *
	 * @param int $entity_id Entität.
	 * @return list<array<string, mixed>>
	 */
	public static function suggestions_for( int $entity_id ): array {
		$entity = self::entity( $entity_id );
		if ( null === $entity ) {
			return array();
		}
		$mentions = self::mention_counts( array( $entity_id ) );
		return self::suggest( $entity, self::candidates(), $mentions[ $entity_id ] ?? array(), self::impressions( $entity, self::gsc_rows() ) );
	}

	/**
	 * Eigene Inhalte nach Titel für die Auswahl von Hand.
	 *
	 * @param string $search Suchbegriff.
	 * @return list<array{post_id: int, title: string, url: string, kind: string}>
	 */
	public static function search( string $search ): array {
		$posts = get_posts(
			array(
				'post_type'        => ContentIndex::post_types(),
				'post_status'      => 'publish',
				's'                => $search,
				'posts_per_page'   => 10,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$out[] = array(
					'post_id' => (int) $post->ID,
					'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'url'     => (string) get_permalink( $post ),
					'kind'    => (string) $post->post_type,
				);
			}
		}
		return $out;
	}

	/**
	 * Wählt die besten Seiten für eine Entität (höchstens drei, mindestens MIN_SCORE Punkte).
	 *
	 * @param array<string, mixed>       $entity      Entität (type, name, aliases, location).
	 * @param list<array<string, mixed>> $pages       Kandidaten (post_id, title, url, path, kind, front).
	 * @param array<int, int>            $mentions    Fundstellen je Beitrag.
	 * @param array<int, int>            $impressions Impressionen je Beitrag für Anfragen mit dem Namen.
	 * @return list<array<string, mixed>>
	 */
	public static function suggest( array $entity, array $pages, array $mentions, array $impressions ): array {
		$scored = array();
		foreach ( $pages as $page ) {
			$id   = (int) $page['post_id'];
			$page = $page + array(
				'mentions'    => $mentions[ $id ] ?? 0,
				'impressions' => $impressions[ $id ] ?? 0,
			);
			$rate = self::score( $entity, $page );
			if ( $rate['score'] >= self::MIN_SCORE && $rate['named'] ) {
				$scored[] = array(
					'post_id' => $id,
					'title'   => (string) $page['title'],
					'url'     => (string) $page['url'],
					'kind'    => (string) $page['kind'],
					'score'   => $rate['score'],
					'reasons' => $rate['reasons'],
				);
			}
		}
		usort( $scored, static fn( array $a, array $b ): int => array( $b['score'], $a['post_id'] ) <=> array( $a['score'], $b['post_id'] ) );
		return array_slice( $scored, 0, 3 );
	}

	/**
	 * Punkte einer Seite als Hauptseite einer Entität. named: Die Seite trägt den Namen (oder ist Startseite bzw.
	 * Über-uns-Seite des Unternehmens); ohne das wird sie nicht vorgeschlagen, auch wenn sie die Entität oft nennt.
	 *
	 * @param array<string, mixed> $entity Entität (type, name, aliases, location).
	 * @param array<string, mixed> $page   Seite (title, path, kind, front, mentions, impressions).
	 * @return array{score: int, named: bool, reasons: list<string>}
	 */
	public static function score( array $entity, array $page ): array {
		$score   = 0;
		$named   = false;
		$reasons = array();
		$title   = ' ' . KeywordFilter::fold( (string) $page['title'] ) . ' ';
		$path    = ' ' . KeywordFilter::fold( str_replace( array( '-', '/', '_' ), ' ', (string) $page['path'] ) ) . ' ';
		$names   = array_filter( array_map( static fn( $n ): string => KeywordFilter::fold( (string) $n ), array_merge( array( (string) $entity['name'] ), (array) ( $entity['aliases'] ?? array() ) ) ) );
		$in      = static function ( string $hay ) use ( $names ): bool {
			foreach ( $names as $name ) {
				if ( mb_strlen( $name ) >= 3 && str_contains( $hay, ' ' . $name . ' ' ) ) {
					return true;
				}
			}
			return false;
		};
		$stems   = self::stems( (string) $entity['name'] );
		$found   = array_filter( $stems, static fn( string $s ): bool => str_contains( $title . $path, ' ' . $s ) );
		$type    = (string) $entity['type'];

		if ( $in( $title ) ) {
			$score    += 6;
			$named     = true;
			$reasons[] = 'Name im Titel';
			// Hauptseiten tragen den Namen vorn („Fassadenreinigung“, „Fassadenreinigung in Celle“), Ratgeber selten.
			foreach ( $names as $name ) {
				if ( str_starts_with( trim( $title ), $name ) ) {
					$score    += 2;
					$reasons[] = 'Titel beginnt mit dem Namen';
					break;
				}
			}
		} elseif ( $in( $path ) ) {
			$score    += 5;
			$named     = true;
			$reasons[] = 'Name in der Adresse';
		} elseif ( array() !== $stems && count( $found ) === count( $stems ) ) {
			$score    += 4;
			$named     = true;
			$reasons[] = 'Wörter des Namens im Titel';
		} elseif ( count( $stems ) > 1 && 2 * count( $found ) >= count( $stems ) ) {
			$score    += 2;
			$reasons[] = 'Teil des Namens im Titel';
		}
		$slug = trim( $path );
		if ( 'organization' === $type ) {
			if ( ! empty( $page['front'] ) ) {
				$score    += 6;
				$named     = true;
				$reasons[] = 'Startseite';
			} elseif ( self::path_has( $slug, self::ABOUT ) ) {
				$score    += 5;
				$named     = true;
				$reasons[] = 'Seite über das Unternehmen';
			}
		}
		if ( 'place' === $type && ! empty( $entity['location'] ) && self::path_has( $slug, self::CONTACT ) ) {
			$score += 3;
			// Standortnamen („Hauptsitz Uetze“) stehen selten im Titel; die Kontaktseite ist trotzdem die übliche Hauptseite.
			$named     = true;
			$reasons[] = 'Kontakt- oder Standortseite';
		}
		if ( 'person' === $type && self::path_has( $slug, array( 'team', 'ueber', 'about', 'praxis' ) ) ) {
			++$score;
			$reasons[] = 'Team- oder Über-uns-Seite';
		}
		if ( in_array( (string) $page['kind'], array( 'page', 'product' ), true ) ) {
			$score    += 2;
			$reasons[] = 'product' === $page['kind'] ? 'Produkt' : 'Seite (kein Blogbeitrag)';
		}
		$mentions = (int) ( $page['mentions'] ?? 0 );
		if ( $mentions > 0 ) {
			$score    += min( 2, $mentions );
			$reasons[] = 1 === $mentions ? '1 Fundstelle' : $mentions . ' Fundstellen';
		}
		$impressions = (int) ( $page['impressions'] ?? 0 );
		if ( $impressions >= 10 ) {
			$score    += $impressions >= 100 ? 2 : 1;
			$reasons[] = $impressions . ' Impressionen für Suchen mit dem Namen';
		}
		return array(
			'score'   => $score,
			'named'   => $named,
			'reasons' => $reasons,
		);
	}

	/**
	 * Wortstämme eines Namens: erste sechs Buchstaben der Wörter ab fünf Buchstaben (gefaltet).
	 *
	 * @param string $name Name.
	 * @return list<string>
	 */
	public static function stems( string $name ): array {
		$out = array();
		foreach ( explode( ' ', KeywordFilter::fold( $name ) ) as $word ) {
			if ( mb_strlen( $word ) >= 5 ) {
				$out[] = mb_substr( $word, 0, 6 );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Ob eine Suchanfrage die Entität meint: Name oder Schreibweise darin, sonst alle Wortstämme.
	 *
	 * @param string               $keyword Suchanfrage.
	 * @param array<string, mixed> $entity  Entität (name, aliases).
	 */
	public static function matches( string $keyword, array $entity ): bool {
		$hay = ' ' . KeywordFilter::fold( $keyword ) . ' ';
		foreach ( array_merge( array( (string) $entity['name'] ), (array) ( $entity['aliases'] ?? array() ) ) as $name ) {
			$name = KeywordFilter::fold( (string) $name );
			if ( mb_strlen( $name ) >= 3 && str_contains( $hay, ' ' . $name . ' ' ) ) {
				return true;
			}
		}
		$stems = self::stems( (string) $entity['name'] );
		if ( array() === $stems ) {
			return false;
		}
		foreach ( $stems as $stem ) {
			// Auch am Wortende: „Zahnprophylaxe“ zu „Prophylaxe“.
			if ( ! preg_match( '/' . preg_quote( $stem, '/' ) . '/u', $hay ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Suchanfragen, für die mehrere eigene Seiten Impressionen haben (Seiten machen sich Konkurrenz).
	 *
	 * @param list<array{keyword: string, url: string, post_id: int, impressions: int, position: float}> $rows     Zeilen der Search Console.
	 * @param list<array<string, mixed>>                                                                 $entities Entitäten (id, name, aliases).
	 * @param array<int, int>                                                                            $homes    Entität → Hauptseite.
	 * @return list<array<string, mixed>>
	 */
	public static function competition( array $rows, array $entities, array $homes ): array {
		$by = array();
		foreach ( $rows as $row ) {
			if ( (int) $row['impressions'] < self::MIN_IMPRESSIONS ) {
				continue;
			}
			foreach ( $entities as $entity ) {
				if ( self::matches( (string) $row['keyword'], $entity ) ) {
					$by[ (int) $entity['id'] ][ (string) $row['keyword'] ][ (string) $row['url'] ] = $row;
				}
			}
		}
		$names = array();
		foreach ( $entities as $entity ) {
			$names[ (int) $entity['id'] ] = $entity;
		}
		$out = array();
		foreach ( $by as $id => $queries ) {
			$home    = $homes[ $id ] ?? 0;
			$list    = array();
			$total   = 0;
			$urls    = array();
			$foreign = array();
			foreach ( $queries as $keyword => $pages ) {
				if ( count( $pages ) < 2 ) {
					continue;
				}
				usort( $pages, static fn( array $a, array $b ): int => (int) $b['impressions'] <=> (int) $a['impressions'] );
				$items = array();
				foreach ( $pages as $page ) {
					$total               += (int) $page['impressions'];
					$urls[ $page['url'] ] = true;
					$is_home              = $home > 0 && (int) $page['post_id'] === $home;
					$items[]              = array(
						'url'         => (string) $page['url'],
						'post_id'     => (int) $page['post_id'],
						'impressions' => (int) $page['impressions'],
						'position'    => round( (float) $page['position'], 1 ),
						'home'        => $is_home,
					);
					if ( ! $is_home ) {
						$foreign[ $page['url'] ] = true;
					}
				}
				$list[] = array(
					'keyword' => (string) $keyword,
					'pages'   => $items,
				);
			}
			if ( array() === $list ) {
				continue;
			}
			$out[] = array(
				'entity_id'    => $id,
				'name'         => (string) ( $names[ $id ]['name'] ?? '' ),
				'type'         => (string) ( $names[ $id ]['type'] ?? '' ),
				'home_post_id' => $home,
				'queries'      => array_slice( $list, 0, 5 ),
				'query_count'  => count( $list ),
				'pages'        => count( $urls ),
				'impressions'  => $total,
				'advice'       => $home > 0
					? sprintf( '%d weitere eigene Seite(n) erscheinen für dieselben Suchen wie die Hauptseite. Diese Seiten sollten auf die Hauptseite verlinken und ein anderes Teilthema behandeln; ist eine nur eine schwächere Fassung derselben Seite, mit der Hauptseite zusammenführen und weiterleiten (301).', count( $foreign ) )
					: 'Mehrere eigene Seiten erscheinen für dieselben Suchen. Eine davon als Hauptseite festlegen (meist die mit der besseren Position); die anderen verlinken dann auf sie.',
			);
		}
		usort( $out, static fn( array $a, array $b ): int => $b['impressions'] <=> $a['impressions'] );
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Ob ein Pfad eines der Wörter als ganzes Segment enthält.
	 *
	 * @param string   $path  Pfad (gefaltet, Leerzeichen statt Trennzeichen).
	 * @param string[] $words Wörter (mit Bindestrich erlaubt).
	 */
	private static function path_has( string $path, array $words ): bool {
		$hay = ' ' . $path . ' ';
		foreach ( $words as $word ) {
			if ( str_contains( $hay, ' ' . str_replace( '-', ' ', $word ) . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Entitäten mit Vorschlägen: aus dem Profil (Unternehmen, Standorte, Leistungen, eigene Marken) und bestätigte
	 * Personen und Produkte.
	 *
	 * @return array<int, array{id: int, name: string, type: string, origin: string, aliases: string[], location: bool}>
	 */
	public static function eligible(): array {
		global $wpdb;
		$e = Schema::table( $wpdb->prefix, 'entities' );
		$a = Schema::table( $wpdb->prefix, 'entity_aliases' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellennamen aus dem Präfix.
		$rows = (array) $wpdb->get_results( "SELECT e.id, e.type, e.canonical_name, e.origin, e.properties, GROUP_CONCAT(a.alias SEPARATOR '\n') AS aliases FROM {$e} e LEFT JOIN {$a} a ON a.entity_id = e.id WHERE e.status = 'confirmed' AND e.merged_into IS NULL AND ( ( e.origin = 'business_profile' AND e.type IN ('organization', 'place', 'service', 'brand') ) OR e.type IN ('person', 'product') ) GROUP BY e.id ORDER BY FIELD(e.type, 'organization', 'service', 'place', 'person', 'product', 'brand'), e.canonical_name LIMIT 200", ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$properties = json_decode( (string) $row['properties'], true );
			$properties = is_array( $properties ) ? $properties : array();
			if ( 'brand' === $row['type'] && empty( $properties['own'] ) ) {
				continue;
			}
			$out[ (int) $row['id'] ] = self::cast( $row, $properties );
		}
		return $out;
	}

	/**
	 * Eine Entität für Vorschläge (auch außerhalb der vorgeschlagenen Typen).
	 *
	 * @param int $id Entität.
	 * @return array{id: int, name: string, type: string, origin: string, aliases: string[], location: bool}|null
	 */
	private static function entity( int $id ): ?array {
		global $wpdb;
		$e = Schema::table( $wpdb->prefix, 'entities' );
		$a = Schema::table( $wpdb->prefix, 'entity_aliases' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellennamen aus dem Präfix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT e.id, e.type, e.canonical_name, e.origin, e.properties, GROUP_CONCAT(a.alias SEPARATOR '\n') AS aliases FROM {$e} e LEFT JOIN {$a} a ON a.entity_id = e.id WHERE e.id = %d GROUP BY e.id", $id ), ARRAY_A );
		if ( ! is_array( $row ) || null === $row['id'] ) {
			return null;
		}
		$properties = json_decode( (string) $row['properties'], true );
		return self::cast( $row, is_array( $properties ) ? $properties : array() );
	}

	/**
	 * Zeile der Datenbank als Entität.
	 *
	 * @param array<string, mixed> $row        Zeile.
	 * @param array<string, mixed> $properties Eigenschaften.
	 * @return array{id: int, name: string, type: string, origin: string, aliases: string[], location: bool}
	 */
	private static function cast( array $row, array $properties ): array {
		return array(
			'id'       => (int) $row['id'],
			'name'     => html_entity_decode( (string) $row['canonical_name'], ENT_QUOTES, 'UTF-8' ),
			'type'     => (string) $row['type'],
			'origin'   => (string) $row['origin'],
			'aliases'  => array_values( array_filter( explode( "\n", (string) $row['aliases'] ) ) ),
			'location' => 'place' === $row['type'] && ( 'location' === ( $properties['kind'] ?? '' ) || BusinessProfile::ORIGIN === $row['origin'] ),
		);
	}

	/**
	 * Kandidaten: veröffentlichte Inhalte aus dem Inhaltsindex.
	 *
	 * @return list<array{post_id: int, title: string, url: string, path: string, kind: string, front: bool}>
	 */
	private static function candidates(): array {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$out   = array();
		foreach ( ContentIndex::entries( 500 ) as $entry ) {
			if ( (int) $entry['post_id'] <= 0 ) {
				continue;
			}
			$out[] = array(
				'post_id' => (int) $entry['post_id'],
				'title'   => html_entity_decode( (string) $entry['title'], ENT_QUOTES, 'UTF-8' ),
				'url'     => (string) $entry['url'],
				// Mit dem Kurznamen (Slug), falls die Website keine sprechenden Adressen nutzt (?page_id=).
				'path'    => (string) wp_parse_url( (string) $entry['url'], PHP_URL_PATH ) . '/' . (string) get_post_field( 'post_name', (int) $entry['post_id'] ),
				'kind'    => (string) $entry['kind'],
				'front'   => $front > 0 && (int) $entry['post_id'] === $front,
			);
		}
		return $out;
	}

	/**
	 * Fundstellen je Entität und Beitrag.
	 *
	 * @param int[] $ids Entitäten.
	 * @return array<int, array<int, int>>
	 */
	private static function mention_counts( array $ids ): array {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( array() === $ids ) {
			return array();
		}
		$m  = Schema::table( $wpdb->prefix, 'mentions' );
		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus dem Präfix, IDs als Zahlen.
		$rows = (array) $wpdb->get_results( "SELECT entity_id, object_id, COUNT(*) AS n FROM {$m} WHERE object_type = 'post' AND entity_id IN ({$in}) GROUP BY entity_id, object_id", ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['entity_id'] ][ (int) $row['object_id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Zeilen der Search Console aus dem letzten Zeitraum, mit eigener Seite.
	 *
	 * @return list<array{keyword: string, url: string, post_id: int, impressions: int, position: float}>
	 */
	public static function gsc_rows(): array {
		global $wpdb;
		$k  = Schema::table( $wpdb->prefix, 'keywords' );
		$km = Schema::table( $wpdb->prefix, 'keyword_metrics' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest = (string) $wpdb->get_var( "SELECT MAX(period_end) FROM {$km} WHERE source = 'gsc'" );
		$rows   = '' === $latest ? array() : (array) $wpdb->get_results( $wpdb->prepare( "SELECT kw.keyword, m.page_url, m.impressions, m.position FROM {$km} m JOIN {$k} kw ON kw.id = m.keyword_id WHERE m.source = 'gsc' AND m.period_end = %s AND m.page_url <> '' AND m.impressions > 0 ORDER BY m.impressions DESC LIMIT 3000", $latest ), ARRAY_A );
		// phpcs:enable
		$out  = array();
		$post = array();
		foreach ( $rows as $row ) {
			$local = TopicResearchWorkflow::own_url( (string) $row['page_url'] );
			if ( null === $local ) {
				continue;
			}
			$post[ $local ] ??= url_to_postid( $local );
			$out[]            = array(
				'keyword'     => (string) $row['keyword'],
				'url'         => $local,
				'post_id'     => (int) $post[ $local ],
				'impressions' => (int) $row['impressions'],
				'position'    => (float) $row['position'],
			);
		}
		return $out;
	}

	/**
	 * Impressionen je Beitrag für Suchanfragen, die die Entität meinen.
	 *
	 * @param array<string, mixed>                                                                       $entity Entität.
	 * @param list<array{keyword: string, url: string, post_id: int, impressions: int, position: float}> $rows   Zeilen.
	 * @return array<int, int>
	 */
	public static function impressions( array $entity, array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( $row['post_id'] > 0 && self::matches( $row['keyword'], $entity ) ) {
				$out[ $row['post_id'] ] = ( $out[ $row['post_id'] ] ?? 0 ) + $row['impressions'];
			}
		}
		return $out;
	}
}
