<?php
/**
 * Entity-Erkennung in einem Beitrag.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiService;
use Lsec\Research\Keywords\KeywordNormalizer;

/**
 * Spezifikation 8.3, Prüfpunkt M11. Die KI schlägt Entitäten mit wörtlichen Fundstellen vor.
 * Übernommen wird ein Vorschlag nur, wenn mindestens eine Fundstelle tatsächlich im Text steht;
 * so gelangen keine erfundenen Belege in die Datenbank. Alle Vorschläge bleiben Kandidaten.
 */
final class EntityExtractor {

	public const TYPES = array( 'organization', 'brand', 'place', 'service_area', 'service', 'product', 'person', 'competitor', 'concept' );

	/**
	 * Höchstzahl Zeichen, die an die KI gehen.
	 */
	private const MAX_CHARS = 15000;

	/**
	 * Konstruktor.
	 *
	 * @param AiService $ai KI.
	 */
	public function __construct( private readonly AiService $ai ) {}

	/**
	 * Erkennt Entitäten.
	 *
	 * @param string   $title  Titel.
	 * @param string   $text   Text des Beitrags.
	 * @param string[] $known  Bereits bekannte Entitäten (Namen), damit die KI dieselbe Schreibweise nutzt.
	 * @param int|null $run_id Ablauf.
	 * @return array{entities: array<int, array{type: string, name: string, aliases: string[], description: string, confidence: float, evidence: array<int, array{excerpt: string, location: string}>}>, rejected: array<int, array{name: string, reason: string}>, ambiguities: array<int, array{name: string, reason: string}>, cost: float|null}
	 */
	public function extract( string $title, string $text, array $known = array(), ?int $run_id = null ): array {
		$excerpt  = mb_substr( $text, 0, self::MAX_CHARS );
		$system   = 'Du erkennst Entitäten für die lokale Suchmaschinenoptimierung eines deutschen Unternehmens. '
			. 'Entitäten sind Dinge mit eigener Identität: Unternehmen, Marken, Orte, Einzugsgebiete, Leistungen, Produkte, Personen, Wettbewerber, Fachbegriffe. '
			. 'Keine allgemeinen Wörter, keine Adjektive, keine Satzteile. '
			. 'Jede Fundstelle ist ein wörtliches Zitat aus dem Text (5 bis 25 Wörter), unverändert, ohne Auslassungen. '
			. 'Wenn ein Name mehrdeutig ist (z. B. ein Ort, den es mehrfach gibt), trage ihn zusätzlich unter ambiguities ein. '
			. 'Erfinde nichts. Antworte auf Deutsch.';
		$user     = "Titel: {$title}\n\n"
			. ( array() !== $known ? 'Bekannte Entitäten (gleiche Schreibweise verwenden, wenn gemeint): ' . implode( ', ', array_slice( $known, 0, 80 ) ) . "\n\n" : '' )
			. "Text:\n<<<\n{$excerpt}\n>>>";
		$response = $this->ai->structured( new AiRequest( 'entity_extraction', $system, $user, self::schema(), 'entities', false, 4000, 120 ), $run_id );

		$verified = self::verify( $response->data, $title . "\n" . $text );
		return $verified + array( 'cost' => $response->cost );
	}

	/**
	 * Prüft die Vorschläge gegen den Text.
	 *
	 * @param array<mixed> $data Antwort der KI.
	 * @param string       $text Text.
	 * @return array{entities: array<int, array{type: string, name: string, aliases: string[], description: string, confidence: float, evidence: array<int, array{excerpt: string, location: string}>}>, rejected: array<int, array{name: string, reason: string}>, ambiguities: array<int, array{name: string, reason: string}>}
	 */
	public static function verify( array $data, string $text ): array {
		$haystack = self::flatten( $text );
		$entities = array();
		$rejected = array();
		$seen     = array();

		foreach ( (array) ( $data['entities'] ?? array() ) as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$name = trim( (string) ( $entity['name'] ?? '' ) );
			$type = (string) ( $entity['type'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$rejected[] = array(
					'name'   => $name,
					'reason' => 'unbekannter Typ ' . $type,
				);
				continue;
			}
			$key = $type . '|' . KeywordNormalizer::normalize( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$evidence = array();
			foreach ( (array) ( $entity['evidence'] ?? array() ) as $item ) {
				$quote = trim( (string) ( is_array( $item ) ? ( $item['excerpt'] ?? '' ) : '' ) );
				if ( '' !== $quote && str_contains( $haystack, self::flatten( $quote ) ) ) {
					$evidence[] = array(
						'excerpt'  => mb_substr( $quote, 0, 500 ),
						'location' => 'heading' === ( $item['location'] ?? '' ) ? 'heading' : 'body',
					);
				}
			}
			// Ohne wörtliches Zitat genügt es, wenn der Name selbst im Text steht.
			if ( array() === $evidence && str_contains( $haystack, self::flatten( $name ) ) ) {
				$evidence[] = array(
					'excerpt'  => self::context( $text, $name ),
					'location' => 'body',
				);
			}
			if ( array() === $evidence ) {
				$rejected[] = array(
					'name'   => $name,
					'reason' => 'keine Fundstelle im Text',
				);
				continue;
			}
			$seen[ $key ] = true;
			$entities[]   = array(
				'type'        => $type,
				'name'        => $name,
				'aliases'     => array_values( array_filter( array_map( static fn( $a ): string => trim( (string) $a ), (array) ( $entity['aliases'] ?? array() ) ) ) ),
				'description' => trim( (string) ( $entity['description'] ?? '' ) ),
				'confidence'  => max( 0.0, min( 1.0, (float) ( $entity['confidence'] ?? 0.5 ) ) ),
				'evidence'    => array_slice( $evidence, 0, 5 ),
			);
		}

		$ambiguities = array();
		foreach ( (array) ( $data['ambiguities'] ?? array() ) as $item ) {
			if ( is_array( $item ) && '' !== trim( (string) ( $item['name'] ?? '' ) ) ) {
				$ambiguities[] = array(
					'name'   => trim( (string) $item['name'] ),
					'reason' => trim( (string) ( $item['reason'] ?? '' ) ),
				);
			}
		}
		return array(
			'entities'    => $entities,
			'rejected'    => $rejected,
			'ambiguities' => $ambiguities,
		);
	}

	/**
	 * JSON-Schema der Antwort (strikt: alle Felder Pflicht, keine Zusatzfelder).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$string = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'entities'    => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'type'        => array(
								'type' => 'string',
								'enum' => self::TYPES,
							),
							'name'        => $string,
							'aliases'     => array(
								'type'  => 'array',
								'items' => $string,
							),
							'description' => $string,
							'confidence'  => array( 'type' => 'number' ),
							'evidence'    => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'excerpt'  => $string,
										'location' => array(
											'type' => 'string',
											'enum' => array( 'heading', 'body' ),
										),
									),
									'required'             => array( 'excerpt', 'location' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'type', 'name', 'aliases', 'description', 'confidence', 'evidence' ),
						'additionalProperties' => false,
					),
				),
				'ambiguities' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'   => $string,
							'reason' => $string,
						),
						'required'             => array( 'name', 'reason' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'entities', 'ambiguities' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Text für den Vergleich: klein, Leerraum und typografische Zeichen vereinheitlicht.
	 *
	 * @param string $text Text.
	 */
	private static function flatten( string $text ): string {
		$text = str_replace( array( '„', '“', '”', '‚', '‘', '’', '–', '—', "\u{00A0}" ), array( '"', '"', '"', "'", "'", "'", '-', '-', ' ' ), $text );
		return KeywordNormalizer::normalize( $text );
	}

	/**
	 * Satzumgebung eines Namens als Fundstelle.
	 *
	 * @param string $text Text.
	 * @param string $name Name.
	 */
	private static function context( string $text, string $name ): string {
		$pos = mb_stripos( $text, $name );
		if ( false === $pos ) {
			return $name;
		}
		$start = max( 0, $pos - 80 );
		return trim( (string) preg_replace( '/\s+/u', ' ', mb_substr( $text, $start, mb_strlen( $name ) + 160 ) ) );
	}
}
