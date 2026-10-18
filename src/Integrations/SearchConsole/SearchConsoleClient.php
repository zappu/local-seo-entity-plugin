<?php
/**
 * Zugriff auf die Search Console API.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\SearchConsole;

use Lsec\Infrastructure\Http\HttpTransport;
use Lsec\Integrations\AI\Json;
use Lsec\Research\Keywords\KeywordNormalizer;
use Lsec\Workflow\RetryableException;

/**
 * Properties und Suchanalyse (Prüfpunkt M9). Nur Lesezugriff.
 */
final class SearchConsoleClient {

	public const API = 'https://searchconsole.googleapis.com/webmasters/v3/';

	/**
	 * Zeilen je Seite der Abfrage (Höchstwert der API).
	 */
	private const ROW_LIMIT = 25000;

	/**
	 * Konstruktor.
	 *
	 * @param ServiceAccount $account Dienstkonto.
	 * @param HttpTransport  $http    HTTP.
	 */
	public function __construct( private readonly ServiceAccount $account, private readonly HttpTransport $http ) {}

	/**
	 * Properties, auf die das Dienstkonto Zugriff hat.
	 *
	 * @return array<int, array{url: string, permission: string}>
	 */
	public function sites(): array {
		$data = $this->request( 'GET', 'sites', null );
		$out  = array();
		foreach ( (array) ( $data['siteEntry'] ?? array() ) as $site ) {
			if ( is_array( $site ) && isset( $site['siteUrl'] ) ) {
				$out[] = array(
					'url'        => (string) $site['siteUrl'],
					'permission' => (string) ( $site['permissionLevel'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Suchanfragen mit Seite für einen Zeitraum und ein Land.
	 *
	 * @param string $property Property, z. B. sc-domain:example.de oder https://example.de/.
	 * @param string $start    Startdatum Y-m-d.
	 * @param string $end      Enddatum Y-m-d.
	 * @param string $country  Land als ISO-3166-1-alpha-3 (deu, aut, che) oder leer für alle.
	 * @param int    $max_rows Obergrenze.
	 * @return array<int, array{query: string, page: string, clicks: int, impressions: int, ctr: float, position: float}>
	 */
	public function search_analytics( string $property, string $start, string $end, string $country = 'deu', int $max_rows = 25000 ): array {
		$rows      = array();
		$start_row = 0;
		do {
			$body = array(
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => array( 'query', 'page' ),
				'rowLimit'   => min( self::ROW_LIMIT, $max_rows - count( $rows ) ),
				'startRow'   => $start_row,
				'dataState'  => 'final',
			);
			if ( '' !== $country ) {
				$body['dimensionFilterGroups'] = array(
					array(
						'filters' => array(
							array(
								'dimension'  => 'country',
								'operator'   => 'equals',
								'expression' => strtolower( $country ),
							),
						),
					),
				);
			}
			$data = $this->request( 'POST', 'sites/' . rawurlencode( $property ) . '/searchAnalytics/query', $body );
			$page = (array) ( $data['rows'] ?? array() );
			foreach ( $page as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['keys'][0] ) ) {
					continue;
				}
				$rows[] = array(
					'query'       => (string) $row['keys'][0],
					'page'        => (string) ( $row['keys'][1] ?? '' ),
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'ctr'         => (float) ( $row['ctr'] ?? 0 ),
					'position'    => (float) ( $row['position'] ?? 0 ),
				);
			}
			$received   = count( $page );
			$total      = count( $rows );
			$start_row += $received;
		} while ( self::ROW_LIMIT === $received && $total < $max_rows );
		return $rows;
	}

	/**
	 * Fasst Zeilen je Suchanfrage zusammen: Summen, CTR, nach Impressionen gewichtete Position und die Seite mit den meisten Klicks.
	 *
	 * @param array<int, array{query: string, page: string, clicks: int, impressions: int, ctr: float, position: float}> $rows Zeilen.
	 * @return array<string, array{keyword: string, clicks: int, impressions: int, ctr: float, position: float, page: string, pages: int}>
	 */
	public static function aggregate( array $rows ): array {
		$out  = array();
		$best = array();
		foreach ( $rows as $row ) {
			$key = KeywordNormalizer::normalize( $row['query'] );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ]  = array(
					'keyword'     => $row['query'],
					'clicks'      => 0,
					'impressions' => 0,
					'ctr'         => 0.0,
					'position'    => 0.0,
					'page'        => '',
					'pages'       => 0,
				);
				$best[ $key ] = array( -1, -1 );
			}
			$out[ $key ]['clicks']      += $row['clicks'];
			$out[ $key ]['impressions'] += $row['impressions'];
			$out[ $key ]['position']    += $row['position'] * $row['impressions'];
			++$out[ $key ]['pages'];
			if ( array( $row['clicks'], $row['impressions'] ) > $best[ $key ] ) {
				$best[ $key ]        = array( $row['clicks'], $row['impressions'] );
				$out[ $key ]['page'] = $row['page'];
			}
		}
		foreach ( $out as $key => $entry ) {
			$impressions             = max( 1, $entry['impressions'] );
			$out[ $key ]['ctr']      = round( $entry['clicks'] / $impressions, 4 );
			$out[ $key ]['position'] = round( $entry['position'] / $impressions, 2 );
		}
		uasort( $out, static fn( array $a, array $b ): int => array( $b['clicks'], $b['impressions'] ) <=> array( $a['clicks'], $a['impressions'] ) );
		return $out;
	}

	/**
	 * Zeitraum der letzten n Tage mit endgültigen Daten. Die Search Console liefert Daten mit etwa drei Tagen Verzögerung.
	 *
	 * @param int $days Tage.
	 * @param int $now  Zeitpunkt (Unix).
	 * @return array{start: string, end: string}
	 */
	public static function period( int $days, int $now ): array {
		$end   = $now - 3 * 86400;
		$start = $end - ( max( 1, $days ) - 1 ) * 86400;
		return array(
			'start' => gmdate( 'Y-m-d', $start ),
			'end'   => gmdate( 'Y-m-d', $end ),
		);
	}

	/**
	 * Aufruf mit Zugriffstoken.
	 *
	 * @param string            $method Methode.
	 * @param string            $path   Pfad unter API.
	 * @param array<mixed>|null $body   JSON-Rumpf.
	 * @return array<mixed>
	 * @throws RetryableException Bei 429, 5xx und Netzwerkfehlern.
	 * @throws SearchConsoleException Bei fehlendem Zugriff oder ungültiger Anfrage.
	 */
	private function request( string $method, string $path, ?array $body ): array {
		$response = $this->http->request(
			$method,
			self::API . $path,
			array(
				'Authorization' => 'Bearer ' . $this->account->access_token(),
				'Content-Type'  => 'application/json',
			),
			null === $body ? null : Json::encode( $body ),
			60
		);
		$status   = $response['status'];
		$data     = json_decode( $response['body'], true );
		$detail   = is_array( $data ) && isset( $data['error']['message'] ) ? mb_substr( (string) $data['error']['message'], 0, 300 ) : '';
		if ( 0 === $status || 429 === $status || $status >= 500 ) {
			throw new RetryableException( 0 === $status ? 'network' : 'http_' . $status, 'Search Console: ' . ( 0 === $status ? 'Netzwerkfehler ' . (string) $response['error'] : 'HTTP ' . $status . ( '' !== $detail ? ' – ' . $detail : '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 401 === $status || 403 === $status ) {
			throw new SearchConsoleException( 'forbidden', sprintf( 'Kein Zugriff (HTTP %d). Das Dienstkonto %s muss in der Search Console unter Einstellungen → Nutzer und Berechtigungen eingetragen sein.%s', $status, $this->account->client_email, '' !== $detail ? ' Google: ' . $detail : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 200 !== $status || ! is_array( $data ) ) {
			throw new SearchConsoleException( 'api_error', 'Search Console: HTTP ' . $status . ( '' !== $detail ? ' – ' . $detail : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $data;
	}
}
