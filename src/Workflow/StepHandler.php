<?php
/**
 * Schnittstelle für einen Ablaufschritt.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Ein Schritt bekommt den Kontext des Ablaufs und liefert ein StepResult.
 *
 * Schritte müssen so geschrieben sein, dass eine Wiederholung nach einem Abbruch
 * keinen Schaden anrichtet (z. B. vor dem Anlegen eines Beitrags nach einem
 * vorhandenen Beitrag dieses Ablaufs suchen).
 */
interface StepHandler {

	/**
	 * Führt den Schritt aus.
	 *
	 * @param RunContext $context Ablauf, Schritt und Zwischenergebnisse.
	 */
	public function handle( RunContext $context ): StepResult;
}
