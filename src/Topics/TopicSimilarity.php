<?php
/**
 * Erkennt doppelte Themen.
 *
 * @package Lsec
 */

namespace Lsec\Topics;

use Lsec\Research\Keywords\KeywordFilter;

/**
 * Nachtrag 22.10. (0.7.1): Zwei Recherchen schlugen „Pflasterreinigung in Uetze: Gehwege und Pflasterflächen
 * vom Profi reinigen lassen“ und „Pflasterreinigung in Uetze: Firma in der Nähe für Einfahrt, Hof und Wege“
 * vor; beide wurden freigegeben und hätten als zwei Seiten gegeneinander gerankt.
 *
 * Zwei Themen sind Dubletten, wenn sie dieselbe Art haben (lokale Seite oder Inhalt) und
 * - mit Ort: derselbe Ort und dieselbe Hauptleistung (die erste Leistung aus dem Profil, die im Titel oder
 *   Haupt-Keyword steht), oder
 * - die Titel zu mindestens 50 % dieselben Wörter haben (ohne Ort und Füllwörter), bei gleichem Ort.
 *
 * Die Klasse kennt WordPress nicht.
 */
final class TopicSimilarity {

	/**
	 * Wörter ohne Unterscheidungskraft in Titeln.
	 */
	private const FILLER = array( 'lassen', 'profi', 'profis', 'firma', 'fachbetrieb', 'fachfirma', 'naehe', 'umgebung', 'region', 'gemeinde', 'stadt', 'raum', 'professionell', 'reinigen', 'reinigung', 'sauber', 'richtig', 'tipps', 'ihre', 'ihren', 'fuer', 'oder', 'eine', 'einer', 'wann', 'warum', 'lohnt', 'sich', 'vom', 'und', 'mit', 'der', 'die', 'das', 'den', 'von' );

	/**
	 * Merkmale eines Themas.
	 *
	 * @param array<string, mixed> $topic    Thema mit title, region, format und primary_keywords (Text oder Liste).
	 * @param string[]             $services Leistungen aus dem Profil.
	 * @return array{region: string, kind: string, service: string, words: string[]}
	 */
	public static function signature( array $topic, array $services ): array {
		$region   = self::region( (string) ( $topic['region'] ?? '' ) );
		$keywords = is_array( $topic['primary_keywords'] ?? null ) ? implode( ' ', array_map( 'strval', $topic['primary_keywords'] ) ) : str_replace( '|', ' ', (string) ( $topic['primary_keywords'] ?? '' ) );
		$title    = KeywordFilter::fold( (string) ( $topic['title'] ?? '' ) );
		$hay      = ' ' . $title . ' ' . KeywordFilter::fold( $keywords ) . ' ';
		$service  = '';
		$best     = PHP_INT_MAX;
		foreach ( $services as $name ) {
			$pos = self::position( $hay, (string) $name );
			if ( null !== $pos && $pos < $best ) {
				$best    = $pos;
				$service = KeywordFilter::fold( (string) $name );
			}
		}
		$skip  = array_merge( self::FILLER, explode( ' ', $region ) );
		$words = array();
		foreach ( explode( ' ', $title ) as $word ) {
			if ( mb_strlen( $word ) >= 4 && ! in_array( $word, $skip, true ) ) {
				$words[ mb_substr( $word, 0, 10 ) ] = true;
			}
		}
		return array(
			'region'  => $region,
			'kind'    => 'local_landing' === (string) ( $topic['format'] ?? '' ) ? 'local' : 'content',
			'service' => $service,
			'words'   => array_keys( $words ),
		);
	}

	/**
	 * Sind zwei Themen Dubletten?
	 *
	 * @param array<string, mixed> $a        Thema.
	 * @param array<string, mixed> $b        Thema.
	 * @param string[]             $services Leistungen aus dem Profil.
	 */
	public static function same( array $a, array $b, array $services ): bool {
		$sa = self::signature( $a, $services );
		$sb = self::signature( $b, $services );
		if ( $sa['region'] !== $sb['region'] || $sa['kind'] !== $sb['kind'] ) {
			return false;
		}
		if ( '' !== $sa['region'] && '' !== $sa['service'] && $sa['service'] === $sb['service'] ) {
			return true;
		}
		return self::overlap( $sa['words'], $sb['words'] ) >= 0.5;
	}

	/**
	 * Erstes Thema aus der Liste, das eine Dublette ist.
	 *
	 * @param array<string, mixed>             $topic    Thema.
	 * @param array<int, array<string, mixed>> $existing Vorhandene Themen.
	 * @param string[]                         $services Leistungen aus dem Profil.
	 * @return array<string, mixed>|null
	 */
	public static function find( array $topic, array $existing, array $services ): ?array {
		foreach ( $existing as $other ) {
			if ( (int) ( $other['id'] ?? 0 ) !== (int) ( $topic['id'] ?? -1 ) && self::same( $topic, $other, $services ) ) {
				return $other;
			}
		}
		return null;
	}

	/**
	 * Anteil gemeinsamer Wörter, bezogen auf den kürzeren Titel.
	 *
	 * @param string[] $a Wörter.
	 * @param string[] $b Wörter.
	 */
	private static function overlap( array $a, array $b ): float {
		if ( array() === $a || array() === $b ) {
			return 0.0;
		}
		return count( array_intersect( $a, $b ) ) / min( count( $a ), count( $b ) );
	}

	/**
	 * Position einer Leistung im Text: der ganze Name oder alle seine Wörter ab vier Buchstaben (Wortanfang,
	 * sieben Buchstaben).
	 *
	 * @param string $hay  Vereinheitlichter Text mit Leerzeichen am Rand.
	 * @param string $name Leistung.
	 */
	private static function position( string $hay, string $name ): ?int {
		$key = KeywordFilter::fold( $name );
		if ( '' === $key ) {
			return null;
		}
		$pos = mb_strpos( $hay, ' ' . $key );
		if ( false !== $pos ) {
			return $pos;
		}
		$first = null;
		foreach ( explode( ' ', $key ) as $word ) {
			if ( mb_strlen( $word ) < 4 ) {
				continue;
			}
			$p = mb_strpos( $hay, ' ' . mb_substr( $word, 0, 7 ) );
			if ( false === $p ) {
				return null;
			}
			$first = null === $first ? $p : min( $first, $p );
		}
		return $first;
	}

	/**
	 * Ort ohne allgemeine Wörter („Raum Hannover“ = „Hannover“).
	 *
	 * @param string $region Ort.
	 */
	private static function region( string $region ): string {
		$words = array_diff( explode( ' ', KeywordFilter::fold( $region ) ), array( 'region', 'gemeinde', 'stadt', 'raum', 'grossraum', 'umgebung', 'umkreis', 'landkreis', 'kreis', '' ) );
		return implode( ' ', $words );
	}
}
