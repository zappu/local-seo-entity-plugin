<?php
/**
 * Recherche geprüfter Tatsachen zu einem Ort für lokale Seiten.
 *
 * @package Lsec
 */

namespace Lsec\Production;

use Lsec\Entities\PlaceName;
use Lsec\Topics\TopicResearcher;

/**
 * Nachtrag 23.10. (0.7.13): Lokale Seiten brauchen mindestens zwei geprüfte Tatsachen zum Ort (10.1). Für
 * Lehrte (Thema 4) gab es keine, der Entwurf war deshalb blockiert. Fehlen sie, recherchiert der Schritt
 * place_facts vor dem Briefing Tatsachen zum Ort mit Websuche; die Faktenprüfung aus Stufe D prüft sie an
 * den Quellen. Gespeichert werden sie an der Entität des Ortes und stehen danach allen Themen zu diesem
 * Ort zur Verfügung.
 *
 * Auftrag, Schema und Auswertung; die Klasse kennt WordPress nicht.
 */
final class PlaceFacts {

	/**
	 * Systemanweisung der Websuche.
	 */
	public static function system(): string {
		return 'Du recherchierst überprüfbare Tatsachen über deutsche Orte für die Website eines lokalen Unternehmens. Du nennst nur, was in einer Quelle steht, mit Quelle. Antworte auf Deutsch.';
	}

	/**
	 * Frage an die Websuche.
	 *
	 * @param string $place Ort.
	 * @param string $area  Servicegebiet (zur Einordnung).
	 * @param string $topic Thema des Beitrags.
	 */
	public static function question( string $place, string $area, string $topic ): string {
		return sprintf(
			"Recherchiere überprüfbare Tatsachen über den Ort %s%s. Sie sollen auf einer lokalen Seite zum Thema „%s“ stehen.\n"
			. "Gesucht sind zum Beispiel: Lage (Landkreis, Region, Nachbarorte, Flüsse), Ortsteile, Einwohnerzahl mit Stand, erste urkundliche Erwähnung, Verkehrsanbindung (Autobahn, Bahnhof), Gewerbegebiete, bekannte Plätze, Straßen oder Gebäude, Klima oder Boden, soweit für das Thema nützlich.\n"
			. "Ist der Ort klein oder ein Ortsteil, gehören auch Tatsachen dazu, in denen der Ort genannt wird, etwa zu welcher Gemeinde er gehört und was ihn dort auszeichnet.\n"
			. 'Nenne zu jeder Tatsache die Quelle (Adresse) und einen wörtlichen Satz aus der Quelle, der den Ort nennt. Bevorzuge amtliche Seiten des Ortes, Wikipedia und Landesämter, und zwar Webseiten, keine PDF-Dateien (PDF-Quellen lassen sich nicht prüfen; Einwohnerzahlen und Flächen stehen meist auch auf Wikipedia). Keine Bewertungen, keine Unternehmen.',
			$place,
			'' !== $area ? ' (Servicegebiet: ' . $area . ')' : '',
			$topic
		);
	}

	/**
	 * Auftrag zur Strukturierung des Rechercheergebnisses.
	 *
	 * @param string                                        $place    Ort.
	 * @param string                                        $research Rechercheergebnis.
	 * @param array<int, array{url: string, title: string}> $sources  Quellen.
	 */
	public static function structure_prompt( string $place, string $research, array $sources ): string {
		$list = '';
		foreach ( $sources as $source ) {
			$list .= '- ' . $source['url'] . ( '' !== $source['title'] ? ' (' . $source['title'] . ')' : '' ) . "\n";
		}
		return "Ort: {$place}\n\nRechercheergebnis:\n<<<\n{$research}\n>>>\n\nQuellen:\n" . ( '' !== $list ? $list : "(keine)\n" )
			. "\nfacts: Tatsachen über {$place} aus dem Rechercheergebnis, höchstens acht. property kurz und deutsch (z. B. einwohner, erste_erwaehnung, fluss, landkreis, ortsteil, autobahn), value der Wert, source_url eine Adresse aus den Quellen, quote ein wörtlicher Satz aus der Quelle, der den Wert enthält. Nur Tatsachen über {$place} selbst, nicht über andere Orte.\n";
	}

	/**
	 * JSON-Schema (strikt).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$string = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'facts' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'property'   => $string,
							'value'      => $string,
							'source_url' => $string,
							'quote'      => $string,
						),
						'required'             => array( 'property', 'value', 'source_url', 'quote' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'facts' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Prüft die Antwort: Quelle und Zitat vorhanden, Adresse gültig; höchstens acht Tatsachen.
	 *
	 * @param array<mixed>                                  $data    Antwort.
	 * @param string                                        $place   Ort.
	 * @param array<int, array{url: string, title: string}> $sources Quellen der Websuche.
	 * @return array{facts: array<int, array<string, mixed>>, dropped: string[]}
	 */
	public static function validate( array $data, string $place, array $sources ): array {
		$urls = array();
		foreach ( $sources as $source ) {
			$urls[ TopicResearcher::url_key( $source['url'] ) ] = $source['url'];
		}
		$facts   = array();
		$dropped = array();
		$seen    = array();
		foreach ( (array) ( $data['facts'] ?? array() ) as $fact ) {
			if ( ! is_array( $fact ) ) {
				continue;
			}
			$property = mb_substr( trim( (string) ( $fact['property'] ?? '' ) ), 0, 100 );
			$value    = trim( (string) ( $fact['value'] ?? '' ) );
			$url      = trim( (string) ( $fact['source_url'] ?? '' ) );
			$quote    = trim( (string) ( $fact['quote'] ?? '' ) );
			$label    = sprintf( '%s: %s = %s', $place, $property, $value );
			if ( '' === $property || '' === $value ) {
				continue;
			}
			if ( '' === $quote || '' === $url ) {
				$dropped[] = $label . ' – ohne Quelle oder Zitat';
				continue;
			}
			if ( ! isset( $urls[ TopicResearcher::url_key( $url ) ] ) && ! preg_match( '#^https?://#i', $url ) ) {
				$dropped[] = $label . ' – keine gültige Quelle';
				continue;
			}
			$key = PlaceName::key( $property . ' ' . $value );
			if ( isset( $seen[ $key ] ) || count( $facts ) >= 8 ) {
				continue;
			}
			$seen[ $key ] = true;
			$facts[]      = array(
				'subject'      => $place,
				'subject_type' => 'place',
				'property'     => $property,
				'value'        => $value,
				'source_url'   => $urls[ TopicResearcher::url_key( $url ) ] ?? $url,
				'quote'        => $quote,
			);
		}
		return array(
			'facts'   => $facts,
			'dropped' => $dropped,
		);
	}
}
