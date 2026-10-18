<?php
/**
 * HTTP-Schnittstelle.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Http;

/**
 * Kapselt HTTP-Aufrufe, damit Adapter ohne Netzwerk testbar sind.
 */
interface HttpTransport {

	/**
	 * Sendet eine Anfrage.
	 *
	 * @param string                $method  GET oder POST.
	 * @param string                $url     Adresse.
	 * @param array<string, string> $headers Kopfzeilen.
	 * @param string|null           $body    Rumpf, z. B. JSON.
	 * @param int                   $timeout Zeitlimit in Sekunden.
	 * @return array{status: int, body: string, error: string|null} Status 0 bei Netzwerkfehler.
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): array;
}
