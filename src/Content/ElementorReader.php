<?php
/**
 * Liest den Text von Elementor-Seiten.
 *
 * @package Lsec
 */

namespace Lsec\Content;

/**
 * Wertet _elementor_data aus (Prüfpunkt M14). Elementor speichert die Seite als JSON-Baum aus
 * Abschnitten, Spalten und Widgets; der sichtbare Text steht in den Einstellungen der Widgets.
 * Gelesen werden bekannte Textfelder; Links, Farben, IDs und Layoutwerte werden übergangen.
 */
final class ElementorReader {

	/**
	 * Einstellungen, die sichtbaren Text enthalten.
	 */
	private const TEXT_KEYS = array(
		'title',
		'editor',
		'text',
		'description',
		'title_text',
		'description_text',
		'tab_title',
		'tab_content',
		'testimonial_content',
		'testimonial_name',
		'testimonial_job',
		'item_description',
		'caption',
		'blockquote_content',
		'alert_title',
		'alert_description',
		'prefix',
		'suffix',
		'before_text',
		'highlighted_text',
		'rotating_text',
		'after_text',
		'inner_text',
		'heading',
		'sub_heading',
		'content',
		'excerpt',
		'question',
		'answer',
	);

	/**
	 * Widgets ohne lesbaren Inhalt für den Index.
	 */
	private const SKIP_WIDGETS = array( 'html', 'shortcode', 'menu-anchor', 'spacer', 'divider', 'google_maps', 'video', 'image', 'icon', 'sidebar' );

	/**
	 * Wertet die Daten aus.
	 *
	 * @param string $json Inhalt von _elementor_data.
	 * @return array{text: string, headings: array<int, array{level: int, text: string}>, widgets: int}|null Null bei ungültigem JSON.
	 */
	public static function read( string $json ): ?array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$state = array(
			'parts'    => array(),
			'headings' => array(),
			'widgets'  => 0,
		);
		self::walk( $data, $state );
		return array(
			'text'     => trim( implode( "\n\n", $state['parts'] ) ),
			'headings' => $state['headings'],
			'widgets'  => $state['widgets'],
		);
	}

	/**
	 * Durchläuft den Baum in Lesereihenfolge.
	 *
	 * @param array<mixed>                                                                                $elements Elemente.
	 * @param array{parts: string[], headings: array<int, array{level: int, text: string}>, widgets: int} $state    Zwischenstand.
	 */
	private static function walk( array $elements, array &$state ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type = (string) ( $element['elType'] ?? '' );
			if ( 'widget' === $type ) {
				++$state['widgets'];
				self::widget( $element, $state );
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk( $element['elements'], $state );
			}
		}
	}

	/**
	 * Text eines Widgets.
	 *
	 * @param array<string, mixed>                                                                        $widget Widget.
	 * @param array{parts: string[], headings: array<int, array{level: int, text: string}>, widgets: int} $state  Zwischenstand.
	 */
	private static function widget( array $widget, array &$state ): void {
		$name     = (string) ( $widget['widgetType'] ?? '' );
		$settings = is_array( $widget['settings'] ?? null ) ? $widget['settings'] : array();
		if ( in_array( $name, self::SKIP_WIDGETS, true ) ) {
			return;
		}
		if ( 'heading' === $name || 'animated-headline' === $name ) {
			$text = self::plain( (string) ( $settings['title'] ?? implode( ' ', array_filter( array( $settings['before_text'] ?? '', $settings['highlighted_text'] ?? '', $settings['after_text'] ?? '' ) ) ) ) );
			if ( '' !== $text ) {
				$size                = (string) ( $settings['header_size'] ?? 'h2' );
				$level               = preg_match( '/^h([1-6])$/', $size, $m ) ? (int) $m[1] : 0;
				$state['parts'][]    = $text;
				$state['headings'][] = array(
					'level' => $level,
					'text'  => $text,
				);
			}
			return;
		}
		foreach ( self::strings( $settings ) as $text ) {
			$state['parts'][] = $text;
		}
	}

	/**
	 * Sammelt Textfelder, auch aus Wiederholungen wie Tabs oder Listen.
	 *
	 * @param array<mixed> $settings Einstellungen.
	 * @return string[]
	 */
	private static function strings( array $settings ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( array_is_list( $value ) ) {
					foreach ( $value as $item ) {
						if ( is_array( $item ) ) {
							$out = array_merge( $out, self::strings( $item ) );
						}
					}
				}
				continue;
			}
			if ( ! is_string( $key ) || ! is_string( $value ) || str_starts_with( $key, '_' ) || ! in_array( $key, self::TEXT_KEYS, true ) ) {
				continue;
			}
			$text = self::plain( $value );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}
		return $out;
	}

	/**
	 * HTML zu Text mit Absätzen.
	 *
	 * @param string $html HTML.
	 */
	public static function plain( string $html ): string {
		$html = (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<br\s*/?>|</(p|div|li|h[1-6]|tr)>#i', "\n", $html );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- ohne WordPress testbar, script und style sind entfernt.
		$text = (string) preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = (string) preg_replace( "/\s*\n\s*/", "\n", $text );
		return trim( $text );
	}
}
