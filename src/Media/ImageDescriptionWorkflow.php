<?php
/**
 * Ablauf image_description: neue Bilder der Mediathek beschreiben.
 *
 * @package Lsec
 */

namespace Lsec\Media;

use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Workflow\RunContext;
use Lsec\Workflow\StepHandler;
use Lsec\Workflow\StepResult;
use Lsec\Workflow\WorkflowDefinition;

/**
 * Nachtrag 24.10.2026 (0.10.0): Der Cronjob startet höchstens alle 15 Minuten einen Lauf, wenn Bilder ohne
 * Beschreibung da sind und Beitragsbilder eingeschaltet sind. Je Durchgang zehn Bilder, je Lauf höchstens 500.
 * Kosten mit dem Hauptmodell openai/gpt-6-luna: etwa 0,0002 USD je Bild.
 */
final class ImageDescriptionWorkflow implements StepHandler {

	public const TYPE = 'image_description';

	/**
	 * Höchstzahl Bilder je Lauf.
	 */
	public const MAX = 500;

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Definition.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function definition( Plugin $plugin ): WorkflowDefinition {
		return new WorkflowDefinition( self::TYPE, array( 'describe' => new self( $plugin ) ) );
	}

	/**
	 * Startet einen Lauf, wenn keiner läuft und Bilder offen sind.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param int[]  $ids    Nur diese Bilder (Befehl, Prüfskript).
	 * @return array{run_id: int, created: bool}|null
	 */
	public static function start( Plugin $plugin, array $ids = array() ): ?array {
		if ( $plugin->runs->has_active( self::TYPE ) ) {
			return null;
		}
		return $plugin->engine->start( self::TYPE, null, null, array( 'ids' => array_values( array_map( 'intval', $ids ) ) ), array( 'at' => microtime( true ) ) );
	}

	/**
	 * Für den Cronjob.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return int|null Gestarteter Ablauf.
	 */
	public static function maybe_start_scheduled( Plugin $plugin ): ?int {
		if ( 'off' === (string) Settings::get( 'image_mode' ) || '' === (string) Settings::get( 'ai_model' ) ) {
			return null;
		}
		$checked = (int) get_option( 'lsec_images_checked', 0 );
		if ( time() - $checked < 15 * MINUTE_IN_SECONDS ) {
			return null;
		}
		update_option( 'lsec_images_checked', time(), false );
		if ( array() === ImageLibrary::pending_ids( 1 ) ) {
			return null;
		}
		$run = self::start( $plugin );
		return null === $run ? null : $run['run_id'];
	}

	/**
	 * KI-Dienst mit dem Modell für Bilder (ai_vision_model, sonst Hauptmodell).
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function vision( Plugin $plugin ): \Lsec\Integrations\AI\AiService {
		$model = trim( (string) Settings::get( 'ai_vision_model' ) );
		return $plugin->ai->service( null, '' !== $model ? $model : null );
	}

	/**
	 * Ein Durchgang.
	 *
	 * @param RunContext $context Kontext.
	 */
	public function handle( RunContext $context ): StepResult {
		$state = $context->state;
		if ( ! isset( $state['queue'] ) ) {
			$ids   = array_values( array_map( 'intval', (array) ( $context->input['ids'] ?? array() ) ) );
			$state = array(
				'queue'     => array() !== $ids ? $ids : ImageLibrary::pending_ids( self::MAX ),
				'described' => 0,
				'small'     => 0,
				'errors'    => 0,
			);
		}
		$batch    = array_splice( $state['queue'], 0, ImageLibrary::BATCH );
		$plugin   = $this->plugin;
		$ai       = ImageLibrary::lazy( static fn() => self::vision( $plugin ) );
		$business = ImageLibrary::business();
		foreach ( $batch as $id ) {
			$result = ImageLibrary::describe( (int) $id, $ai, $business, $context->run_id );
			$key    = array(
				'described' => 'described',
				'small'     => 'small',
			)[ $result['status'] ] ?? 'errors';
			++$state[ $key ];
		}
		if ( array() !== $state['queue'] ) {
			return StepResult::more( $state );
		}
		unset( $state['queue'] );
		return StepResult::done( $state );
	}
}
