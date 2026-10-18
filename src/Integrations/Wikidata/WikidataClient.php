<?php
/**
 * Lesender Zugriff auf Wikidata.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\Wikidata;

use Lsec\Infrastructure\Http\HttpTransport;
use Lsec\Integrations\AI\Json;
use Lsec\Integrations\Textfocus\ProviderCallLog;
use Lsec\Workflow\RetryableException;

/**
 * Suche (wbsearchentities) und Abruf (wbgetentities) mit Zwischenspeicher in lsec_provider_calls (Anbieter wikidata, 30 Tage).
 * Wikidata begrenzt Anfragen je Adresse; 429 und 5xx werden als wiederholbare Fehler gemeldet.
 */
final class WikidataClient {

	public const API      = 'https://www.wikidata.org/w/api.php';
	public const PROVIDER = 'wikidata';

	private const CACHE_DAYS = 30;

	/**
	 * Konstruktor.
	 *
	 * @param HttpTransport        $http       HTTP.
	 * @param ProviderCallLog|null $log        Zwischenspeicher oder null.
	 * @param string               $user_agent Kennung nach Wikimedia-Richtlinie (ohne persönliche Daten).
	 */
	public function __construct(
		private readonly HttpTransport $http,
		private readonly ?ProviderCallLog $log = null,
		private readonly string $user_agent = 'LocalSEOEntityPlugin/0.2 (WordPress plugin)'
	) {}

	/**
	 * Sucht Einträge nach Name.
	 *
	 * @param string $name  Name.
	 * @param string $lang  Sprache.
	 * @param int    $limit Höchstzahl (max. 50).
	 * @return array<int, array{id: string, label: string, description: string, match: string, match_lang: string}>
	 */
	public function search( string $name, string $lang = 'de', int $limit = 10 ): array {
		$data = $this->get(
			array(
				'action'   => 'wbsearchentities',
				'search'   => $name,
				'language' => $lang,
				'uselang'  => $lang,
				'type'     => 'item',
				'limit'    => min( 50, max( 1, $limit ) ),
			)
		);
		$out  = array();
		foreach ( (array) ( $data['search'] ?? array() ) as $hit ) {
			if ( ! is_array( $hit ) || ! isset( $hit['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'          => (string) $hit['id'],
				'label'       => (string) ( $hit['label'] ?? '' ),
				'description' => (string) ( $hit['description'] ?? '' ),
				'match'       => (string) ( $hit['match']['text'] ?? $hit['label'] ?? '' ),
				'match_lang'  => (string) ( $hit['match']['language'] ?? $lang ),
			);
		}
		return $out;
	}

	/**
	 * Lädt Einträge mit den für Orte nötigen Aussagen.
	 *
	 * @param string[] $ids  Q-IDs.
	 * @param string   $lang Sprache.
	 * @return array<string, array{id: string, label: string, description: string, instance_of: string[], country: string[], located_in: string[], coord: array{0: float, 1: float}|null}>
	 */
	public function entities( array $ids, string $lang = 'de' ): array {
		$ids = array_values( array_unique( array_filter( $ids, static fn( string $id ): bool => (bool) preg_match( '/^Q\d+$/', $id ) ) ) );
		$out = array();
		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			$data = $this->get(
				array(
					'action'    => 'wbgetentities',
					'ids'       => implode( '|', $chunk ),
					'props'     => 'claims|labels|descriptions',
					'languages' => $lang,
				)
			);
			foreach ( (array) ( $data['entities'] ?? array() ) as $id => $entity ) {
				if ( is_array( $entity ) && ! isset( $entity['missing'] ) && in_array( (string) $id, $chunk, true ) ) {
					$out[ (string) $id ] = self::simplify( (string) $id, $entity, $lang );
				}
			}
		}
		return $out;
	}

	/**
	 * Verdichtet einen Eintrag auf Typ (P31), Staat (P17), Lage (P131) und Koordinaten (P625).
	 *
	 * @param string               $id     Q-ID.
	 * @param array<string, mixed> $entity Eintrag aus wbgetentities.
	 * @param string               $lang   Sprache.
	 * @return array{id: string, label: string, description: string, instance_of: string[], country: string[], located_in: string[], coord: array{0: float, 1: float}|null}
	 */
	public static function simplify( string $id, array $entity, string $lang = 'de' ): array {
		$claims = is_array( $entity['claims'] ?? null ) ? $entity['claims'] : array();
		$items  = static function ( string $property ) use ( $claims ): array {
			$out = array();
			foreach ( (array) ( $claims[ $property ] ?? array() ) as $claim ) {
				if ( ! is_array( $claim ) || 'deprecated' === ( $claim['rank'] ?? '' ) ) {
					continue;
				}
				// Beendete Zugehörigkeiten (P582 Endzeitpunkt) zählen nicht, z. B. frühere Landkreise.
				if ( isset( $claim['qualifiers']['P582'] ) ) {
					continue;
				}
				$value = $claim['mainsnak']['datavalue']['value'] ?? null;
				if ( is_array( $value ) && isset( $value['id'] ) ) {
					$out[] = (string) $value['id'];
				}
			}
			return array_values( array_unique( $out ) );
		};
		$coord  = null;
		foreach ( (array) ( $claims['P625'] ?? array() ) as $claim ) {
			$value = is_array( $claim ) ? ( $claim['mainsnak']['datavalue']['value'] ?? null ) : null;
			if ( is_array( $value ) && isset( $value['latitude'], $value['longitude'] ) ) {
				$coord = array( (float) $value['latitude'], (float) $value['longitude'] );
				break;
			}
		}
		return array(
			'id'          => $id,
			'label'       => (string) ( $entity['labels'][ $lang ]['value'] ?? '' ),
			'description' => (string) ( $entity['descriptions'][ $lang ]['value'] ?? '' ),
			'instance_of' => $items( 'P31' ),
			'country'     => $items( 'P17' ),
			'located_in'  => $items( 'P131' ),
			'coord'       => $coord,
		);
	}

	/**
	 * GET mit Zwischenspeicher.
	 *
	 * @param array<string, string|int> $params Parameter.
	 * @return array<mixed>
	 * @throws RetryableException Bei Netzwerkfehler, 429 und 5xx.
	 * @throws \RuntimeException  Bei sonstigen Fehlern.
	 */
	private function get( array $params ): array {
		$params['format'] = 'json';
		ksort( $params );
		$url  = self::API . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$hash = hash( 'sha256', $url );

		if ( null !== $this->log ) {
			$hit = $this->log->cached( self::PROVIDER, $hash );
			if ( null !== $hit ) {
				$data = Json::decode( $hit['body'] );
				if ( null !== $data ) {
					return $data;
				}
			}
		}

		$response = $this->http->request(
			'GET',
			$url,
			array(
				'User-Agent' => $this->user_agent,
				'Accept'     => 'application/json',
			),
			null,
			20
		);
		$status   = $response['status'];
		$endpoint = (string) $params['action'];
		if ( 0 === $status || 429 === $status || $status >= 500 ) {
			$this->log?->record(
				array(
					'provider'     => self::PROVIDER,
					'endpoint'     => $endpoint,
					'request_hash' => $hash,
					'status'       => 429 === $status ? 'rate_limited' : 'error',
				)
			);
			throw new RetryableException( 429 === $status ? 'rate_limited' : ( 0 === $status ? 'network' : 'http_' . $status ), 'Wikidata: ' . ( 429 === $status ? 'zu viele Anfragen, später erneut versuchen' : 'HTTP ' . $status . ' ' . (string) $response['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$data = Json::decode( $response['body'] );
		if ( 200 !== $status || null === $data || isset( $data['error'] ) ) {
			$info = is_array( $data['error'] ?? null ) ? (string) ( $data['error']['info'] ?? '' ) : '';
			throw new \RuntimeException( 'Wikidata: HTTP ' . $status . ( '' !== $info ? ' – ' . $info : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$this->log?->record(
			array(
				'provider'      => self::PROVIDER,
				'endpoint'      => $endpoint,
				'request_hash'  => $hash,
				'status'        => 'ok',
				'credits_used'  => 0.0,
				'response_body' => $response['body'],
				'cached_until'  => gmdate( 'Y-m-d H:i:s', time() + self::CACHE_DAYS * 86400 ),
			)
		);
		return $data;
	}
}
