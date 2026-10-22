<?php
/**
 * Prüft Tatsachen aus der Recherche gegen den Text ihrer Quelle (Spezifikation 8.4a).
 *
 * @package Lsec
 */

namespace Lsec\Topics;

use Lsec\Research\Keywords\KeywordFilter;

/**
 * Im Test nannten zwei Modelle mit Quellenangabe dieselbe Jahreszahl für zwei verschiedene Ortsteile
 * (1226 für Eltze bzw. Dollbergen). Eine URL allein belegt also nichts:
 *
 * 1. Das Zitat muss im Text der Quelle stehen (vereinheitlicht wie bei den Fundstellen).
 * 2. Der Wert muss im Zitat oder im selben Absatz stehen.
 * 3. Der Name oder eine Schreibweise der Entität muss im Zitat oder im selben Absatz stehen.
 *
 * Ergebnis: verified/strong, disputed/weak („Bezug unklar“) oder rejected.
 *
 * Nachtrag 22.10.: Im Echtbetrieb gab das Strukturmodell die Zitate oft sinngemäß wieder, weil es nur die
 * Zusammenfassung der Recherche sieht. Steht das Zitat nicht in der Quelle, sucht die Prüfung einen Absatz,
 * der den Wert enthält: mit der Entität im selben Absatz verified/medium (Beleg ist dann der Satz der
 * Quelle), ohne Entität disputed/weak, sonst rejected. Die Klasse kennt WordPress nicht.
 */
final class FactChecker {

	/**
	 * Größte Länge eines Absatzes; längere Blöcke werden auf ein Fenster um das Zitat gekürzt.
	 */
	private const PARAGRAPH = 1500;

	/**
	 * Fenster um das Zitat in langen Blöcken (Zeichen je Seite).
	 */
	private const WINDOW = 400;

	/**
	 * Prüft eine Tatsache.
	 *
	 * @param array{quote: string, value: string} $fact  Tatsache mit wörtlichem Zitat und Wert.
	 * @param string[]                            $names Name und Schreibweisen der Entität.
	 * @param string|null                         $page  Text der Quelle oder null, wenn nicht abrufbar.
	 * @param string|null                         $error Grund, warum die Seite nicht abrufbar war.
	 * @return array{status: string, quality: string|null, note: string, excerpt: string}
	 */
	public static function check( array $fact, array $names, ?string $page, ?string $error = null ): array {
		$quote = trim( (string) $fact['quote'] );
		if ( null === $page || '' === trim( $page ) ) {
			return self::result( 'rejected', null, 'Seite nicht abrufbar' . ( null !== $error && '' !== $error ? ' (' . $error . ')' : '' ), $quote );
		}
		if ( mb_strlen( $quote ) < 10 ) {
			return self::result( 'rejected', null, 'Zitat fehlt oder ist zu kurz', $quote );
		}
		$paragraph = self::paragraph( $page, $quote );
		if ( null === $paragraph ) {
			return self::by_value( $fact, $names, $page, $quote );
		}
		$context = self::fold( $quote ) . ' ' . self::fold( $paragraph );
		if ( ! self::contains_value( $context, (string) $fact['value'] ) ) {
			return self::result( 'rejected', null, 'Wert steht nicht im Zitat oder Absatz', $quote );
		}
		if ( self::names_in( $context, $names ) ) {
			return self::result( 'verified', 'strong', 'Zitat, Wert und Bezug in der Quelle gefunden', $quote );
		}
		return self::result( 'disputed', 'weak', 'Bezug unklar: Zitat und Wert gefunden, die Entität aber nicht', $quote );
	}

	/**
	 * Zitat nicht wörtlich gefunden: Absätze der Quelle mit dem Wert. Ein Absatz mit Wert und Entität belegt
	 * die Aussage (medium); gibt es nur Absätze ohne Entität, bleibt der Bezug unklar.
	 *
	 * @param array{quote: string, value: string} $fact  Tatsache.
	 * @param string[]                            $names Name und Schreibweisen der Entität.
	 * @param string                              $page  Text der Quelle.
	 * @param string                              $quote Zitat der Recherche.
	 * @return array{status: string, quality: string|null, note: string, excerpt: string}
	 */
	private static function by_value( array $fact, array $names, string $page, string $quote ): array {
		$value  = (string) $fact['value'];
		$found  = null;
		$blocks = preg_split( '/\n+/u', $page );
		foreach ( false === $blocks ? array() : $blocks as $block ) {
			$folded = self::fold( (string) $block );
			if ( '' === $folded || ! self::contains_value( $folded, $value ) ) {
				continue;
			}
			$window = self::around_value( $folded, $value );
			if ( self::names_in( $window, $names ) ) {
				return self::result( 'verified', 'medium', 'Zitat sinngemäß, Beleg im Text gefunden', self::sentence( (string) $block, $value ) );
			}
			$found ??= self::sentence( (string) $block, $value );
		}
		if ( null !== $found ) {
			return self::result( 'disputed', 'weak', 'Bezug unklar: Zitat sinngemäß, Wert gefunden, die Entität aber nicht', $found );
		}
		return self::result( 'rejected', null, 'Zitat und Wert nicht in der Quelle gefunden', $quote );
	}

	/**
	 * Fenster um den Wert in einem langen Block.
	 *
	 * @param string $folded Vereinheitlichter Block.
	 * @param string $value  Wert.
	 */
	private static function around_value( string $folded, string $value ): string {
		if ( mb_strlen( $folded ) <= self::PARAGRAPH ) {
			return $folded;
		}
		$pos = mb_strpos( $folded, self::fold( $value ) );
		return false === $pos ? $folded : mb_substr( $folded, max( 0, $pos - self::WINDOW ), 2 * self::WINDOW );
	}

	/**
	 * Steht ein Name im Text (ganzes Wort)?
	 *
	 * @param string   $text  Text.
	 * @param string[] $names Namen.
	 */
	private static function names_in( string $text, array $names ): bool {
		$hay = ' ' . KeywordFilter::fold( $text ) . ' ';
		foreach ( $names as $name ) {
			$key = KeywordFilter::fold( (string) $name );
			if ( '' !== $key && str_contains( $hay, ' ' . $key . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Satz des Blocks mit dem Wert (als Beleg), höchstens 400 Zeichen.
	 *
	 * @param string $block Block der Quelle.
	 * @param string $value Wert.
	 */
	private static function sentence( string $block, string $value ): string {
		$parts = preg_split( '/(?<=[.!?])\s+/u', trim( $block ) );
		foreach ( false === $parts ? array() : $parts as $part ) {
			if ( self::contains_value( self::fold( (string) $part ), $value ) ) {
				return mb_substr( trim( (string) $part ), 0, 400 );
			}
		}
		return mb_substr( trim( $block ), 0, 400 );
	}

	/**
	 * Widersprüche: geprüfte Aussagen zum selben Gegenstand und derselben Eigenschaft mit verschiedenen Werten
	 * werden beide „disputed“.
	 *
	 * @param array<int, array<string, mixed>> $facts Tatsachen mit Prüfergebnis (subject, property, value, status, note).
	 * @return array<int, array<string, mixed>>
	 */
	public static function contradictions( array $facts ): array {
		$groups = array();
		foreach ( $facts as $index => $fact ) {
			if ( 'verified' !== $fact['status'] ) {
				continue;
			}
			$key = KeywordFilter::fold( (string) $fact['subject'] ) . '|' . (string) $fact['property'];
			$groups[ $key ][ self::fold( (string) $fact['value'] ) ][] = $index;
		}
		foreach ( $groups as $values ) {
			if ( count( $values ) < 2 ) {
				continue;
			}
			foreach ( $values as $indexes ) {
				foreach ( $indexes as $index ) {
					$facts[ $index ]['status'] = 'disputed';
					$facts[ $index ]['note']   = 'Widerspricht einer anderen geprüften Quelle';
				}
			}
		}
		return $facts;
	}

	/**
	 * Absatz der Quelle, in dem das Zitat steht, oder null.
	 *
	 * @param string $page  Text der Quelle.
	 * @param string $quote Zitat.
	 */
	public static function paragraph( string $page, string $quote ): ?string {
		$needle = self::fold( $quote );
		if ( '' === $needle || ! str_contains( self::fold( $page ), $needle ) ) {
			return null;
		}
		$blocks = preg_split( '/\n+/u', $page );
		foreach ( false === $blocks ? array() : $blocks as $block ) {
			$folded = self::fold( (string) $block );
			$pos    = mb_strpos( $folded, $needle );
			if ( false === $pos ) {
				continue;
			}
			if ( mb_strlen( $folded ) <= self::PARAGRAPH ) {
				return (string) $block;
			}
			return mb_substr( $folded, max( 0, $pos - self::WINDOW ), mb_strlen( $needle ) + 2 * self::WINDOW );
		}
		// Das Zitat reicht über mehrere Blöcke: Fenster im ganzen Text.
		$folded = self::fold( $page );
		$pos    = (int) mb_strpos( $folded, $needle );
		return mb_substr( $folded, max( 0, $pos - self::WINDOW ), mb_strlen( $needle ) + 2 * self::WINDOW );
	}

	/**
	 * Steht der Wert im Text? Zahlen als ganze Zahl („1226“, nicht in „12260“), sonst als Wortfolge.
	 *
	 * @param string $text  Vereinheitlichter Text.
	 * @param string $value Wert.
	 */
	public static function contains_value( string $text, string $value ): bool {
		$value = self::fold( $value );
		if ( '' === $value ) {
			return false;
		}
		if ( preg_match( '/^\d[\d.,]*$/', $value ) ) {
			return 1 === preg_match( '/(?<![\d.,])' . preg_quote( $value, '/' ) . '(?![\d])/u', $text );
		}
		return str_contains( ' ' . KeywordFilter::fold( $text ) . ' ', ' ' . KeywordFilter::fold( $value ) . ' ' );
	}

	/**
	 * Vereinheitlichung wie bei den Fundstellen: typografische Zeichen, Kleinschreibung, Leerraum.
	 *
	 * @param string $text Text.
	 */
	public static function fold( string $text ): string {
		$text = str_replace( array( '„', '“', '”', '‚', '‘', '’', '–', '—', "\u{00A0}", '…' ), array( '"', '"', '"', "'", "'", "'", '-', '-', ' ', '...' ), $text );
		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 'UTF-8' );
	}

	/**
	 * Ergebnis.
	 *
	 * @param string      $status  Status.
	 * @param string|null $quality Qualität des Belegs.
	 * @param string      $note    Hinweis.
	 * @param string      $excerpt Zitat.
	 * @return array{status: string, quality: string|null, note: string, excerpt: string}
	 */
	private static function result( string $status, ?string $quality, string $note, string $excerpt ): array {
		return array(
			'status'  => $status,
			'quality' => $quality,
			'note'    => $note,
			'excerpt' => mb_substr( $excerpt, 0, 1000 ),
		);
	}
}
