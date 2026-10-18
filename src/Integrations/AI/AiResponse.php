<?php
/**
 * KI-Antwort.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

/**
 * Antwort eines Anbieters in einheitlicher Form.
 */
final class AiResponse {

	/**
	 * Konstruktor.
	 *
	 * @param string                                        $provider      Anbieter.
	 * @param string                                        $model         Modell laut Antwort.
	 * @param string                                        $text          Antworttext.
	 * @param array<string, mixed>|null                     $data          Geprüftes JSON-Ergebnis, falls ein Schema verlangt war.
	 * @param array<int, array{url: string, title: string}> $sources       Quellen aus der Websuche.
	 * @param int|null                                      $input_tokens  Eingabe-Token.
	 * @param int|null                                      $output_tokens Ausgabe-Token.
	 * @param float|null                                    $cost          Kosten in US-Dollar, falls bekannt.
	 * @param int                                           $duration_ms   Dauer.
	 * @param string                                        $finish        Abschlussgrund, z. B. stop oder length.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $model,
		public readonly string $text,
		public readonly ?array $data,
		public readonly array $sources,
		public readonly ?int $input_tokens,
		public readonly ?int $output_tokens,
		public readonly ?float $cost,
		public readonly int $duration_ms,
		public readonly string $finish = 'stop'
	) {}

	/**
	 * Kopie mit geprüften Daten.
	 *
	 * @param array<string, mixed> $data Daten.
	 */
	public function with_data( array $data ): self {
		return new self( $this->provider, $this->model, $this->text, $data, $this->sources, $this->input_tokens, $this->output_tokens, $this->cost, $this->duration_ms, $this->finish );
	}
}
