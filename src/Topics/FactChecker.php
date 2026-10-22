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
 * Ergebnis: verified/strong oder verified/medium, disputed/weak („Bezug unklar“) oder rejected.
 * Die Regeln für sinngemäße Werte und den Bezug über die Seite stehen bei check() (Nachtrag 0.6.4).
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
	 * Wörter ohne Aussage beim Vergleich eines Werts mit dem Text.
	 */
	private const STOPWORDS = array( 'eine', 'einer', 'einem', 'einen', 'eines', 'oder', 'sind', 'wird', 'werden', 'wurde', 'wurden', 'haben', 'nicht', 'auch', 'sowie', 'dass', 'nach', 'über', 'unter', 'beim', 'diese', 'dieser', 'dieses', 'gilt', 'gelten', 'kann', 'können', 'muss', 'müssen', 'ihre', 'ihren', 'jeweils', 'stand' );

	/**
	 * Allgemeine Wörter in Namen von Gegenständen („Gemeinde Uetze“, „Büro Uetze“).
	 */
	private const GENERIC = array( 'gemeinde', 'stadt', 'ortsteil', 'ort', 'dorf', 'landkreis', 'kreis', 'region', 'raum', 'büro', 'standort' );

	/**
	 * Prüft eine Tatsache.
	 *
	 * Nachtrag 22.10. (0.6.4), nach Lauf 118: Die KI gibt den Wert als eigenen Satz wieder („20.723 Einwohner
	 * (Stand Oktober 2022)“ zum Zitat „Einwohner: 20.723 (Stand: 10/2022)“) und den Gegenstand als
	 * Beschreibung („Sommerreinigung in Uetze“). Deshalb:
	 * - Wert: wörtlich (strong); sonst alle Zahlen des Werts (strong) oder, ohne Zahlen, mindestens 60 % seiner
	 *   Wörter (medium). Fehlt der Wert im Absatz, zählen bis zu zwei Absätze davor und danach (medium).
	 * - Bezug: Name, Name ohne allgemeine Wörter („Gemeinde“, „Büro“) oder ein bekannter Ort im Namen, im Zitat
	 *   oder Absatz (strong); sonst im Titel oder in der Adresse der Seite, wenn der Absatz keinen anderen
	 *   bekannten Ort nennt (medium).
	 *
	 * @param array{quote: string, value: string} $fact     Tatsache mit Zitat und Wert.
	 * @param string[]                            $names    Name und Schreibweisen der Entität.
	 * @param string|null                         $page     Text der Quelle oder null, wenn nicht abrufbar.
	 * @param string|null                         $error    Grund, warum die Seite nicht abrufbar war.
	 * @param string                              $page_ref Titel und Adresse der Seite.
	 * @param string[]                            $places   Bekannte Orte (für den Bezug und die Abgrenzung).
	 * @return array{status: string, quality: string|null, note: string, excerpt: string}
	 */
	public static function check( array $fact, array $names, ?string $page, ?string $error = null, string $page_ref = '', array $places = array() ): array {
		$quote = trim( (string) $fact['quote'] );
		if ( null === $page || '' === trim( $page ) ) {
			return self::result( 'rejected', null, 'Seite nicht abrufbar' . ( null !== $error && '' !== $error ? ' (' . $error . ')' : '' ), $quote );
		}
		if ( mb_strlen( $quote ) < 10 ) {
			return self::result( 'rejected', null, 'Zitat fehlt oder ist zu kurz', $quote );
		}
		$variants  = self::variants( $names, $places );
		$others    = self::others( $places, $variants );
		$value     = (string) $fact['value'];
		$paragraph = self::paragraph( $page, $quote );
		if ( null === $paragraph ) {
			return self::by_value( $value, $variants, $others, $page, $quote, $page_ref );
		}
		$context = self::fold( $quote ) . ' ' . self::fold( $paragraph );
		$match   = self::value_match( $context, $value );
		$near    = false;
		if ( '' === $match ) {
			$match = self::value_match( self::surroundings( $page, $quote ), $value );
			if ( '' === $match ) {
				return self::result( 'rejected', null, 'Wert steht nicht im Zitat oder in seinem Umfeld', $quote );
			}
			$near = true;
		}
		$strong = ! $near && 'words' !== $match;
		if ( self::names_in( $context, $variants ) ) {
			return self::result( 'verified', $strong ? 'strong' : 'medium', $strong ? 'Zitat, Wert und Bezug in der Quelle gefunden' : ( $near ? 'Zitat und Bezug gefunden, Wert im Umfeld des Zitats' : 'Zitat und Bezug gefunden, Wert sinngemäß' ), $quote );
		}
		if ( self::names_in( $page_ref, $variants ) && ! self::names_in( $context, $others ) ) {
			return self::result( 'verified', 'medium', 'Zitat und Wert gefunden, Bezug über Titel oder Adresse der Seite', $quote );
		}
		return self::result( 'disputed', 'weak', 'Bezug unklar: Zitat und Wert gefunden, die Entität aber nicht', $quote );
	}

	/**
	 * Zitat nicht wörtlich gefunden: Absätze der Quelle mit dem Wert. Ein Absatz mit Wert und Entität belegt
	 * die Aussage (medium); gibt es nur Absätze ohne Entität, bleibt der Bezug unklar.
	 *
	 * @param string   $value    Wert.
	 * @param string[] $variants Namen der Entität.
	 * @param string[] $others   Andere bekannte Orte.
	 * @param string   $page     Text der Quelle.
	 * @param string   $quote    Zitat der Recherche.
	 * @param string   $page_ref Titel und Adresse der Seite.
	 * @return array{status: string, quality: string|null, note: string, excerpt: string}
	 */
	private static function by_value( string $value, array $variants, array $others, string $page, string $quote, string $page_ref ): array {
		$found  = null;
		$blocks = preg_split( '/\n+/u', $page );
		foreach ( false === $blocks ? array() : $blocks as $block ) {
			$folded = self::fold( (string) $block );
			if ( '' === $folded ) {
				continue;
			}
			$window = self::around_value( $folded, $value );
			$match  = self::value_match( $window, $value );
			// Nur Zahlen sind ohne Zitat zu wenig, wenn der Wert auch Wörter hat („20.723 Einwohner“).
			if ( '' === $match || ( 'numbers' === $match && array() !== self::words( $value ) && 0 === self::words_found( $window, $value ) ) ) {
				continue;
			}
			if ( self::names_in( $window, $variants ) ) {
				return self::result( 'verified', 'medium', 'Zitat sinngemäß, Beleg im Text gefunden', self::sentence( (string) $block, $value ) );
			}
			if ( self::names_in( $page_ref, $variants ) && ! self::names_in( $window, $others ) ) {
				return self::result( 'verified', 'medium', 'Zitat sinngemäß, Beleg im Text, Bezug über Titel oder Adresse der Seite', self::sentence( (string) $block, $value ) );
			}
			$found ??= self::sentence( (string) $block, $value );
		}
		if ( null !== $found ) {
			return self::result( 'disputed', 'weak', 'Bezug unklar: Zitat sinngemäß, Wert gefunden, die Entität aber nicht', $found );
		}
		return self::result( 'rejected', null, 'Zitat und Wert nicht in der Quelle gefunden', $quote );
	}

	/**
	 * Wie steht der Wert im Text? „exact“ (wörtlich), „numbers“ (alle Zahlen des Werts), „words“ (ohne Zahlen:
	 * mindestens 60 % der Wörter ab vier Buchstaben, verglichen über die ersten fünf) oder leer.
	 *
	 * @param string $text  Vereinheitlichter Text.
	 * @param string $value Wert.
	 */
	public static function value_match( string $text, string $value ): string {
		if ( self::contains_value( $text, $value ) ) {
			return 'exact';
		}
		preg_match_all( '/\d+(?:[.,]\d+)*/u', self::fold( $value ), $m );
		$numbers = array_values( array_unique( $m[0] ) );
		if ( array() !== $numbers ) {
			foreach ( $numbers as $number ) {
				if ( ! self::contains_value( $text, $number ) ) {
					return '';
				}
			}
			return 'numbers';
		}
		$words = self::words( $value );
		if ( array() === $words ) {
			return '';
		}
		$found = self::words_found( $text, $value );
		return ( $found >= min( 2, count( $words ) ) && $found / count( $words ) >= 0.6 ) ? 'words' : '';
	}

	/**
	 * Wörter des Werts mit Aussage.
	 *
	 * @param string $value Wert.
	 * @return string[]
	 */
	private static function words( string $value ): array {
		$out = array();
		foreach ( explode( ' ', KeywordFilter::fold( $value ) ) as $word ) {
			if ( mb_strlen( $word ) >= 4 && ! preg_match( '/^\d+$/', $word ) && ! in_array( $word, self::folded( self::STOPWORDS ), true ) ) {
				$out[ $word ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Wie viele Wörter des Werts stehen im Text (Wortanfang, erste fünf Buchstaben)?
	 *
	 * @param string $text  Text.
	 * @param string $value Wert.
	 */
	private static function words_found( string $text, string $value ): int {
		$hay   = ' ' . KeywordFilter::fold( $text );
		$found = 0;
		foreach ( self::words( $value ) as $word ) {
			if ( str_contains( $hay, ' ' . mb_substr( $word, 0, 5 ) ) ) {
				++$found;
			}
		}
		return $found;
	}

	/**
	 * Umfeld des Zitats: der Block mit dem Zitat und bis zu zwei Blöcke davor und danach (höchstens 2000 Zeichen).
	 *
	 * @param string $page  Text der Quelle.
	 * @param string $quote Zitat.
	 */
	private static function surroundings( string $page, string $quote ): string {
		$needle = self::fold( $quote );
		$blocks = preg_split( '/\n+/u', $page );
		$blocks = false === $blocks ? array() : array_values( array_filter( array_map( 'trim', $blocks ), static fn( string $b ): bool => '' !== $b ) );
		foreach ( $blocks as $index => $block ) {
			if ( str_contains( self::fold( $block ), $needle ) ) {
				return mb_substr( self::fold( implode( ' ', array_slice( $blocks, max( 0, $index - 2 ), 5 ) ) ), 0, 2000 );
			}
		}
		$folded = self::fold( $page );
		$pos    = mb_strpos( $folded, $needle );
		return false === $pos ? '' : mb_substr( $folded, max( 0, $pos - 800 ), mb_strlen( $needle ) + 1600 );
	}

	/**
	 * Schreibweisen für den Bezug: Name, Name ohne allgemeine Wörter, bekannte Orte im Namen.
	 *
	 * @param string[] $names  Name und Schreibweisen.
	 * @param string[] $places Bekannte Orte.
	 * @return string[] Vereinheitlicht.
	 */
	public static function variants( array $names, array $places ): array {
		$out = array();
		foreach ( $names as $name ) {
			$key = KeywordFilter::fold( (string) $name );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = true;
			$core        = trim( implode( ' ', array_diff( explode( ' ', $key ), self::folded( self::GENERIC ) ) ) );
			if ( mb_strlen( $core ) >= 3 ) {
				$out[ $core ] = true;
			}
			foreach ( $places as $place ) {
				$p = KeywordFilter::fold( (string) $place );
				if ( mb_strlen( $p ) >= 3 && str_contains( ' ' . $key . ' ', ' ' . $p . ' ' ) ) {
					$out[ $p ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Wortliste in der Form von KeywordFilter::fold („büro“ → „buero“).
	 *
	 * @param string[] $words Wörter.
	 * @return string[]
	 */
	private static function folded( array $words ): array {
		return array_map( array( KeywordFilter::class, 'fold' ), $words );
	}

	/**
	 * Andere bekannte Orte: weder eine Schreibweise der Entität noch darin enthalten oder sie enthaltend.
	 *
	 * @param string[] $places   Bekannte Orte.
	 * @param string[] $variants Schreibweisen der Entität (vereinheitlicht).
	 * @return string[]
	 */
	private static function others( array $places, array $variants ): array {
		$out = array();
		foreach ( $places as $place ) {
			$p = KeywordFilter::fold( (string) $place );
			if ( mb_strlen( $p ) < 3 ) {
				continue;
			}
			foreach ( $variants as $v ) {
				if ( str_contains( ' ' . $v . ' ', ' ' . $p . ' ' ) || str_contains( ' ' . $p . ' ', ' ' . $v . ' ' ) ) {
					continue 2;
				}
			}
			$out[ $p ] = true;
		}
		return array_keys( $out );
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
	 * Steht ein Name im Text (ganzes Wort)? Text und Namen werden vereinheitlicht, auch Adressen („www.uetze.de“).
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
			if ( '' !== self::value_match( self::fold( (string) $part ), $value ) ) {
				return mb_substr( trim( (string) $part ), 0, 400 );
			}
		}
		return mb_substr( trim( $block ), 0, 400 );
	}

	/**
	 * Widersprüche: geprüfte Aussagen zum selben Gegenstand und derselben Eigenschaft mit verschiedenen Werten
	 * werden beide „disputed“. Nachtrag 22.10. (0.6.5): Nur Eigenschaften mit einem Wert zählen, also Zahlen und
	 * Jahre (Einwohner, Ersterwähnung); Regeln oder Sehenswürdigkeiten gibt es mehrere, ohne dass sie sich
	 * widersprechen (Lauf 120: zwei Regeln der Straßenreinigung).
	 *
	 * @param array<int, array<string, mixed>> $facts Tatsachen mit Prüfergebnis (subject, property, value, status, note).
	 * @return array<int, array<string, mixed>>
	 */
	public static function contradictions( array $facts ): array {
		$groups = array();
		foreach ( $facts as $index => $fact ) {
			if ( 'verified' !== $fact['status'] || ! self::single_valued( (string) $fact['property'], (string) $fact['value'] ) ) {
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
	 * Hat die Eigenschaft nur einen Wert? Der Wert enthält eine Zahl, und die Eigenschaft ist keine Liste
	 * (Regel, Sehenswürdigkeit, Veranstaltung, Merkmal …).
	 *
	 * @param string $property Eigenschaft (Englisch, z. B. population).
	 * @param string $value    Wert.
	 */
	public static function single_valued( string $property, string $value ): bool {
		if ( ! preg_match( '/\d/', $value ) ) {
			return false;
		}
		return ! preg_match( '/(regulation|rule|landmark|sight|attraction|event|feature|service|example|village|district|part|member|scope|exception)/i', $property );
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
