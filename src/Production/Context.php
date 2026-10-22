<?php
/**
 * Kontext eines Beitrags als Text für die KI.
 *
 * @package Lsec
 */

namespace Lsec\Production;

/**
 * Gemeinsame Darstellung für Briefing, Entwurf und Bewertung. Aussagen erscheinen mit Nummer, Wert und
 * Zitat, damit die KI sie zuordnen kann; Linkziele mit Nummer, Titel und Adresse. Die Klasse kennt WordPress nicht.
 */
final class Context {

	/**
	 * Kontext als Text.
	 *
	 * @param array<string, mixed> $context Kontext aus gather_context.
	 * @param bool                 $links   Linkziele mit ausgeben.
	 */
	public static function text( array $context, bool $links = true ): string {
		$company = (array) $context['company'];
		$out     = "Unternehmen: {$company['name']}\n";
		foreach ( (array) ( $company['locations'] ?? array() ) as $location ) {
			$out .= sprintf( "Standort: %s, %s %s, %s, Telefon %s\n", $location['name'], $location['street'], $location['postal_code'], $location['city'], $location['phone'] );
		}
		foreach ( array(
			'phone'   => 'Telefon',
			'email'   => 'E-Mail',
			'website' => 'Website',
		) as $key => $label ) {
			if ( '' !== (string) ( $company[ $key ] ?? '' ) ) {
				$out .= $label . ': ' . $company[ $key ] . "\n";
			}
		}
		$out .= 'Leistungen: ' . implode( ', ', (array) $company['services'] ) . "\n";
		$out .= 'Servicegebiet: ' . ( '' !== (string) $context['area'] ? $context['area'] : 'nicht festgelegt' ) . "\n\n";

		$out .= "Entitäten (#ID Name, Typ, Rolle):\n";
		foreach ( (array) $context['entities'] as $entity ) {
			$out .= sprintf( "#%d %s, %s, %s\n", $entity['id'], $entity['name'], $entity['type'], $entity['role'] );
		}
		$out .= "\nGeprüfte Tatsachen (#ID Gegenstand: Eigenschaft = Wert – Zitat aus der Quelle):\n";
		if ( array() === (array) $context['claims'] ) {
			$out .= "(keine)\n";
		}
		foreach ( (array) $context['claims'] as $claim ) {
			$out .= sprintf( "#%d %s: %s = %s – <<<%s>>> (%s)\n", $claim['id'], $claim['entity'], $claim['property'], $claim['value'], mb_substr( (string) $claim['quote'], 0, 300 ), $claim['source_url'] );
		}
		$out .= "\nKeywords (Haupt zuerst):\n";
		foreach ( (array) $context['keywords'] as $keyword ) {
			$out .= sprintf( "- %s%s%s\n", $keyword['keyword'], 'primary' === $keyword['role'] ? ' (Haupt)' : '', null === $keyword['volume'] ? '' : ', Volumen ' . $keyword['volume'] );
		}
		if ( $links ) {
			$out .= "\nLinkziele auf der eigenen Website (#post_id Titel – Adresse):\n";
			foreach ( (array) $context['link_targets'] as $target ) {
				$out .= sprintf( "#%d %s – %s\n", $target['post_id'], $target['title'], $target['url'] );
			}
		}
		if ( array() !== (array) ( $context['categories'] ?? array() ) ) {
			$out .= "\nKategorien (#ID Name): " . implode( ', ', array_map( static fn( array $c ): string => '#' . $c['id'] . ' ' . $c['name'], (array) $context['categories'] ) ) . "\n";
		}
		$out .= "\nSchreibstil:\n" . (string) $context['style'] . "\n";
		return $out;
	}

	/**
	 * Nachschlagetabelle nach ID.
	 *
	 * @param array<int, array<string, mixed>> $rows Zeilen.
	 * @param string                           $key  Schlüssel der ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function ids( array $rows, string $key = 'id' ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row[ $key ] ] = $row;
		}
		return $out;
	}
}
