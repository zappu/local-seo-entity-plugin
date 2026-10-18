<?php
/**
 * KI-Attrappe für den Selbsttest.
 *
 * @package Lsec
 */

namespace Lsec\Workflow\Selftest;

use Lsec\Integrations\AI\AiClient;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiResponse;

/**
 * Antwortet ohne Netz mit festen Kosten. Damit lässt sich das KI-Budget prüfen,
 * ohne einen Anbieter aufzurufen.
 */
final class SelftestAiClient implements AiClient {

	public const PROVIDER = 'selftest';

	/**
	 * Konstruktor.
	 *
	 * @param float $cost Kosten je Aufruf in USD.
	 */
	public function __construct( private readonly float $cost ) {}

	/**
	 * {@inheritDoc}
	 */
	public function provider(): string {
		return self::PROVIDER;
	}

	/**
	 * {@inheritDoc}
	 */
	public function model(): string {
		return 'selftest-stub';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AiRequest $request Anfrage.
	 */
	public function send( AiRequest $request ): AiResponse {
		return new AiResponse( self::PROVIDER, 'selftest-stub', 'ok', null, array(), 10, 2, $this->cost, 1 );
	}
}
