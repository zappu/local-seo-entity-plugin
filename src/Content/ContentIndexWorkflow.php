<?php
/**
 * Ablauf content_index.
 *
 * @package Lsec
 */

namespace Lsec\Content;

use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\EntityExtractionWorkflow;
use Lsec\Workflow\RunContext;
use Lsec\Workflow\StepHandler;
use Lsec\Workflow\StepResult;
use Lsec\Workflow\WorkflowDefinition;

/**
 * Spezifikation 5 und 7.3: liest veröffentlichte Beiträge, Seiten, Produkte und Kategorieseiten in Stapeln zu 20.
 * Für neue oder geänderte Inhalte startet er die Entity-Erkennung, wenn sie eingeschaltet ist.
 */
final class ContentIndexWorkflow implements StepHandler {

	public const TYPE = 'content_index';

	/**
	 * Kürzere Texte lohnen keine Entity-Erkennung (Wörter).
	 */
	public const MIN_WORDS = 50;

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Definition mit einem Schritt, der stapelweise weiterläuft.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function definition( Plugin $plugin ): WorkflowDefinition {
		return new WorkflowDefinition( self::TYPE, array( 'index' => new self( $plugin ) ) );
	}

	/**
	 * Startet einen Indexlauf, wenn keiner läuft.
	 *
	 * @param Plugin            $plugin Plugin.
	 * @param bool              $all    Alle Inhalte neu lesen, nicht nur neue und geänderte.
	 * @param array<int|string> $ids    Nur diese Inhalte (12 oder post:12, term:34; z. B. im Prüfskript); zählt nicht als vollständiger Lauf.
	 * @return array{run_id: int, created: bool}|null Null, wenn schon ein Lauf aktiv ist.
	 */
	public static function start( Plugin $plugin, bool $all = false, array $ids = array() ): ?array {
		if ( $plugin->runs->has_active( self::TYPE ) ) {
			return null;
		}
		return $plugin->engine->start(
			self::TYPE,
			null,
			null,
			array(
				'all' => $all,
				'ids' => array_values( array_map( static fn( $ref ): string => IndexItem::normalize_ref( $ref ), $ids ) ),
			),
			array( 'at' => microtime( true ) )
		);
	}

	/**
	 * Hält den Index an: bricht den Indexlauf und alle offenen Entity-Erkennungen ab. Bereits erkannte
	 * Entitäten bleiben; der nächste Indexlauf startet fehlende Erkennungen neu.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return int Anzahl abgebrochener Abläufe.
	 */
	public static function stop( Plugin $plugin ): int {
		$count = $plugin->runs->cancel_open( array( self::TYPE, EntityExtractionWorkflow::TYPE ) );
		$plugin->logger->audit( 'Inhaltsindex angehalten', array( 'count' => $count ) );
		return $count;
	}

	/**
	 * Für den Cronjob: höchstens einmal je Stunde nachsehen, ob Inhalte neu oder geändert sind, und dann
	 * einen Lauf starten. Erst nachdem der Administrator den Index einmal selbst gestartet hat, damit die
	 * erste Erkennung (mit KI-Kosten) nicht unbemerkt beginnt.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return int|null Gestarteter Ablauf.
	 */
	public static function maybe_start_scheduled( Plugin $plugin ): ?int {
		if ( 0 === (int) get_option( ContentIndex::LAST_RUN, 0 ) ) {
			return null;
		}
		$checked = (int) get_option( 'lsec_index_checked', 0 );
		if ( time() - $checked < HOUR_IN_SECONDS ) {
			return null;
		}
		update_option( 'lsec_index_checked', time(), false );
		if ( array() === ContentIndex::pending_ids() ) {
			return null;
		}
		$run = self::start( $plugin );
		return null === $run ? null : $run['run_id'];
	}

	/**
	 * Ein Stapel.
	 *
	 * @param RunContext $context Kontext.
	 */
	public function handle( RunContext $context ): StepResult {
		$state = $context->state;
		if ( ! isset( $state['queue'] ) ) {
			$ids   = array_values( array_map( 'strval', (array) ( $context->input['ids'] ?? array() ) ) );
			$queue = array() !== $ids ? $ids : ContentIndex::pending_ids( ! empty( $context->input['all'] ) );
			$state = array(
				'queue'       => $queue,
				'total'       => count( $queue ),
				'indexed'     => 0,
				'changed'     => 0,
				'extractions' => 0,
			);
		}
		$auto  = (bool) Settings::get( 'entity_auto_extract' );
		$kinds = ContentIndex::extract_kinds();
		$batch = array_splice( $state['queue'], 0, ContentIndex::BATCH );
		foreach ( $batch as $ref ) {
			$item = IndexItem::from_ref( $ref );
			if ( null === $item || ! $item->is_published() ) {
				continue;
			}
			$result = ContentIndex::index_item( $item );
			++$state['indexed'];
			if ( $result['changed'] ) {
				++$state['changed'];
			}
			// Erkennung, wenn sie für diesen Inhalt noch nicht gelaufen ist, auch wenn er schon im Index stand
			// (z. B. weil die automatische Erkennung beim ersten Lauf ausgeschaltet war). Nur für die Arten aus
			// entity_extract_types; Blogbeiträge sind standardmäßig nicht dabei.
			$extracted = (string) $item->meta( ContentIndex::EXTRACTED );
			if ( $auto && in_array( $item->kind, $kinds, true ) && $result['words'] >= self::MIN_WORDS && $extracted !== $result['hash'] ) {
				$run = EntityExtractionWorkflow::start_item( $this->plugin, $item, $result['hash'] );
				if ( $run['created'] ) {
					++$state['extractions'];
				}
			}
		}
		if ( array() !== $state['queue'] ) {
			return StepResult::more( $state );
		}
		if ( array() === (array) ( $context->input['ids'] ?? array() ) ) {
			update_option( ContentIndex::LAST_RUN, time(), false );
		}
		unset( $state['queue'] );
		return StepResult::done( $state );
	}
}
