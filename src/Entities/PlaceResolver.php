<?php
/**
 * Ordnet Ortsnamen einem Wikidata-Eintrag zu.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Integrations\Wikidata\WikidataClient;
use Lsec\Research\Keywords\KeywordNormalizer;

/**
 * Abgleich über Kontext (Spezifikation 8.3, Prüfpunkt M18):
 * 1. Kandidaten mit gleichem Namen und Koordinaten im Land der Website
 * 2. Kandidaten, die in einem anderen Kandidaten liegen (Ortskern, Ortsteil), entfallen
 * 3. Mit Servicegebiet: Lage in einer Verwaltungseinheit des Gebiets oder innerhalb des Radius; das Gebiet
 *    selbst zählt dazu
 * 4. Passt keiner, aber genau einer ist dem Servicegebiet übergeordnet (Niedersachsen zur Region Hannover),
 *    gilt er als probable (Nachtrag 22.10.)
 * Genau ein passender Kandidat → probable, mehrere → unresolved, keiner → candidate ohne Zuordnung.
 */
final class PlaceResolver {

	/**
	 * Staat je Locale.
	 */
	public const COUNTRIES = array(
		'de_DE' => 'Q183',
		'de_AT' => 'Q40',
		'de_CH' => 'Q39',
	);

	/**
	 * Tiefe der Verwaltungskette (Ort → Kreis → Land).
	 */
	private const MAX_DEPTH = 3;

	/**
	 * Konstruktor.
	 *
	 * @param WikidataClient $wikidata Wikidata.
	 */
	public function __construct( private readonly WikidataClient $wikidata ) {}

	/**
	 * Löst einen Ortsnamen auf.
	 *
	 * @param string           $name   Ortsname.
	 * @param ServiceArea|null $area   Servicegebiet oder null.
	 * @param string           $locale Locale der Website.
	 * @return array{status: string, name: string, chosen: array<string, mixed>|null, candidates: array<int, array<string, mixed>>, reason: string}
	 */
	public function resolve( string $name, ?ServiceArea $area, string $locale = 'de_DE' ): array {
		$wanted  = KeywordNormalizer::normalize( $name );
		$country = self::COUNTRIES[ $locale ] ?? null;

		$ids  = array();
		$lang = substr( $locale, 0, 2 );
		foreach ( $this->wikidata->search( $name, $lang, 20 ) as $hit ) {
			// Nur Treffer in der Sprache der Website: „Hasle bei Burgdorf“ hat den englischen Alias „Burgdorf“.
			$by_match = $hit['match_lang'] === $lang && KeywordNormalizer::normalize( $hit['match'] ) === $wanted;
			if ( $by_match || KeywordNormalizer::normalize( $hit['label'] ) === $wanted ) {
				$ids[] = $hit['id'];
			}
		}
		$entities   = $this->wikidata->entities( $ids, $lang );
		$candidates = array_filter(
			$entities,
			static fn( array $e ): bool => null !== $e['coord'] && ( null === $country || in_array( $country, $e['country'], true ) )
		);

		$ancestors = $this->ancestors( $candidates, $country );

		// Ortskern oder Ortsteil, der in einem anderen Kandidaten liegt, entfällt.
		foreach ( array_keys( $candidates ) as $id ) {
			foreach ( array_keys( $candidates ) as $other ) {
				if ( $id !== $other && in_array( $other, $ancestors[ $id ], true ) ) {
					unset( $candidates[ $id ] );
					break;
				}
			}
		}

		$list = array();
		foreach ( $candidates as $id => $e ) {
			$distance = null !== $area && null !== $area->center && null !== $e['coord'] ? self::distance_km( $area->center, $e['coord'] ) : null;
			$in_admin = null !== $area && ( in_array( (string) $id, $area->wikidata_ids, true ) || array() !== array_intersect( $area->wikidata_ids, $ancestors[ $id ] ) );
			$in_range = null !== $distance && null !== $area && $area->radius_km > 0 && $distance <= $area->radius_km;
			$list[]   = array(
				'id'          => $id,
				'label'       => $e['label'],
				'description' => $e['description'],
				'coord'       => $e['coord'],
				'located_in'  => $ancestors[ $id ],
				'distance_km' => null === $distance ? null : round( $distance, 1 ),
				'in_area'     => null === $area ? null : ( $in_admin || $in_range ),
			);
		}

		if ( array() === $list ) {
			return array(
				'status'     => 'candidate',
				'name'       => $name,
				'chosen'     => null,
				'candidates' => array(),
				'reason'     => 'Kein Ort dieses Namens mit Koordinaten bei Wikidata gefunden.',
			);
		}
		if ( null === $area ) {
			return 1 === count( $list )
				? self::result( 'probable', $name, $list[0], $list, 'Einziger Ort dieses Namens.' )
				: self::result( 'unresolved', $name, null, $list, sprintf( '%d Orte dieses Namens, kein Servicegebiet als Kontext.', count( $list ) ) );
		}
		$matching = array_values( array_filter( $list, static fn( array $c ): bool => true === $c['in_area'] ) );
		if ( 1 === count( $matching ) ) {
			return self::result( 'probable', $name, $matching[0], $list, 'Einziger Kandidat im Servicegebiet ' . $area->label . '.' );
		}
		if ( array() === $matching ) {
			$parents  = $this->area_parents( $area, $country );
			$covering = array_values( array_filter( $list, static fn( array $c ): bool => in_array( (string) $c['id'], $parents, true ) ) );
			if ( 1 === count( $covering ) ) {
				return self::result( 'probable', $name, $covering[0], $list, 'Übergeordnetes Gebiet des Servicegebiets ' . $area->label . '.' );
			}
		}
		return self::result(
			'unresolved',
			$name,
			null,
			$list,
			array() === $matching ? 'Kein Kandidat im Servicegebiet ' . $area->label . '.' : sprintf( '%d Kandidaten im Servicegebiet, Entscheidung nötig.', count( $matching ) )
		);
	}

	/**
	 * Übergeordnete Verwaltungseinheiten je Kandidat (P131, transitiv bis MAX_DEPTH, ohne Staaten).
	 *
	 * @param array<string, array{located_in: string[], country: string[]}> $candidates Kandidaten.
	 * @param string|null                                                   $country    Staat, der nicht geladen wird.
	 * @return array<string, string[]>
	 */
	private function ancestors( array $candidates, ?string $country ): array {
		$parents = array();
		foreach ( $candidates as $id => $e ) {
			$parents[ $id ] = $e['located_in'];
		}
		$skip     = array_merge( null === $country ? array() : array( $country ), ...array_values( array_map( static fn( array $e ): array => $e['country'], $candidates ) ) );
		$frontier = array_merge( ...array_values( $parents ) );
		for ( $depth = 1; $depth < self::MAX_DEPTH && array() !== $frontier; $depth++ ) {
			$load     = array_values( array_diff( array_unique( $frontier ), array_keys( $parents ), $skip ) );
			$frontier = array();
			foreach ( $this->wikidata->entities( $load ) as $id => $e ) {
				$parents[ $id ] = $e['located_in'];
				$frontier       = array_merge( $frontier, $e['located_in'] );
			}
		}

		$out = array();
		foreach ( array_keys( $candidates ) as $id ) {
			$seen  = array();
			$queue = $parents[ $id ];
			while ( array() !== $queue ) {
				$next = (string) array_shift( $queue );
				if ( isset( $seen[ $next ] ) ) {
					continue;
				}
				$seen[ $next ] = true;
				foreach ( $parents[ $next ] ?? array() as $parent ) {
					$queue[] = $parent;
				}
			}
			$out[ (string) $id ] = array_keys( $seen );
		}
		return $out;
	}

	/**
	 * Übergeordnete Verwaltungseinheiten des Servicegebiets (ohne Staat). Eine Woche zwischengespeichert,
	 * weil Wikidata schnell „zu viele Anfragen“ meldet.
	 *
	 * @param ServiceArea $area    Servicegebiet.
	 * @param string|null $country Staat.
	 * @return string[]
	 */
	private function area_parents( ServiceArea $area, ?string $country ): array {
		if ( array() === $area->wikidata_ids ) {
			return array();
		}
		$key = 'lsec_area_parents_' . md5( implode( ',', $area->wikidata_ids ) );
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return array_map( 'strval', $cached );
			}
		}
		$areas = $this->wikidata->entities( $area->wikidata_ids );
		$out   = array() === $areas ? array() : array_values( array_unique( array_merge( ...array_values( $this->ancestors( $areas, $country ) ) ) ) );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $out, WEEK_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * Ergebnis zusammensetzen.
	 *
	 * @param string                           $status     Status.
	 * @param string                           $name       Name.
	 * @param array<string, mixed>|null        $chosen     Gewählter Kandidat.
	 * @param array<int, array<string, mixed>> $candidates Alle Kandidaten.
	 * @param string                           $reason     Begründung.
	 * @return array{status: string, name: string, chosen: array<string, mixed>|null, candidates: array<int, array<string, mixed>>, reason: string}
	 */
	private static function result( string $status, string $name, ?array $chosen, array $candidates, string $reason ): array {
		return array(
			'status'     => $status,
			'name'       => $name,
			'chosen'     => $chosen,
			'candidates' => $candidates,
			'reason'     => $reason,
		);
	}

	/**
	 * Entfernung in Kilometern (Haversine).
	 *
	 * @param array{0: float, 1: float} $a Breite, Länge.
	 * @param array{0: float, 1: float} $b Breite, Länge.
	 */
	public static function distance_km( array $a, array $b ): float {
		$lat1 = deg2rad( $a[0] );
		$lat2 = deg2rad( $b[0] );
		$dlat = $lat2 - $lat1;
		$dlon = deg2rad( $b[1] - $a[1] );
		$h    = sin( $dlat / 2 ) ** 2 + cos( $lat1 ) * cos( $lat2 ) * sin( $dlon / 2 ) ** 2;
		return 6371.0 * 2 * asin( min( 1.0, sqrt( $h ) ) );
	}
}
