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
 * Zwei Themen sind Dubletten, wenn sie dieselbe Art haben (Seite – lokale Seite, Beitrag, sonstiges –,
 * Ratgeber oder saisonal; Thema 39 war ein Beitrag, 43 eine lokale Seite) und
 * - mit Ort: derselbe Ort und dieselbe Hauptleistung (das erste Leistungswort aus dem Profil im Titel oder
 *   Haupt-Keyword, siehe vocabulary()), oder
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
		// Nachtrag 23.10. (0.7.7): Leistungen heißen oft zusammengesetzt („Gehweg- und Pflasterreinigung“). Die
		// Hauptleistung ist deshalb das erste Leistungswort aus dem Profil im Titel oder Haupt-Keyword (verglichen
		// über die ersten acht Buchstaben), nicht der ganze Leistungsname.
		foreach ( self::vocabulary( $services ) as $word ) {
			$pos = mb_strpos( $hay, ' ' . $word );
			if ( false !== $pos && $pos < $best ) {
				$best    = $pos;
				$service = $word;
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
			'kind'    => in_array( (string) ( $topic['format'] ?? '' ), array( 'guide', 'seasonal' ), true ) ? (string) $topic['format'] : 'page',
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
	 * Leistungswörter aus den Namen der Leistungen: Wörter ab sechs Buchstaben ohne allgemeine Wörter
	 * („Reinigung“, „Bereichen“), gekürzt auf acht Buchstaben.
	 *
	 * @param string[] $services Leistungen aus dem Profil.
	 * @return string[]
	 */
	public static function vocabulary( array $services ): array {
		$generic = array( 'reinigung', 'reinigungen', 'entfernung', 'bereiche', 'bereichen', 'oeffentlichen', 'oberflaechen', 'langfristiger', 'regelmaessige', 'regelmaessig', 'service', 'arbeiten', 'leistung', 'leistungen' );
		$out     = array();
		foreach ( $services as $name ) {
			foreach ( explode( ' ', KeywordFilter::fold( (string) $name ) ) as $word ) {
				// „Reinigungspakete“ würde sonst jedes Thema mit „Reinigung“ treffen.
				if ( mb_strlen( $word ) >= 6 && ! in_array( $word, $generic, true ) && ! str_starts_with( $word, 'reinigung' ) && ! str_starts_with( $word, 'entfernung' ) ) {
					$out[ mb_substr( $word, 0, 8 ) ] = true;
				}
			}
		}
		return array_keys( $out );
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
	 * Ort ohne allgemeine Wörter („Raum Hannover“ = „Hannover“).
	 *
	 * @param string $region Ort.
	 */
	private static function region( string $region ): string {
		$words = array_diff( explode( ' ', KeywordFilter::fold( $region ) ), array( 'region', 'gemeinde', 'stadt', 'raum', 'grossraum', 'umgebung', 'umkreis', 'landkreis', 'kreis', '' ) );
		return implode( ' ', $words );
	}
}
