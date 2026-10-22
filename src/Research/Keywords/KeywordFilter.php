<?php
/**
 * Ausschlussregeln für Keywords (Spezifikation 6.4).
 *
 * @package Lsec
 */

namespace Lsec\Research\Keywords;

use Lsec\Entities\EntityRepository;

/**
 * Setzt den Ausschlussgrund eines Keywords nach festen Regeln, in dieser Reihenfolge:
 *
 * 1. competitor – enthält den Namen oder eine Schreibweise eines Wettbewerbers
 * 2. brand – enthält einen fremden Firmen- oder Markennamen (von der KI erkannt oder als Entität bekannt),
 *    der weder zur eigenen Firma gehört noch eine angebotene Marke ist
 * 3. foreign_place – enthält einen Ort außerhalb des Servicegebiets
 *
 * off_topic folgt mit der Themenrecherche (Stufe D), manual setzt nur der Administrator.
 * Die Klasse kennt WordPress nicht; Orte prüft ein übergebener Rückruf.
 */
final class KeywordFilter {

	/**
	 * Wettbewerber: Vergleichsform → Anzeigename.
	 *
	 * @var array<string, string>
	 */
	private array $competitors = array();

	/**
	 * Fremde Firmen und Marken: Vergleichsform → Anzeigename.
	 *
	 * @var array<string, string>
	 */
	private array $foreign = array();

	/**
	 * Konstruktor.
	 *
	 * @param array<string, string[]>       $competitors Wettbewerber: Name → Schreibweisen.
	 * @param string[]                      $own         Eigene Namen: Firma, eigene und angebotene Marken, mit Schreibweisen.
	 * @param array<string, string[]>       $foreign     Bekannte fremde Firmen und Marken: Name → Schreibweisen.
	 * @param \Closure(string): (bool|null) $place_in_area Ort im Servicegebiet? true, false oder null (unbekannt).
	 */
	public function __construct(
		array $competitors,
		private readonly array $own,
		array $foreign,
		private readonly \Closure $place_in_area
	) {
		foreach ( $competitors as $name => $aliases ) {
			foreach ( array_merge( array( (string) $name ), $aliases ) as $term ) {
				$key = self::fold( $term );
				if ( mb_strlen( $key ) >= 3 ) {
					$this->competitors[ $key ] = (string) $name;
				}
			}
		}
		foreach ( $foreign as $name => $aliases ) {
			if ( $this->is_own( (string) $name ) ) {
				continue;
			}
			foreach ( array_merge( array( (string) $name ), $aliases ) as $term ) {
				$key = self::fold( $term );
				if ( mb_strlen( $key ) >= 3 ) {
					$this->foreign[ $key ] = (string) $name;
				}
			}
		}
		// Längere Namen zuerst, damit „Muster Reinigung GmbH“ vor „Muster“ greift.
		uksort( $this->competitors, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );
		uksort( $this->foreign, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );
	}

	/**
	 * Ausschlussgrund eines Keywords.
	 *
	 * @param string                                     $keyword  Keyword.
	 * @param array{places?: string[], names?: string[]} $analysis Von der KI erkannte Orte und Namen.
	 * @return array{exclusion: string|null, detail: string|null}
	 */
	public function evaluate( string $keyword, array $analysis = array() ): array {
		$text = ' ' . self::fold( $keyword ) . ' ';

		foreach ( $this->competitors as $key => $name ) {
			if ( str_contains( $text, ' ' . $key . ' ' ) ) {
				return self::result( 'competitor', $name );
			}
		}

		$places = array_map( 'strval', (array) ( $analysis['places'] ?? array() ) );
		$folded = array_map( array( self::class, 'fold' ), $places );
		foreach ( (array) ( $analysis['names'] ?? array() ) as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name || in_array( self::fold( $name ), $folded, true ) || $this->is_own( $name ) ) {
				continue;
			}
			if ( str_contains( $text, ' ' . self::fold( $name ) . ' ' ) ) {
				return self::result( 'brand', $name );
			}
		}
		foreach ( $this->foreign as $key => $name ) {
			if ( str_contains( $text, ' ' . $key . ' ' ) ) {
				return self::result( 'brand', $name );
			}
		}

		foreach ( $places as $place ) {
			$place = trim( $place );
			if ( '' === $place || ! str_contains( $text, ' ' . self::fold( $place ) . ' ' ) ) {
				continue;
			}
			if ( false === ( $this->place_in_area )( $place ) ) {
				return self::result( 'foreign_place', $place );
			}
		}

		return self::result( null, null );
	}

	/**
	 * Gehört ein Name zur eigenen Firma oder zu einer angebotenen Marke?
	 *
	 * @param string $name Name.
	 */
	public function is_own( string $name ): bool {
		$key = self::fold( $name );
		foreach ( $this->own as $own ) {
			$own_key = self::fold( $own );
			if ( '' === $own_key ) {
				continue;
			}
			if ( $own_key === $key || EntityRepository::same_name( $own, $name ) || str_contains( ' ' . $own_key . ' ', ' ' . $key . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Vergleichsform: klein, ohne Umlaute, nur Buchstaben und Ziffern, durch Leerzeichen getrennt.
	 *
	 * @param string $text Text.
	 */
	public static function fold( string $text ): string {
		$text = KeywordNormalizer::fold( $text );
		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
	}

	/**
	 * Ergebnis.
	 *
	 * @param string|null $exclusion Grund.
	 * @param string|null $detail    Name oder Ort.
	 * @return array{exclusion: string|null, detail: string|null}
	 */
	private static function result( ?string $exclusion, ?string $detail ): array {
		return array(
			'exclusion' => $exclusion,
			'detail'    => $detail,
		);
	}
}
