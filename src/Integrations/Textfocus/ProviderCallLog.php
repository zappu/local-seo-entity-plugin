<?php
/**
 * Protokoll und Zwischenspeicher für externe Datenanbieter.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\Textfocus;

/**
 * Schnittstelle zu lsec_provider_calls, damit Budget, Abstand und Zwischenspeicher ohne Datenbank testbar sind.
 */
interface ProviderCallLog {

	/**
	 * Gültiger Eintrag im Zwischenspeicher.
	 *
	 * @param string $provider     Anbieter.
	 * @param string $request_hash Fingerabdruck der Anfrage.
	 * @return array{id: int, body: string}|null
	 */
	public function cached( string $provider, string $request_hash ): ?array;

	/**
	 * Schreibt einen Aufruf.
	 *
	 * @param array{provider: string, endpoint: string, request_hash: string, status: string, credits_used?: float|null, credits_remaining?: float|null, response_body?: string|null, cached_until?: string|null, response_ref?: string|null, run_id?: int|null} $call Aufruf.
	 * @return int ID.
	 */
	public function record( array $call ): int;

	/**
	 * Heute verbrauchte Credits (Tag in der Zeitzone der Website).
	 *
	 * @param string $provider Anbieter.
	 */
	public function credits_today( string $provider ): float;

	/**
	 * Zeitpunkt (Unix) des letzten echten Aufrufs beim Anbieter, ohne Treffer aus dem Zwischenspeicher.
	 *
	 * @param string $provider Anbieter.
	 */
	public function last_call_at( string $provider ): ?int;
}
