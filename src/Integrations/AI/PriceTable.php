<?php
/**
 * Preistabelle für die Kostenberechnung.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

/**
 * Preise in US-Dollar je 1 Million Token, gepflegt in den Einstellungen, weil sie sich ändern.
 * OpenRouter liefert die Kosten selbst; dort wird die Tabelle nicht gebraucht.
 */
final class PriceTable {

	/**
	 * Konstruktor.
	 *
	 * @param array<string, array<string, array{in: float|int, out: float|int}>> $prices Anbieter => Modell => Preise.
	 */
	public function __construct( private readonly array $prices = array() ) {}

	/**
	 * Kosten einer Antwort, falls ein Preis hinterlegt ist.
	 *
	 * @param AiResponse $response Antwort.
	 */
	public function cost_of( AiResponse $response ): ?float {
		if ( null !== $response->cost ) {
			return $response->cost;
		}
		$price = $this->prices[ $response->provider ][ $response->model ] ?? null;
		if ( null === $price || null === $response->input_tokens || null === $response->output_tokens ) {
			return null;
		}
		return round( ( $response->input_tokens * (float) $price['in'] + $response->output_tokens * (float) $price['out'] ) / 1000000, 6 );
	}
}
