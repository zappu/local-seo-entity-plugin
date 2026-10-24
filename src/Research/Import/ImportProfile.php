<?php
/**
 * Importvorlagen für Keyword-Tools.
 *
 * @package Lsec
 */

namespace Lsec\Research\Import;

/**
 * Vorlage: welche Spalte welches Feld enthält und welche Regeln gelten (Spezifikation 6.6).
 */
final class ImportProfile {

	/**
	 * Felder, die ein Import befüllen kann.
	 */
	public const FIELDS = array( 'keyword', 'volume', 'difficulty', 'cpc', 'competition', 'intent', 'trend' );

	/**
	 * Spaltennamen, die für die allgemeine Vorlage erkannt werden (klein geschrieben, ohne Klammerzusatz).
	 */
	private const ALIASES = array(
		'keyword'     => array( 'keyword', 'keywords', 'expression', 'suchbegriff', 'suchanfrage', 'begriff', 'query', 'mot-clé', 'mot clé', 'mots-clés', 'term' ),
		'volume'      => array( 'searches', 'monthly volume', 'suchvolumen', 'volume', 'search volume', 'avg', 'avg. monthly searches', 'monatliches suchvolumen', 'durchschnittliches suchvolumen', 'sv', 'volumen' ),
		'difficulty'  => array( 'difficulty', 'schwierigkeit', 'kd', 'keyword difficulty', 'seo difficulty' ),
		'cpc'         => array( 'cpc', 'cost per click', 'kosten pro klick' ),
		'competition' => array( 'competition', 'konkurrenz', 'wettbewerb', 'concurrency' ),
		'intent'      => array( 'search_intent', 'intent', 'suchintention', 'search intent' ),
		'trend'       => array( 'historical_volume', 'trend', 'verlauf' ),
	);

	/**
	 * Konstruktor.
	 *
	 * @param string                $id                  Kennung der Vorlage.
	 * @param string                $label               Anzeigename.
	 * @param string                $source              Quellkennung in lsec_keyword_metrics.
	 * @param array<string, string> $mapping             Feld => Spaltenname.
	 * @param bool                  $all_zero_is_unknown Alle Volumina 0 bedeutet „unbekannt“.
	 * @param string[]              $zero_is_unknown     Felder, in denen jede 0 „unbekannt“ bedeutet (Textfocus).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $source,
		public readonly array $mapping,
		public readonly bool $all_zero_is_unknown = true,
		public readonly array $zero_is_unknown = array()
	) {}

	/**
	 * Ob die Kopfzeile zur Entitäten-Tabelle einer Textfocus-Analyse gehört (keine Keyword-Liste).
	 *
	 * @param string[] $header Kopfzeile.
	 */
	public static function is_entity_table( array $header ): bool {
		$keys = array_map( array( self::class, 'header_key' ), $header );
		return in_array( 'entity detected on the page', $keys, true ) && in_array( 'wikidata id', $keys, true );
	}

	/**
	 * Wählt die Vorlage anhand der Kopfzeile.
	 *
	 * @param string[] $header    Kopfzeile.
	 * @param string   $format    csv oder xlsx.
	 * @param string   $requested Gewünschte Vorlage oder leer für automatische Erkennung.
	 * @throws \InvalidArgumentException Bei unbekannter Vorlage.
	 */
	public static function detect( array $header, string $format = 'csv', string $requested = '' ): self {
		$keys = array_map( array( self::class, 'header_key' ), $header );

		$revealer = in_array( 'keywordsinclude', $keys, true ) && in_array( 'historical_volume', $keys, true );
		$chef     = array() === array_diff( array( 'keyword', 'avg', 'high', 'low' ), $keys );
		$tf_vol   = in_array( 'expression', $keys, true ) && in_array( 'monthly volume', $keys, true );
		if ( self::is_entity_table( $header ) ) {
			throw new \InvalidArgumentException( 'Das ist die Entitäten-Tabelle einer Textfocus-Analyse, keine Keyword-Liste. Auf der Seite „Keywords“ hochgeladen, übernimmt das Plugin sie als Entitäten.' );
		}

		if ( '' === $requested ) {
			$requested = $revealer ? 'keyword_revealer' : ( $chef ? 'keyword_chef' : ( $tf_vol ? 'textfocus' : 'generic' ) );
		}

		switch ( $requested ) {
			case 'keyword_revealer':
				return new self(
					'keyword_revealer',
					'Keyword Revealer',
					'csv:keyword_revealer',
					self::resolve(
						$header,
						array(
							'keyword'    => 'keyword',
							'volume'     => 'searches',
							'difficulty' => 'difficulty',
							'cpc'        => 'cpc',
							'intent'     => 'search_intent',
							'trend'      => 'historical_volume',
						)
					)
				);
			case 'keyword_chef':
				return new self(
					'keyword_chef',
					'Keyword Chef',
					'csv:keyword_chef',
					self::resolve(
						$header,
						array(
							'keyword' => 'keyword',
							'volume'  => 'avg',
						)
					)
				);
			case 'textfocus':
				// Export „Keyword volume search“ (geprüft am 18.10.2026): Expression, Monthly volume, CPC,
				// Competition, Difficulty, Words, Interest, Type of volume. Textfocus schreibt 0, wenn keine Daten vorliegen.
				return new self(
					'textfocus',
					'Textfocus',
					$format . ':textfocus',
					self::resolve(
						$header,
						array(
							'keyword'     => 'expression',
							'volume'      => 'monthly volume',
							'cpc'         => 'cpc',
							'competition' => 'competition',
							'difficulty'  => 'difficulty',
						)
					) + self::guess( $header ),
					false,
					array( 'volume', 'cpc', 'difficulty', 'competition' )
				);
			case 'generic':
				return new self( 'generic', 'Allgemein', $format . ':generic', self::guess( $header ) );
			default:
				throw new \InvalidArgumentException( 'Unbekannte Vorlage: ' . $requested . ' (keyword_revealer, keyword_chef, textfocus, generic).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Überschreibt einzelne Zuordnungen, z. B. aus --map=keyword=Begriff,volume=SV.
	 *
	 * @param string   $map    Zuordnung als Text.
	 * @param string[] $header Kopfzeile.
	 * @throws \InvalidArgumentException Bei unbekanntem Feld oder fehlender Spalte.
	 */
	public function with_map( string $map, array $header ): self {
		if ( '' === trim( $map ) ) {
			return $this;
		}
		$mapping = $this->mapping;
		foreach ( explode( ',', $map ) as $pair ) {
			$parts = array_map( 'trim', explode( '=', $pair, 2 ) );
			if ( 2 !== count( $parts ) || ! in_array( $parts[0], self::FIELDS, true ) ) {
				throw new \InvalidArgumentException( 'Ungültige Zuordnung: ' . $pair . '. Felder: ' . implode( ', ', self::FIELDS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$column = self::find_column( $header, $parts[1] );
			if ( null === $column ) {
				throw new \InvalidArgumentException( 'Spalte nicht gefunden: ' . $parts[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$mapping[ $parts[0] ] = $column;
		}
		return new self( $this->id, $this->label, $this->source, $mapping, $this->all_zero_is_unknown, $this->zero_is_unknown );
	}

	/**
	 * Ersetzt die Zuordnung vollständig, z. B. aus der Vorschau im Backend. Leere Werte lassen das Feld weg.
	 *
	 * @param array<string, string> $mapping Feld => Spalte.
	 * @param string[]              $header  Kopfzeile.
	 * @throws \InvalidArgumentException Bei unbekanntem Feld oder fehlender Spalte.
	 */
	public function with_mapping( array $mapping, array $header ): self {
		$clean = array();
		foreach ( $mapping as $field => $column ) {
			$column = (string) $column;
			if ( '' === $column ) {
				continue;
			}
			if ( ! in_array( $field, self::FIELDS, true ) ) {
				throw new \InvalidArgumentException( 'Unbekanntes Feld: ' . $field ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$found = self::find_column( $header, $column );
			if ( null === $found ) {
				throw new \InvalidArgumentException( 'Spalte nicht gefunden: ' . $column ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$clean[ (string) $field ] = $found;
		}
		return new self( $this->id, $this->label, $this->source, $clean, $this->all_zero_is_unknown, $this->zero_is_unknown );
	}

	/**
	 * Kopie mit anderer Quellkennung.
	 *
	 * @param string $source Quelle, z. B. csv:sistrix.
	 */
	public function with_source( string $source ): self {
		return new self( $this->id, $this->label, $source, $this->mapping, $this->all_zero_is_unknown, $this->zero_is_unknown );
	}

	/**
	 * Vergleichsschlüssel für Spaltennamen: klein, ohne Klammerzusatz und Leerraum am Rand.
	 *
	 * @param string $name Spaltenname.
	 */
	public static function header_key( string $name ): string {
		$name = (string) preg_replace( '/\s*\(.*?\)\s*/u', ' ', $name );
		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $name ) ), 'UTF-8' );
	}

	/**
	 * Feste Zuordnung auf die tatsächlichen Spaltennamen übertragen.
	 *
	 * @param string[]              $header  Kopfzeile.
	 * @param array<string, string> $wanted  Feld => erwarteter Schlüssel.
	 * @return array<string, string>
	 */
	private static function resolve( array $header, array $wanted ): array {
		$mapping = array();
		foreach ( $wanted as $field => $key ) {
			$column = self::find_column( $header, $key );
			if ( null !== $column ) {
				$mapping[ $field ] = $column;
			}
		}
		return $mapping;
	}

	/**
	 * Zuordnung über bekannte Spaltennamen.
	 *
	 * @param string[] $header Kopfzeile.
	 * @return array<string, string>
	 */
	private static function guess( array $header ): array {
		$mapping = array();
		foreach ( self::ALIASES as $field => $aliases ) {
			foreach ( $header as $column ) {
				if ( in_array( self::header_key( $column ), $aliases, true ) && ! in_array( $column, $mapping, true ) ) {
					$mapping[ $field ] = $column;
					break;
				}
			}
		}
		return $mapping;
	}

	/**
	 * Sucht eine Spalte ohne Rücksicht auf Groß-/Kleinschreibung.
	 *
	 * @param string[] $header Kopfzeile.
	 * @param string   $name   Gesuchter Name.
	 */
	private static function find_column( array $header, string $name ): ?string {
		foreach ( $header as $column ) {
			if ( $column === $name || self::header_key( $column ) === self::header_key( $name ) ) {
				return $column;
			}
		}
		return null;
	}
}
