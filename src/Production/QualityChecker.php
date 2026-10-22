<?php
/**
 * Regelbasierte Qualitätsprüfung (Spezifikation 10.1).
 *
 * @package Lsec
 */

namespace Lsec\Production;

use Lsec\Research\Keywords\KeywordFilter;

/**
 * Prüft einen Entwurf ohne KI. Alle Daten kommen von außen (Kontext, Entwurf, bestehende Titel, andere lokale
 * Beiträge, Orte außerhalb, Fokus-Keywords anderer Beiträge, Ergebnis der Blockprüfung); die Klasse kennt
 * WordPress nicht.
 *
 * Nachtrag 22.10. (0.7.0) zur Zahlenprüfung: Einstellige Zahlen ohne Einheit („in 3 Schritten“) gelten nicht
 * als Tatsache; Zahlen mit €, %, Euro, m², km oder Jahr(en) werden immer geprüft. Ausgeschriebene Jahreszahlen
 * prüft erst die KI-Bewertung.
 */
final class QualityChecker {

	/**
	 * Floskeln, die als Warnung zählen.
	 */
	private const PHRASES = array( 'in der heutigen zeit', 'es ist wichtig zu beachten', 'zusammenfassend lässt sich sagen', 'last but not least', 'egal ob sie', 'nicht zuletzt', 'von großer bedeutung', 'rundum sorglos' );

	/**
	 * Prüft den Entwurf.
	 *
	 * @param array<string, mixed> $draft   Bereinigter Entwurf (DraftGenerator::normalize).
	 * @param array<string, mixed> $context Kontext.
	 * @param array<string, mixed> $site    Daten der Website: titles (string[]), local_texts (string[]),
	 *                                      foreign_places (string[]), focus_keywords (string[]), blocks_valid (bool),
	 *                                      seo_title (int[2]), seo_description (int[2]).
	 * @return array{checks: array<int, array<string, mixed>>, blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>, used_claim_ids: int[], words: int}
	 */
	public static function check( array $draft, array $context, array $site ): array {
		$text   = DraftGenerator::plain_text( (array) $draft['content'] );
		$all    = (string) $draft['title'] . "\n" . $text . "\n" . (string) $draft['excerpt'];
		$split  = preg_split( '/\s+/u', trim( $text ) );
		$words  = '' === trim( $text ) || false === $split ? 0 : count( $split );
		$checks = array();
		$claims = Context::ids( (array) $context['claims'] );
		$used   = array_values( array_unique( array_map( 'intval', (array) $draft['used_claim_ids'] ) ) );
		$add    = static function ( string $code, string $label, bool $passed, bool $blocking, string $detail = '' ) use ( &$checks ): void {
			$checks[] = array(
				'code'     => $code,
				'label'    => $label,
				'passed'   => $passed,
				'blocking' => $blocking,
				'detail'   => $detail,
			);
		};
		$length = (array) $context['length'];
		$add( 'LENGTH', 'Länge im Rahmen des Beitragstyps', $words >= (int) $length['min'] && $words <= (int) $length['max'], false, sprintf( '%d Wörter, erlaubt %d–%d', $words, $length['min'], $length['max'] ) );
		$add( 'BLOCKS', 'Gültiges Block-Markup', ! empty( $site['blocks_valid'] ), true );

		// Verwendete Aussagen: nur verified oder confirmed.
		$bad = array();
		foreach ( $used as $id ) {
			if ( ! isset( $claims[ $id ] ) || ! in_array( (string) $claims[ $id ]['status'], array( 'verified', 'confirmed' ), true ) ) {
				$bad[] = '#' . $id;
			}
		}
		$add( 'UNSUPPORTED_FACT', 'Verwendete Tatsachen sind geprüft', array() === $bad, true, array() === $bad ? '' : 'nicht geprüft oder unbekannt: ' . implode( ', ', $bad ) );
		$unsupported = (array) $draft['unsupported_statements'];
		$add( 'UNSUPPORTED_STATEMENT', 'Keine unbelegten Aussagen laut Entwurf', array() === $unsupported, true, implode( ' | ', array_map( 'strval', $unsupported ) ) );

		// Zahlen im Text müssen aus einer verwendeten Aussage oder dem Profil stammen.
		$numbers = self::numbers_check( $all, $used, $claims, (array) $context['company'] + array( 'area' => (string) ( $context['area'] ?? '' ) ) );
		$used    = $numbers['used'];
		$add( 'UNSUPPORTED_NUMBER', 'Zahlen stammen aus Tatsachen oder Profil', array() === $numbers['missing'], true, array() === $numbers['missing'] ? ( array() === $numbers['added'] ? '' : 'Tatsachen ergänzt: #' . implode( ', #', $numbers['added'] ) ) : 'ohne Beleg: ' . implode( ', ', $numbers['missing'] ) . ' – ' . implode( ' | ', self::snippets( $all, $numbers['missing'] ) ) );

		// Haupt-Entität.
		$primary = array_values( array_filter( (array) $context['entities'], static fn( array $e ): bool => 'primary' === $e['role'] ) );
		$weak    = array_filter( $primary, static fn( array $e ): bool => ! in_array( (string) $e['status'], array( 'confirmed', 'probable' ), true ) );
		$add( 'PRIMARY_ENTITY', 'Haupt-Entität bestätigt oder wahrscheinlich', array() === $weak, true, array() === $primary ? 'keine Haupt-Entität im Thema' : implode( ', ', array_map( static fn( array $e ): string => $e['name'] . ' (' . $e['status'] . ')', $primary ) ) );

		// Interne Links: nur Linkziele (veröffentlichte Inhalte); andere entfernt das Plugin beim Anlegen.
		$targets = array_map( static fn( array $t ): string => rtrim( (string) $t['url'], '/' ), (array) $context['link_targets'] );
		$foreign = array();
		$home    = (string) ( $site['home_host'] ?? '' );
		foreach ( self::hrefs( (array) $draft['content'] ) as $href ) {
			$host = (string) parse_url( $href, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			if ( ( '' === $host || 0 === strcasecmp( $host, $home ) ) && ! in_array( rtrim( $href, '/' ), $targets, true ) ) {
				$foreign[] = $href;
			}
		}
		$add( 'INTERNAL_LINKS', 'Interne Links zeigen auf veröffentlichte Inhalte', true, true, array() === $foreign ? count( (array) $draft['internal_links'] ) . ' Links' : 'werden entfernt: ' . implode( ', ', $foreign ) );

		$add( 'OVERLAP', 'Keine hohe Überschneidung mit einer bestehenden Seite', 'high' !== (string) ( $context['topic']['overlap_level'] ?? '' ), true );

		$similar = self::similar_title( (string) $draft['title'], (array) ( $site['titles'] ?? array() ) );
		$add( 'SIMILAR_TITLE', 'Kein bestehender Beitrag mit sehr ähnlichem Titel', null === $similar, true, null === $similar ? '' : sprintf( '„%s“ (%d %%)', $similar['title'], $similar['percent'] ) );

		$region = trim( (string) $context['topic']['region'] );
		if ( '' !== $region ) {
			$named = self::contains( $all, $region );
			$local = array_filter( $used, static fn( int $id ): bool => isset( $claims[ $id ] ) && in_array( (string) ( $claims[ $id ]['entity_type'] ?? '' ), array( 'place', 'service_area' ), true ) && in_array( (string) $claims[ $id ]['status'], array( 'verified', 'confirmed' ), true ) );
			$add( 'LOCAL_FACTS', 'Ort genannt und mit mindestens zwei geprüften lokalen Tatsachen', $named && count( $local ) >= 2, true, sprintf( 'Ort %s, %d lokale Tatsachen', $named ? 'genannt' : 'fehlt', count( $local ) ) );
			$max = 0.0;
			foreach ( (array) ( $site['local_texts'] ?? array() ) as $other ) {
				$max = max( $max, self::similarity( $text, (string) $other ) );
			}
			$add( 'LOCAL_DUPLICATE', 'Unterscheidet sich von lokalen Beiträgen für andere Orte', $max < 0.5, true, sprintf( 'größte Übereinstimmung %d %%', (int) round( 100 * $max ) ) );
		}

		$places = array_values( array_filter( (array) ( $site['foreign_places'] ?? array() ), static fn( $p ): bool => self::contains( $all, (string) $p ) ) );
		$add( 'FOREIGN_PLACE', 'Keine Orte außerhalb des Servicegebiets', array() === $places, true, implode( ', ', $places ) );

		$placeholders = self::placeholders( $all );
		$add( 'PLACEHOLDER', 'Keine Platzhalter', array() === $placeholders, true, implode( ', ', $placeholders ) );

		$seo   = (array) $draft['seo'];
		$t_len = mb_strlen( (string) $seo['title'] );
		$d_len = mb_strlen( (string) $seo['description'] );
		$t_rng = (array) ( $site['seo_title'] ?? array( 30, 60 ) );
		$d_rng = (array) ( $site['seo_description'] ?? array( 120, 160 ) );
		$add( 'SEO_META', 'SEO-Titel und Meta-Beschreibung in der Länge', $t_len >= $t_rng[0] && $t_len <= $t_rng[1] && $d_len >= $d_rng[0] && $d_len <= $d_rng[1], false, sprintf( 'Titel %d Zeichen (%d–%d), Beschreibung %d (%d–%d)', $t_len, $t_rng[0], $t_rng[1], $d_len, $d_rng[0], $d_rng[1] ) );

		$focus = KeywordFilter::fold( (string) $seo['focus_keyword'] );
		$taken = '' !== $focus && in_array( $focus, array_map( array( KeywordFilter::class, 'fold' ), (array) ( $site['focus_keywords'] ?? array() ) ), true );
		$add( 'FOCUS_TAKEN', 'Fokus-Keyword nicht schon bei einem anderen Beitrag', ! $taken, false, $taken ? (string) $seo['focus_keyword'] : '' );

		$repeats = self::repeats( $text );
		$add( 'REPETITION', 'Keine Wiederholungen oder Floskeln', array() === $repeats, false, implode( ' | ', $repeats ) );

		$blocking = array();
		$warnings = array();
		foreach ( $checks as $c ) {
			if ( $c['passed'] ) {
				continue;
			}
			$item = array(
				'code'   => $c['code'],
				'detail' => '' !== $c['detail'] ? $c['detail'] : $c['label'],
			);
			if ( $c['blocking'] ) {
				$blocking[] = $item;
			} else {
				$warnings[] = $item;
			}
		}
		return array(
			'checks'         => $checks,
			'blocking'       => $blocking,
			'warnings'       => $warnings,
			'used_claim_ids' => $used,
			'words'          => $words,
		);
	}

	/**
	 * Zahlen im Text gegen verwendete Aussagen und Profil. Eine Zahl aus einer geprüften Aussage des Kontexts,
	 * die nicht in used_claim_ids steht, ergänzt diese Liste.
	 *
	 * @param string                           $text    Text.
	 * @param int[]                            $used    Verwendete Aussagen.
	 * @param array<int, array<string, mixed>> $claims  Aussagen des Kontexts nach ID.
	 * @param array<string, mixed>             $company Profil.
	 * @return array{missing: string[], used: int[], added: int[]}
	 */
	public static function numbers_check( string $text, array $used, array $claims, array $company ): array {
		$allowed = array();
		$profile = array( (string) ( $company['phone'] ?? '' ), (string) ( $company['name'] ?? '' ), implode( ' ', array_map( 'strval', (array) ( $company['services'] ?? array() ) ) ), (string) ( $company['area'] ?? '' ), implode( ' ', array_map( static fn( $d ): string => (string) ( $d['description'] ?? '' ), (array) ( $company['service_details'] ?? array() ) ) ) );
		$phones  = array_filter( array_map( array( self::class, 'phone_digits' ), array_merge( array( (string) ( $company['phone'] ?? '' ) ), array_map( static fn( $l ): string => (string) ( $l['phone'] ?? '' ), (array) ( $company['locations'] ?? array() ) ) ) ) );
		// Lauf 123: Die Zahlen „89, 22, 101, 72“ standen im Hostnamen der E-Mail-Adresse (Staging). Ziffern in
		// E-Mail-Adressen, Internetadressen und Domainnamen sind keine Tatsachen.
		$text = (string) preg_replace( '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+|https?:\/\/\S+|\bwww\.\S+|\b(?:[a-z0-9-]+\.)+(?:de|com|net|org|eu|info|page|io)\b\S*/iu', ' ', $text );
		// Telefonnummern aus dem Profil, auch anders gruppiert oder mit +49 (Lauf 121: „89 22 101 72“).
		$text = (string) preg_replace_callback(
			'/(?<![\d.,])(?:\+|00)?\d[\d\s\p{Zs}\/\-–().]{4,}\d(?![\d])/u',
			static function ( array $m ) use ( $phones ): string {
				$digits = self::phone_digits( $m[0] );
				foreach ( $phones as $phone ) {
					if ( strlen( $digits ) >= 6 && ( $digits === $phone || str_ends_with( $phone, ltrim( $digits, '0' ) ) ) ) {
						return ' ';
					}
				}
				return $m[0];
			},
			$text
		);
		foreach ( (array) ( $company['locations'] ?? array() ) as $location ) {
			$profile[] = implode( ' ', array_map( 'strval', (array) $location ) );
		}
		foreach ( $profile as $value ) {
			foreach ( self::numbers( $value ) as $n ) {
				$allowed[ $n ] = true;
			}
			// Telefonnummer auch als Ganzes ohne Leerzeichen und Striche.
			$digits = (string) preg_replace( '/\D+/', '', $value );
			if ( strlen( $digits ) >= 6 ) {
				$allowed[ $digits ] = true;
			}
		}
		$by_claim = array();
		foreach ( $claims as $id => $claim ) {
			if ( ! in_array( (string) $claim['status'], array( 'verified', 'confirmed' ), true ) ) {
				continue;
			}
			foreach ( self::numbers( (string) $claim['value'] . ' ' . (string) ( $claim['quote'] ?? '' ) ) as $n ) {
				$by_claim[ $n ][] = (int) $id;
			}
		}
		$missing = array();
		$added   = array();
		foreach ( self::numbers( $text, true ) as $n ) {
			if ( isset( $allowed[ $n ] ) ) {
				continue;
			}
			$ids = $by_claim[ $n ] ?? array();
			if ( array() !== array_intersect( $ids, $used ) ) {
				continue;
			}
			if ( array() !== $ids ) {
				$used[]  = $ids[0];
				$added[] = $ids[0];
				continue;
			}
			$missing[] = $n;
		}
		return array(
			'missing' => array_values( array_unique( $missing ) ),
			'used'    => array_values( array_unique( $used ) ),
			'added'   => array_values( array_unique( $added ) ),
		);
	}

	/**
	 * Textstellen der Zahlen (je bis 30 Zeichen davor und danach), damit der Bericht zeigt, wo sie stehen.
	 *
	 * @param string   $text    Text.
	 * @param string[] $numbers Zahlen in Vergleichsform.
	 * @return string[]
	 */
	private static function snippets( string $text, array $numbers ): array {
		$out  = array();
		$flat = (string) preg_replace( '/\s+/u', ' ', $text );
		foreach ( $numbers as $number ) {
			$pattern = '/(?<![\d])' . str_replace( ',', '[,]', preg_quote( $number, '/' ) ) . '(?![\d])/u';
			if ( strlen( $number ) > 3 && ! str_contains( $number, ',' ) ) {
				$pattern = '/(?<![\d])' . implode( '\.?', str_split( $number ) ) . '(?![\d])/u';
			}
			if ( preg_match( $pattern, $flat, $m, PREG_OFFSET_CAPTURE ) ) {
				$start = max( 0, (int) $m[0][1] - 30 );
				$out[] = '„…' . trim( mb_strcut( $flat, $start, 70 ) ) . '…“';
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 5 );
	}

	/**
	 * Ziffern einer Telefonnummer, +49 bzw. 0049 als führende 0.
	 *
	 * @param string $phone Telefonnummer.
	 */
	public static function phone_digits( string $phone ): string {
		$phone  = trim( $phone );
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( str_starts_with( $phone, '+49' ) || str_starts_with( $digits, '0049' ) ) {
			$digits = '0' . substr( $digits, str_starts_with( $digits, '0049' ) ? 4 : 2 );
			$digits = (string) preg_replace( '/^00/', '0', $digits );
		}
		return strlen( $digits ) >= 6 ? $digits : '';
	}

	/**
	 * Zahlen eines Textes in Vergleichsform (ohne Tausenderpunkte und Leerzeichen).
	 *
	 * @param string $text      Text.
	 * @param bool   $for_check Einstellige Zahlen ohne Einheit auslassen.
	 * @return string[]
	 */
	public static function numbers( string $text, bool $for_check = false ): array {
		$out = array();
		if ( ! preg_match_all( '/(?<![\p{L}\d])(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d+))?(\s*(?:€|%|euro|prozent|m²|qm|km|jahre?n?\b))?/iu', $text, $m, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $m as $match ) {
			$number = str_replace( '.', '', $match[1] ) . ( isset( $match[2] ) && '' !== $match[2] ? ',' . $match[2] : '' );
			$unit   = isset( $match[3] ) && '' !== trim( $match[3] );
			if ( $for_check && strlen( $match[1] ) === 1 && ! isset( $match[2][0] ) && ! $unit ) {
				continue;
			}
			$out[] = $number;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Ähnlichster bestehender Titel ab 85 %.
	 *
	 * @param string   $title  Titel.
	 * @param string[] $titles Bestehende Titel.
	 * @return array{title: string, percent: int}|null
	 */
	public static function similar_title( string $title, array $titles ): ?array {
		$a    = KeywordFilter::fold( $title );
		$best = null;
		foreach ( $titles as $other ) {
			$b = KeywordFilter::fold( (string) $other );
			if ( '' === $b || '' === $a ) {
				continue;
			}
			similar_text( $a, $b, $percent );
			if ( $percent >= 85 && ( null === $best || $percent > $best['percent'] ) ) {
				$best = array(
					'title'   => (string) $other,
					'percent' => (int) round( $percent ),
				);
			}
		}
		return $best;
	}

	/**
	 * Übereinstimmung zweier Texte über Folgen von fünf Wörtern (Jaccard).
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 */
	public static function similarity( string $a, string $b ): float {
		$sa = self::shingles( $a );
		$sb = self::shingles( $b );
		if ( array() === $sa || array() === $sb ) {
			return 0.0;
		}
		$inter = count( array_intersect_key( $sa, $sb ) );
		return $inter / count( $sa + $sb );
	}

	/**
	 * Folgen von fünf Wörtern.
	 *
	 * @param string $text Text.
	 * @return array<string, true>
	 */
	private static function shingles( string $text ): array {
		$words = array_values( array_filter( explode( ' ', KeywordFilter::fold( $text ) ) ) );
		$out   = array();
		$total = count( $words );
		for ( $i = 0; $i + 5 <= $total; $i++ ) {
			$out[ implode( ' ', array_slice( $words, $i, 5 ) ) ] = true;
		}
		return $out;
	}

	/**
	 * Steht der Name als ganzes Wort im Text?
	 *
	 * @param string $text Text.
	 * @param string $name Name.
	 */
	public static function contains( string $text, string $name ): bool {
		$key = KeywordFilter::fold( $name );
		return '' !== $key && str_contains( ' ' . KeywordFilter::fold( $text ) . ' ', ' ' . $key . ' ' );
	}

	/**
	 * Adressen der Links im Entwurf.
	 *
	 * @param array<int, array<string, mixed>> $nodes Knoten.
	 * @return string[]
	 */
	private static function hrefs( array $nodes ): array {
		$out = array();
		$raw = (string) json_encode( $nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- die Klasse kennt WordPress nicht.
		if ( preg_match_all( '/href=\\\\?["\']([^"\'\\\\]+)/i', $raw, $m ) ) {
			$out = array_values( array_unique( $m[1] ) );
		}
		return $out;
	}

	/**
	 * Platzhalter im Text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function placeholders( string $text ): array {
		preg_match_all( '/\[[^\]\n]{1,40}\]|\{\{[^}]*\}\}|\blorem ipsum\b|\bplatzhalter\b|\bTODO\b|\bXXX\b|\bTBD\b/iu', $text, $m );
		return array_values( array_unique( $m[0] ) );
	}

	/**
	 * Wiederholte Sätze und Floskeln.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function repeats( string $text ): array {
		$out   = array();
		$seen  = array();
		$parts = preg_split( '/(?<=[.!?])\s+|\n/u', $text );
		foreach ( false === $parts ? array() : $parts as $sentence ) {
			$key = KeywordFilter::fold( $sentence );
			if ( mb_strlen( $key ) < 40 ) {
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$out[] = 'wiederholt: „' . mb_substr( trim( $sentence ), 0, 80 ) . '“';
			}
			$seen[ $key ] = true;
		}
		$lower = mb_strtolower( $text );
		$found = array_values( array_filter( self::PHRASES, static fn( string $p ): bool => str_contains( $lower, $p ) ) );
		if ( count( $found ) >= 2 ) {
			$out[] = 'Floskeln: ' . implode( ', ', $found );
		}
		return array_values( array_unique( $out ) );
	}
}
