<?php
/**
 * Schnittstelle zu einem KI-Anbieter.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

/**
 * Ein Adapter je Anbieter (OpenRouter, Anthropic, OpenAI).
 * Adapter liefern Rohtext und Quellen; die Prüfung gegen das Schema macht AiService.
 */
interface AiClient {

	/**
	 * Name des Anbieters, z. B. openrouter.
	 */
	public function provider(): string;

	/**
	 * Modell, mit dem der Adapter arbeitet.
	 */
	public function model(): string;

	/**
	 * Führt die Anfrage aus.
	 *
	 * @param AiRequest $request Anfrage.
	 * @throws AiException                            Bei endgültigen Fehlern.
	 * @throws \Lsec\Workflow\RetryableException Bei vorübergehenden Fehlern.
	 */
	public function send( AiRequest $request ): AiResponse;
}
