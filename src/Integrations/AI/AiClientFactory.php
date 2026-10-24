<?php
/**
 * Erzeugt den konfigurierten KI-Adapter.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Core\Settings;
use Lsec\Infrastructure\Http\HttpTransport;
use Lsec\Infrastructure\Security\Secrets;

/**
 * Ein Anbieter und ein Hauptmodell gelten für alle Aufgaben. Ausnahme: Für den Rechercheschritt
 * mit Websuche kann ai_research_model ein eigenes Modell desselben Anbieters festlegen
 * (Nachtrag zu Anforderung 14 vom 18.10.2026).
 */
final class AiClientFactory {

	/**
	 * Konstruktor.
	 *
	 * @param Secrets       $secrets Schlüssel.
	 * @param HttpTransport $http    HTTP.
	 */
	public function __construct(
		private readonly Secrets $secrets,
		private readonly HttpTransport $http
	) {}

	/**
	 * Adapter nach Einstellungen oder ausdrücklicher Angabe.
	 *
	 * @param string|null $provider openrouter, anthropic oder openai; null = Einstellung.
	 * @param string|null $model    Modell; null = Einstellung.
	 * @throws AiException Wenn Schlüssel oder Modell fehlen.
	 */
	public function create( ?string $provider = null, ?string $model = null ): AiClient {
		$provider = $provider ?? (string) Settings::get( 'ai_provider' );
		$model    = $model ?? (string) Settings::get( 'ai_model' );

		if ( '' === $model ) {
			throw new AiException( 'not_configured', 'Kein KI-Modell eingestellt. Beispiel: wp lsec settings set ai_model <modell>' );
		}

		$key = $this->secrets->get( $provider . '_api_key' );
		if ( null === $key || '' === $key ) {
			throw new AiException( 'not_configured', 'Kein API-Schlüssel für ' . $provider . '. Konstante ' . Secrets::constant_name( $provider . '_api_key' ) . ' in wp-config.php setzen.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$endpoint = (string) Settings::get( 'openai_endpoint' );
		return match ( $provider ) {
			'openrouter' => new OpenRouterClient( $this->http, $key, $model, function_exists( 'home_url' ) ? home_url() : '' ),
			'anthropic'  => new AnthropicClient( $this->http, $key, $model ),
			'openai'     => new OpenAiClient( $this->http, $key, $model, '' !== $endpoint ? $endpoint : OpenAiClient::ENDPOINT ),
			default      => throw new AiException( 'not_configured', 'Unbekannter Anbieter: ' . $provider ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
	}

	/**
	 * Bildmodell über OpenRouter (0.10.0). Bilder erzeugt das Plugin nur über OpenRouter, auch wenn für Texte ein
	 * anderer Anbieter eingestellt ist; dann braucht es zusätzlich den OpenRouter-Schlüssel.
	 *
	 * @param string $model Bildmodell.
	 * @throws AiException Ohne Schlüssel oder Modell.
	 */
	public function image_client( string $model ): OpenRouterImageClient {
		if ( '' === trim( $model ) ) {
			throw new AiException( 'not_configured', 'Kein Bildmodell eingestellt (ai_image_model).' );
		}
		$key = $this->secrets->get( 'openrouter_api_key' );
		if ( null === $key || '' === $key ) {
			throw new AiException( 'not_configured', 'Bilder erzeugt das Plugin über OpenRouter. Konstante ' . Secrets::constant_name( 'openrouter_api_key' ) . ' in wp-config.php setzen.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return new OpenRouterImageClient( $this->http, $key, trim( $model ), function_exists( 'home_url' ) ? home_url() : '' );
	}

	/**
	 * Dienst mit Protokoll, Preistabelle, Budget und gegebenenfalls Recherchemodell.
	 *
	 * Wird ein Modell ausdrücklich angegeben (Prüfbefehl mit --model), gilt es auch für die Recherche.
	 *
	 * @param string|null $provider       Anbieter.
	 * @param string|null $model          Modell.
	 * @param string|null $research_model Recherchemodell statt der Einstellung (Vergleich in Stufe D).
	 */
	public function service( ?string $provider = null, ?string $model = null, ?string $research_model = null ): AiService {
		$prices   = Settings::get( 'ai_prices' );
		$client   = $this->create( $provider, $model );
		$research = null;
		$name     = $research_model ?? (string) Settings::get( 'ai_research_model' );
		if ( null === $model && '' !== $name && $name !== $client->model() ) {
			$research = $this->create( $client->provider(), $name );
		}
		return new AiService(
			$client,
			new AiCallRepository(),
			new PriceTable( is_array( $prices ) ? $prices : array() ),
			self::budget(),
			$research,
			max( 1, min( 10, (int) Settings::get( 'ai_web_max_results' ) ) ),
			AiService::engine( (string) Settings::get( 'ai_web_engine' ) )
		);
	}

	/**
	 * Budget nach Einstellungen, Monat nach Zeitzone der Website.
	 */
	public static function budget(): AiBudget {
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		return new AiBudget(
			new AiCallRepository(),
			(float) Settings::get( 'ai_monthly_budget' ),
			(float) Settings::get( 'ai_run_budget' ),
			AiBudget::month_start( $zone, time() )
		);
	}
}
