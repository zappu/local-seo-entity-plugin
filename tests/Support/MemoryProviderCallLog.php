<?php
/**
 * Protokoll im Speicher für Tests.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Support;

use Lsec\Integrations\Textfocus\ProviderCallLog;

/**
 * Bildet lsec_provider_calls nach.
 */
final class MemoryProviderCallLog implements ProviderCallLog {

	/**
	 * Einträge.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = array();

	/**
	 * Aktuelle Zeit für Einträge.
	 *
	 * @var int
	 */
	public int $now = 1760000000;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $provider     Anbieter.
	 * @param string $request_hash Fingerabdruck.
	 * @return array{id: int, body: string}|null
	 */
	public function cached( string $provider, string $request_hash ): ?array {
		foreach ( array_reverse( $this->calls, true ) as $id => $call ) {
			if ( $call['provider'] === $provider && $call['request_hash'] === $request_hash && 'ok' === $call['status'] && null !== ( $call['response_body'] ?? null ) && strtotime( (string) $call['cached_until'] . ' UTC' ) > $this->now ) {
				return array(
					'id'   => $id,
					'body' => (string) $call['response_body'],
				);
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $call Aufruf.
	 */
	public function record( array $call ): int {
		$call['created']    = $this->now;
		$id                 = count( $this->calls ) + 1;
		$this->calls[ $id ] = $call;
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $provider Anbieter.
	 */
	public function credits_today( string $provider ): float {
		$sum = 0.0;
		foreach ( $this->calls as $call ) {
			if ( $call['provider'] === $provider && 'ok' === $call['status'] ) {
				$sum += (float) ( $call['credits_used'] ?? 1 );
			}
		}
		return $sum;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $provider Anbieter.
	 */
	public function last_call_at( string $provider ): ?int {
		$last = null;
		foreach ( $this->calls as $call ) {
			if ( $call['provider'] === $provider && in_array( $call['status'], array( 'ok', 'error', 'rate_limited' ), true ) ) {
				$last = (int) $call['created'];
			}
		}
		return $last;
	}

	/**
	 * Status aller Einträge.
	 *
	 * @return string[]
	 */
	public function statuses(): array {
		return array_values( array_map( static fn( array $c ): string => (string) $c['status'], $this->calls ) );
	}
}
