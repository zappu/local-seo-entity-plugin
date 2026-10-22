<?php
/**
 * Baut den Keyword-Filter aus Entitäten, Profil und Ortsabgleich.
 *
 * @package Lsec
 */

namespace Lsec\Research\Keywords;

use Lsec\Entities\BusinessProfile;
use Lsec\Entities\PlaceResolver;
use Lsec\Entities\ServiceArea;
use Lsec\Infrastructure\Database\Schema;

/**
 * Verbindet KeywordFilter mit der Datenbank:
 * - Wettbewerber: Entitäten vom Typ competitor (nicht abgelehnt), mit Schreibweisen
 * - eigene Namen: Unternehmen und Marken aus dem Unternehmensprofil, mit Schreibweisen
 * - fremde Namen: bestätigte Unternehmen und Marken, die nicht aus dem Profil stammen
 * - Orte: zuerst bekannte Orts-Entitäten (abgelehnt = außerhalb, bestätigt oder wahrscheinlich = im Gebiet),
 *   sonst Ortsabgleich über Wikidata gegen das Servicegebiet (Antworten 30 Tage zwischengespeichert)
 */
final class KeywordExclusions {

	/**
	 * Zwischenergebnisse des Ortsabgleichs in diesem Lauf.
	 *
	 * @var array<string, bool|null>
	 */
	private array $places = array();

	/**
	 * Konstruktor.
	 *
	 * @param PlaceResolver|null $resolver Ortsabgleich; null = nur bekannte Orte.
	 * @param ServiceArea|null   $area     Servicegebiet; null = kein Ortsfilter.
	 * @param string             $locale   Locale der Website.
	 */
	public function __construct(
		private readonly ?PlaceResolver $resolver,
		private readonly ?ServiceArea $area,
		private readonly string $locale = 'de_DE'
	) {}

	/**
	 * Filter mit den aktuellen Entitäten.
	 */
	public function filter(): KeywordFilter {
		return new KeywordFilter(
			self::names( "e.type = 'competitor' AND e.status NOT IN ('rejected', 'merged')" ),
			self::flatten( self::names( "e.origin = '" . BusinessProfile::ORIGIN . "' AND e.type IN ('organization', 'brand') AND e.status = 'confirmed'" ) ),
			self::names( "e.origin <> '" . BusinessProfile::ORIGIN . "' AND e.type IN ('organization', 'brand') AND e.status = 'confirmed'" ),
			fn( string $place ): ?bool => $this->place_in_area( $place )
		);
	}

	/**
	 * Liegt ein Ort im Servicegebiet?
	 *
	 * @param string $place Ortsname aus dem Keyword.
	 * @return bool|null true = im Gebiet oder darüber, false = außerhalb, null = unbekannt.
	 */
	public function place_in_area( string $place ): ?bool {
		$key = KeywordFilter::fold( $place );
		if ( array_key_exists( $key, $this->places ) ) {
			return $this->places[ $key ];
		}
		$known = self::known_place( $place );
		if ( null !== $known ) {
			$this->places[ $key ] = $known;
			return $known;
		}
		if ( null === $this->resolver || null === $this->area ) {
			$this->places[ $key ] = null;
			return null;
		}
		$result = $this->resolver->resolve( $place, $this->area, $this->locale );
		$in     = array_filter( $result['candidates'], static fn( array $c ): bool => true === ( $c['in_area'] ?? null ) );
		if ( 'probable' === $result['status'] || array() !== $in ) {
			$verdict = true;
		} elseif ( array() !== $result['candidates'] ) {
			$verdict = false;
		} else {
			$verdict = null;
		}
		$this->places[ $key ] = $verdict;
		return $verdict;
	}

	/**
	 * Bekannter Ort aus den Entitäten (Name oder Schreibweise).
	 *
	 * @param string $place Ort.
	 * @return bool|null Entscheidung oder null, wenn nicht bekannt oder ungeklärt.
	 */
	private static function known_place( string $place ): ?bool {
		global $wpdb;
		$e          = Schema::table( $wpdb->prefix, 'entities' );
		$a          = Schema::table( $wpdb->prefix, 'entity_aliases' );
		$normalized = KeywordNormalizer::normalize( $place );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT e.status FROM {$e} e LEFT JOIN {$a} a ON a.entity_id = e.id
				WHERE e.type IN ('place', 'service_area') AND e.status IN ('confirmed', 'probable', 'rejected') AND ( e.normalized_name = %s OR a.normalized_alias = %s )
				ORDER BY FIELD(e.status, 'confirmed', 'rejected', 'probable') LIMIT 1",
				$normalized,
				$normalized
			)
		);
		// phpcs:enable
		if ( null === $status ) {
			return null;
		}
		return 'rejected' !== $status;
	}

	/**
	 * Namen mit Schreibweisen.
	 *
	 * @param string $where Bedingung (feste Werte, keine Eingaben).
	 * @return array<string, string[]>
	 */
	private static function names( string $where ): array {
		global $wpdb;
		$e    = Schema::table( $wpdb->prefix, 'entities' );
		$a    = Schema::table( $wpdb->prefix, 'entity_aliases' );
		$rows = (array) $wpdb->get_results( "SELECT e.canonical_name AS name, a.alias FROM {$e} e LEFT JOIN {$a} a ON a.entity_id = e.id WHERE {$where}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $row ) {
			$name         = (string) $row['name'];
			$out[ $name ] = $out[ $name ] ?? array();
			if ( null !== $row['alias'] && '' !== (string) $row['alias'] ) {
				$out[ $name ][] = (string) $row['alias'];
			}
		}
		return $out;
	}

	/**
	 * Name → Schreibweisen als flache Liste.
	 *
	 * @param array<string, string[]> $names Namen.
	 * @return string[]
	 */
	private static function flatten( array $names ): array {
		$out = array();
		foreach ( $names as $name => $aliases ) {
			$out[] = (string) $name;
			foreach ( $aliases as $alias ) {
				$out[] = $alias;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
