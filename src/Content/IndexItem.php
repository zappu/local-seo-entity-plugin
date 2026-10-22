<?php
/**
 * Ein Inhalt im Inhaltsindex: Beitrag, Seite, Produkt oder Kategorieseite.
 *
 * @package Lsec
 */

namespace Lsec\Content;

/**
 * Fasst Beiträge (WP_Post) und Kategorieseiten (WP_Term) zusammen, damit Index und Entity-Erkennung
 * beide gleich behandeln. Kennung in Warteschlangen: „post:12“ oder „term:34“; eine reine Zahl ist
 * ein Beitrag (ältere Abläufe).
 */
final class IndexItem {

	/**
	 * Konstruktor.
	 *
	 * @param string            $object_type post oder term.
	 * @param int               $id     ID.
	 * @param string            $kind   Inhaltstyp oder Taxonomie.
	 * @param \WP_Post|\WP_Term $source WordPress-Objekt.
	 */
	private function __construct(
		public readonly string $object_type,
		public readonly int $id,
		public readonly string $kind,
		private readonly \WP_Post|\WP_Term $source
	) {}

	/**
	 * Aus einem Beitrag.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public static function from_post( \WP_Post $post ): self {
		return new self( 'post', (int) $post->ID, (string) $post->post_type, $post );
	}

	/**
	 * Aus einer Kategorie.
	 *
	 * @param \WP_Term $term Kategorie.
	 */
	public static function from_term( \WP_Term $term ): self {
		return new self( 'term', (int) $term->term_id, (string) $term->taxonomy, $term );
	}

	/**
	 * Aus einer Kennung („post:12“, „term:34“ oder 12).
	 *
	 * @param int|string $ref Kennung.
	 */
	public static function from_ref( int|string $ref ): ?self {
		[ $object_type, $id ] = self::parse_ref( $ref );
		return self::load( $object_type, $id );
	}

	/**
	 * Lädt ein Objekt.
	 *
	 * @param string $object_type post oder term.
	 * @param int    $id     ID.
	 */
	public static function load( string $object_type, int $id ): ?self {
		if ( $id <= 0 ) {
			return null;
		}
		if ( 'term' === $object_type ) {
			$term = get_term( $id );
			return $term instanceof \WP_Term ? self::from_term( $term ) : null;
		}
		$post = get_post( $id );
		return $post instanceof \WP_Post ? self::from_post( $post ) : null;
	}

	/**
	 * Zerlegt eine Kennung.
	 *
	 * @param int|string $ref Kennung.
	 * @return array{0: string, 1: int}
	 */
	public static function parse_ref( int|string $ref ): array {
		$ref = trim( (string) $ref );
		if ( preg_match( '/^(post|term|t|p):?(\d+)$/', $ref, $m ) ) {
			return array( in_array( $m[1], array( 'term', 't' ), true ) ? 'term' : 'post', (int) $m[2] );
		}
		return array( 'post', (int) $ref );
	}

	/**
	 * Einheitliche Kennung („post:12“) aus jeder erlaubten Schreibweise.
	 *
	 * @param int|string $ref Kennung.
	 */
	public static function normalize_ref( int|string $ref ): string {
		[ $object_type, $id ] = self::parse_ref( $ref );
		return $object_type . ':' . $id;
	}

	/**
	 * Kennung für Warteschlangen.
	 */
	public function ref(): string {
		return $this->object_type . ':' . $this->id;
	}

	/**
	 * Ist der Inhalt öffentlich? Kategorien gelten immer als öffentlich.
	 */
	public function is_published(): bool {
		return ! $this->source instanceof \WP_Post || 'publish' === $this->source->post_status;
	}

	/**
	 * Titel als Klartext.
	 */
	public function title(): string {
		return $this->source instanceof \WP_Post ? ContentReader::title( $this->source ) : ElementorReader::plain( $this->source->name );
	}

	/**
	 * Text, Überschriften, Quelle und Wortzahl.
	 *
	 * Produkte: Kurzbeschreibung vor der Beschreibung. Kategorien: Kategoriebeschreibung.
	 *
	 * @return array{text: string, headings: array<int, array{level: int, text: string}>, source: string, words: int}
	 */
	public function read(): array {
		if ( $this->source instanceof \WP_Term ) {
			return ContentReader::read_html( (string) $this->source->description, 'term' );
		}
		$content = ContentReader::read( $this->source );
		if ( 'product' === $this->source->post_type && '' !== trim( $this->source->post_excerpt ) ) {
			$short = ElementorReader::plain( (string) wpautop( $this->source->post_excerpt ) );
			if ( '' !== $short && ! str_contains( $content['text'], $short ) ) {
				$content['text']  = trim( $short . "\n" . $content['text'] );
				$content['words'] = ContentReader::words( $content['text'] );
			}
		}
		return $content;
	}

	/**
	 * Kategorien des Inhalts (Namen). Bei Kategorien die übergeordnete.
	 *
	 * @return string[]
	 */
	public function categories(): array {
		if ( $this->source instanceof \WP_Term ) {
			$parent = $this->source->parent > 0 ? get_term( $this->source->parent, $this->source->taxonomy ) : null;
			return $parent instanceof \WP_Term ? array( (string) $parent->name ) : array();
		}
		$taxonomy = match ( $this->source->post_type ) {
			'post'    => 'category',
			'product' => 'product_cat',
			default   => '',
		};
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$names = wp_get_object_terms( $this->id, $taxonomy, array( 'fields' => 'names' ) );
		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	/**
	 * Adresse im Frontend.
	 */
	public function url(): string {
		if ( $this->source instanceof \WP_Term ) {
			$link = get_term_link( $this->source );
			return is_string( $link ) ? $link : '';
		}
		return (string) get_permalink( $this->source );
	}

	/**
	 * Metawert (Post- oder Term-Meta).
	 *
	 * @param string $key Schlüssel.
	 */
	public function meta( string $key ): mixed {
		return 'term' === $this->object_type ? get_term_meta( $this->id, $key, true ) : get_post_meta( $this->id, $key, true );
	}

	/**
	 * Setzt einen Metawert.
	 *
	 * @param string $key   Schlüssel.
	 * @param mixed  $value Wert.
	 */
	public function set_meta( string $key, mixed $value ): void {
		if ( 'term' === $this->object_type ) {
			update_term_meta( $this->id, $key, $value );
			return;
		}
		update_post_meta( $this->id, $key, $value );
	}

	/**
	 * Löscht einen Metawert.
	 *
	 * @param string $key Schlüssel.
	 */
	public function delete_meta( string $key ): void {
		if ( 'term' === $this->object_type ) {
			delete_term_meta( $this->id, $key );
			return;
		}
		delete_post_meta( $this->id, $key );
	}
}
