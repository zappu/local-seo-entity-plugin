<?php
/**
 * Priorität eines Themas (Spezifikation 6.4).
 *
 * @package Lsec
 */

namespace Lsec\Topics;

/**
 * Fünf Faktoren zwischen 0 und 1, gewichtet zu 0–100. Fehlt ein Faktor (null), zählt er nicht als 0:
 * Die übrigen Gewichte werden hochgerechnet, und das Thema erhält den Hinweis „eingeschränkte Datenbasis“.
 * Wettbewerb fließt nicht ein. Die Klasse kennt WordPress nicht.
 */
final class PriorityScorer {

	/**
	 * Standardgewichte in Prozent.
	 */
	public const WEIGHTS = array(
		'relevance' => 30,
		'demand'    => 25,
		'gap'       => 20,
		'local'     => 15,
		'season'    => 10,
	);

	/**
	 * Bezeichnungen für Bericht und Oberfläche.
	 */
	public const LABELS = array(
		'relevance' => 'Geschäftsrelevanz',
		'demand'    => 'Nachfragesignal',
		'gap'       => 'Inhaltslücke',
		'local'     => 'Lokaler Bezug',
		'season'    => 'Saisonalität',
	);

	/**
	 * Konstruktor.
	 *
	 * @param array<string, int|float> $weights Gewichte; fehlende Faktoren erhalten den Standard.
	 */
	public function __construct( private readonly array $weights = self::WEIGHTS ) {}

	/**
	 * Punktzahl mit Aufschlüsselung.
	 *
	 * @param array<string, float|null> $factors Faktor → Wert 0–1 oder null (fehlt).
	 * @return array{score: float, breakdown: array<string, array{value: float|null, weight: float, points: float|null}>, missing: string[], limited: bool}
	 */
	public function score( array $factors ): array {
		$available = 0.0;
		foreach ( self::WEIGHTS as $name => $default ) {
			if ( null !== ( $factors[ $name ] ?? null ) ) {
				$available += max( 0.0, (float) ( $this->weights[ $name ] ?? $default ) );
			}
		}
		$breakdown = array();
		$missing   = array();
		$score     = 0.0;
		foreach ( self::WEIGHTS as $name => $default ) {
			$weight = max( 0.0, (float) ( $this->weights[ $name ] ?? $default ) );
			$value  = $factors[ $name ] ?? null;
			if ( null === $value ) {
				$missing[]          = $name;
				$breakdown[ $name ] = array(
					'value'  => null,
					'weight' => $weight,
					'points' => null,
				);
				continue;
			}
			$value              = max( 0.0, min( 1.0, (float) $value ) );
			$points             = $available > 0 ? $value * $weight / $available * 100 : 0.0;
			$score             += $points;
			$breakdown[ $name ] = array(
				'value'  => round( $value, 3 ),
				'weight' => $weight,
				'points' => round( $points, 1 ),
			);
		}
		// Saisonalität fehlt fast immer (nur Quellen mit Monatsangabe); eingeschränkt ist die Datenbasis erst ohne Nachfrage.
		return array(
			'score'     => round( $score, 1 ),
			'breakdown' => $breakdown,
			'missing'   => $missing,
			'limited'   => array_intersect( $missing, array( 'relevance', 'demand', 'gap', 'local' ) ) !== array(),
		);
	}

	/**
	 * Nachfrage aus Suchvolumen oder Impressionen, logarithmisch auf das Maximum der Runde bezogen.
	 *
	 * @param int|null $value Summe des Themas.
	 * @param int      $max   Größte Summe aller Themen der Runde.
	 */
	public static function demand( ?int $value, int $max ): ?float {
		if ( null === $value || $max <= 0 ) {
			return null;
		}
		return log10( 1 + max( 0, $value ) ) / log10( 1 + $max );
	}
}
