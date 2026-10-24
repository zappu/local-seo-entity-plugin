<?php
/**
 * Empfehlung bei doppelten Themen: welches behalten, welches ablehnen.
 *
 * @package Lsec
 */

namespace Lsec\Topics;

use Lsec\Entities\PlaceName;

/**
 * Nachtrag 24.10.2026 (0.10.2, Wunsch von Thomas): Der Vergleich doppelter Themen nennt eine Empfehlung mit Gründen.
 *
 * Regeln, der Reihe nach:
 * 1. Ein Thema, an dem schon gearbeitet wird (freigegeben, in Arbeit, geschrieben), bleibt; das andere wird
 *    abgelehnt. Bei beiden gilt der weiter fortgeschrittene Status.
 * 2. Sonst Punkte: Priorität (mindestens 3 Punkte mehr), Suchvolumen und Impressionen (je mindestens 20 Prozent
 *    mehr), Anzahl der Keywords (mindestens 2 mehr); ein Punkt Abzug, wenn ein Haupt-Keyword einen anderen Ort
 *    nennt als das Thema. Bei Gleichstand die höhere Priorität, dann das ältere Thema.
 * Dazu: Keywords des abgelehnten Themas, die dem behaltenen fehlen (werden mit „Empfehlung übernehmen“ ergänzt),
 * und Hinweise auf Haupt-Keywords mit fremdem Ort.
 *
 * Die Klasse kennt WordPress nicht.
 */
final class TwinAdvice {

	/**
	 * Fortschritt je Status.
	 */
	private const STAGE = array(
		'proposed'      => 0,
		'approved'      => 1,
		'in_production' => 2,
		'produced'      => 3,
	);

	/**
	 * Empfehlung.
	 *
	 * @param array<string, mixed> $a Thema mit id, title, status, region, priority_score, keywords (keyword, role, volume, impressions, places).
	 * @param array<string, mixed> $b Zweites Thema.
	 * @return array{keep: int, reject: int, reasons: string[], warnings: array<int, string[]>, adopt: array<int, array{id: int, keyword: string}>}
	 */
	public static function compare( array $a, array $b ): array {
		$sa = self::summary( $a );
		$sb = self::summary( $b );
		$ga = self::STAGE[ (string) $a['status'] ] ?? 0;
		$gb = self::STAGE[ (string) $b['status'] ] ?? 0;

		$reasons = array();
		if ( $ga !== $gb && max( $ga, $gb ) > 0 ) {
			$keep      = $ga > $gb ? $a : $b;
			$reasons[] = sprintf( 'An Thema #%d wird schon gearbeitet (%s).', (int) $keep['id'], self::status_label( (string) $keep['status'] ) );
		} else {
			$pa    = 0;
			$pb    = 0;
			$found = array();
			self::point( $pa, $pb, $found, (float) $sa['priority'], (float) $sb['priority'], 'absolute', 3.0, 'Priorität %s statt %s' );
			self::point( $pa, $pb, $found, (float) $sa['volume'], (float) $sb['volume'], 'relative', 0.2, '%s statt %s Suchen im Monat' );
			self::point( $pa, $pb, $found, (float) $sa['impressions'], (float) $sb['impressions'], 'relative', 0.2, '%s statt %s Impressionen' );
			self::point( $pa, $pb, $found, (float) $sa['count'], (float) $sb['count'], 'absolute', 2.0, '%s statt %s Keywords' );
			$pa -= array() === $sa['foreign'] ? 0 : 1;
			$pb -= array() === $sb['foreign'] ? 0 : 1;
			if ( $pa === $pb ) {
				$first = (float) $sa['priority'] !== (float) $sb['priority'] ? (float) $sa['priority'] > (float) $sb['priority'] : (int) $a['id'] < (int) $b['id'];
			} else {
				$first = $pa > $pb;
			}
			$keep    = $first ? $a : $b;
			$winner  = $first ? 'a' : 'b';
			$reasons = array_values(
				array_map(
					static fn( array $r ): string => $r['text'],
					array_filter( $found, static fn( array $r ): bool => $r['for'] === $winner )
				)
			);
			$own     = $first ? $sa : $sb;
			$oth     = $first ? $sb : $sa;
			if ( array() === $own['foreign'] && array() !== $oth['foreign'] ) {
				$reasons[] = 'Das andere Thema hat ein Haupt-Keyword mit einem fremden Ort.';
			}
			if ( array() === $reasons ) {
				$reasons[] = (float) $sa['priority'] === (float) $sb['priority'] ? 'Beide Themen sind etwa gleich stark; behalten wird das ältere.' : 'Beide Themen sind etwa gleich stark; behalten wird das mit der höheren Priorität.';
			}
		}
		$reject   = (int) $keep['id'] === (int) $a['id'] ? $b : $a;
		$warnings = array();
		foreach ( array( $a, $b ) as $topic ) {
			foreach ( self::summary( $topic )['foreign'] as $line ) {
				$warnings[ (int) $topic['id'] ][] = $line;
			}
		}
		return array(
			'keep'     => (int) $keep['id'],
			'reject'   => (int) $reject['id'],
			'reasons'  => $reasons,
			'warnings' => $warnings,
			'adopt'    => self::adoptable( $keep, $reject ),
		);
	}

	/**
	 * Keywords des abgelehnten Themas, die dem behaltenen fehlen: ohne Ausschluss und ohne fremden Ort.
	 *
	 * @param array<string, mixed> $keep   Behaltenes Thema.
	 * @param array<string, mixed> $reject Abgelehntes Thema.
	 * @return array<int, array{id: int, keyword: string}>
	 */
	public static function adoptable( array $keep, array $reject ): array {
		$have  = array_map( static fn( array $k ): int => (int) $k['id'], (array) ( $keep['keywords'] ?? array() ) );
		$adopt = array();
		foreach ( (array) ( $reject['keywords'] ?? array() ) as $keyword ) {
			if ( ! in_array( (int) $keyword['id'], $have, true ) && empty( $keyword['exclusion'] ) && array() === self::foreign_places( $keyword, (string) ( $keep['region'] ?? '' ) ) ) {
				$adopt[] = array(
					'id'      => (int) $keyword['id'],
					'keyword' => (string) $keyword['keyword'],
				);
			}
		}
		return $adopt;
	}

	/**
	 * Kennzahlen eines Themas.
	 *
	 * @param array<string, mixed> $topic Thema.
	 * @return array{priority: float, volume: int, impressions: int, count: int, foreign: string[]}
	 */
	public static function summary( array $topic ): array {
		$volume      = 0;
		$impressions = 0;
		$foreign     = array();
		$keywords    = array_filter( (array) ( $topic['keywords'] ?? array() ), static fn( $k ): bool => is_array( $k ) && empty( $k['exclusion'] ) );
		foreach ( $keywords as $keyword ) {
			$volume      += (int) ( $keyword['volume'] ?? 0 );
			$impressions += (int) ( $keyword['impressions'] ?? 0 );
			if ( 'primary' === ( $keyword['role'] ?? '' ) ) {
				foreach ( self::foreign_places( $keyword, (string) ( $topic['region'] ?? '' ) ) as $place ) {
					$foreign[] = sprintf( 'Haupt-Keyword „%s“ nennt einen anderen Ort (%s).', (string) $keyword['keyword'], $place );
				}
			}
		}
		return array(
			'priority'    => (float) ( $topic['priority_score'] ?? 0 ),
			'volume'      => $volume,
			'impressions' => $impressions,
			'count'       => count( $keywords ),
			'foreign'     => $foreign,
		);
	}

	/**
	 * Orte im Keyword, die nicht der Ort des Themas sind (laut Analyse der Keywords).
	 *
	 * @param array<string, mixed> $keyword Keyword mit places.
	 * @param string               $region  Ort des Themas.
	 * @return string[]
	 */
	public static function foreign_places( array $keyword, string $region ): array {
		$region_key = ' ' . PlaceName::key( $region ) . ' ';
		if ( '  ' === $region_key ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( $keyword['places'] ?? array() ) as $place ) {
			$key = PlaceName::key( (string) $place );
			if ( '' !== $key && ! str_contains( $region_key, ' ' . $key . ' ' ) && ! str_contains( ' ' . $key . ' ', $region_key ) ) {
				$out[] = (string) $place;
			}
		}
		return $out;
	}

	/**
	 * Vergibt einen Punkt, wenn ein Wert deutlich höher ist.
	 *
	 * @param int                                          $pa       Punkte A.
	 * @param int                                          $pb       Punkte B.
	 * @param array<int, array{for: string, text: string}> $reasons Gründe.
	 * @param float                                        $va       Wert A.
	 * @param float                                        $vb       Wert B.
	 * @param string                                       $mode     absolute oder relative.
	 * @param float                                        $margin   Mindestabstand.
	 * @param string                                       $text     Begründung mit zwei Platzhaltern.
	 */
	private static function point( int &$pa, int &$pb, array &$reasons, float $va, float $vb, string $mode, float $margin, string $text ): void {
		$high = max( $va, $vb );
		$low  = min( $va, $vb );
		$gap  = 'absolute' === $mode ? $high - $low : ( $high > 0 ? ( $high - $low ) / $high : 0.0 );
		if ( $high <= 0 || $gap < $margin ) {
			return;
		}
		$a_wins = $va > $vb;
		if ( $a_wins ) {
			++$pa;
		} else {
			++$pb;
		}
		$reasons[] = array(
			'for'  => $a_wins ? 'a' : 'b',
			'text' => sprintf( $text, self::number( $high ), self::number( $low ) ),
		);
	}

	/**
	 * Zahl auf Deutsch.
	 *
	 * @param float $value Zahl.
	 */
	private static function number( float $value ): string {
		return number_format( $value, 0, ',', '.' );
	}

	/**
	 * Status auf Deutsch.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		return array(
			'approved'      => 'freigegeben',
			'in_production' => 'in Arbeit',
			'produced'      => 'Entwurf erstellt',
		)[ $status ] ?? $status;
	}
}
