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
 * Ein Anbieter und ein Modell gelten für alle Aufgaben (Anforderung „ein festes KI-Modell“).
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

		return match ( $provider ) {
			'openrouter' => new OpenRouterClient( $this->http, $key, $model, function_exists( 'home_url' ) ? home_url() : '' ),
			'anthropic'  => new AnthropicClient( $this->http, $key, $model ),
			'openai'     => new OpenAiClient( $this->http, $key, $model, '' !== (string) Settings::get( 'openai_endpoint' ) ? (string) Settings::get( 'openai_endpoint' ) : OpenAiClient::ENDPOINT ),
			default      => throw new AiException( 'not_configured', 'Unbekannter Anbieter: ' . $provider ),
		};
	}

	/**
	 * Dienst mit Protokoll und Preistabelle.
	 *
	 * @param string|null $provider Anbieter.
	 * @param string|null $model    Modell.
	 */
	public function service( ?string $provider = null, ?string $model = null ): AiService {
		$prices = Settings::get( 'ai_prices' );
		return new AiService( $this->create( $provider, $model ), new AiCallRepository(), new PriceTable( is_array( $prices ) ? $prices : array() ) );
	}
}
