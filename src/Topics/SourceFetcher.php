<?php
/**
 * Ruft Quellen der Recherche ab.
 *
 * @package Lsec
 */

namespace Lsec\Topics;

use Lsec\Entities\ProfileAssistant;
use Lsec\Research\SourceRepository;

/**
 * Spezifikation 8.4a, Schritt 1: wp_safe_remote_get mit 20 s Zeitlimit, höchstens 2 MB, nur http/https,
 * 3 Weiterleitungen. HTML wird zu Text (Absätze bleiben als Zeilen erhalten). Text oder Fehler stehen
 * 30 Tage in lsec_sources. Eine nicht abrufbare Seite ist kein Fehler des Ablaufs.
 */
final class SourceFetcher {

	/**
	 * Konstruktor.
	 *
	 * @param SourceRepository $sources Quellen.
	 */
	public function __construct( private readonly SourceRepository $sources ) {}

	/**
	 * Text einer Quelle, aus dem Zwischenspeicher oder neu abgerufen.
	 *
	 * @param int $source_id Quelle.
	 * @return array{text: string|null, error: string|null, cached: bool}
	 */
	public function text( int $source_id ): array {
		$cached = $this->sources->cached_content( $source_id );
		if ( null !== $cached ) {
			return $cached + array( 'cached' => true );
		}
		$row = $this->sources->find( $source_id );
		if ( null === $row ) {
			return array(
				'text'   => null,
				'error'  => 'Quelle unbekannt',
				'cached' => false,
			);
		}
		$out = self::fetch( (string) $row['url'] );
		$this->sources->save_content( $source_id, $out['text'], $out['error'], $out['title'] );
		return array(
			'text'   => $out['text'],
			'error'  => $out['error'],
			'cached' => false,
		);
	}

	/**
	 * Ruft eine Seite ab.
	 *
	 * @param string $url Adresse.
	 * @return array{text: string|null, error: string|null, title: string}
	 */
	public static function fetch( string $url ): array {
		$fail = static fn( string $error ): array => array(
			'text'  => null,
			'error' => $error,
			'title' => '',
		);
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return $fail( 'nur http und https' );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => 2 * 1024 * 1024,
				'user-agent'          => ProfileAssistant::BROWSER_UA,
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.8' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $fail( mb_substr( $response->get_error_message(), 0, 90 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $fail( 'HTTP ' . $code );
		}
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' !== $type && ! str_contains( $type, 'html' ) && ! str_contains( $type, 'text/plain' ) ) {
			return $fail( 'kein HTML (' . mb_substr( $type, 0, 40 ) . ')' );
		}
		$html  = (string) wp_remote_retrieve_body( $response );
		$title = preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ? html_entity_decode( trim( wp_strip_all_tags( $m[1] ) ), ENT_QUOTES, 'UTF-8' ) : '';
		$text  = str_contains( $type, 'text/plain' ) ? $html : ProfileAssistant::html_text( $html );
		if ( mb_strlen( trim( $text ) ) < 50 ) {
			return $fail( 'kein lesbarer Text (Anmeldung oder Skript-Inhalt?)' );
		}
		return array(
			'text'  => $text,
			'error' => null,
			'title' => $title,
		);
	}
}
