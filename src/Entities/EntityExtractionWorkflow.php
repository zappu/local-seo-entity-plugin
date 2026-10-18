<?php
/**
 * Ablauf entity_extraction.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Content\ContentReader;
use Lsec\Core\Plugin;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Integrations\AI\AiClientFactory;
use Lsec\Integrations\AI\AiService;
use Lsec\Integrations\AI\PriceTable;
use Lsec\Integrations\Wikidata\WikidataClient;
use Lsec\Workflow\RunContext;
use Lsec\Workflow\StepHandler;
use Lsec\Workflow\StepResult;
use Lsec\Workflow\Selftest\SelftestAiClient;
use Lsec\Workflow\WorkflowDefinition;

/**
 * Spezifikation 7.3: extract (KI) → match_places (Wikidata gegen das Servicegebiet) → save.
 * Jeder Schritt speichert sein Ergebnis; ein Abbruch wiederholt keine bezahlte Erkennung.
 */
final class EntityExtractionWorkflow {

	public const TYPE = 'entity_extraction';

	/**
	 * Definition.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function definition( Plugin $plugin ): WorkflowDefinition {
		return new WorkflowDefinition(
			self::TYPE,
			array(
				'extract'      => new class( $plugin ) implements StepHandler {
					/**
					 * Konstruktor.
					 *
					 * @param Plugin $plugin Plugin.
					 */
					public function __construct( private readonly Plugin $plugin ) {}

					/**
					 * KI-Erkennung mit Prüfung der Fundstellen.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						$post = get_post( (int) ( $context->input['post_id'] ?? 0 ) );
						if ( ! $post instanceof \WP_Post ) {
							return StepResult::fail( 'not_found', 'Beitrag nicht gefunden.' );
						}
						$content = ContentReader::read( $post );
						$title   = ContentReader::title( $post );
						$ai      = EntityExtractionWorkflow::ai( $this->plugin, $context->input );
						$result  = ( new EntityExtractor( $ai ) )->extract( $title, $content['text'], ( new EntityRepository() )->known_names(), $context->run_id );
						return StepResult::done(
							$result + array(
								'title' => $title,
								'words' => $content['words'],
							)
						);
					}
				},
				'match_places' => new class( $plugin ) implements StepHandler {
					/**
					 * Konstruktor.
					 *
					 * @param Plugin $plugin Plugin.
					 */
					public function __construct( private readonly Plugin $plugin ) {}

					/**
					 * Ortsabgleich für erkannte Orte. HTTP 429 von Wikidata wird verzögert wiederholt.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						if ( ! empty( $context->input['offline'] ) ) {
							return StepResult::done( array( 'places' => array() ) );
						}
						$places   = array();
						$resolver = new PlaceResolver( WikidataClient::for_site( $this->plugin->http ) );
						$area     = ServiceArea::from_settings();
						foreach ( (array) ( $context->output_of( 'extract' )['entities'] ?? array() ) as $entity ) {
							if ( 'place' !== ( $entity['type'] ?? '' ) || isset( $places[ $entity['name'] ] ) ) {
								continue;
							}
							$places[ $entity['name'] ] = $resolver->resolve( (string) $entity['name'], $area, get_locale() );
						}
						return StepResult::done( array( 'places' => $places ) );
					}
				},
				'save'         => new class() implements StepHandler {
					/**
					 * Speichert Kandidaten, Fundstellen und das Ergebnis des Ortsabgleichs.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						$repo      = new EntityRepository();
						$extracted = $context->output_of( 'extract' );
						$saved     = $repo->save_extraction( (int) $context->input['post_id'], (array) ( $extracted['entities'] ?? array() ) );
						if ( empty( $context->input['stub_response'] ) ) {
							update_post_meta( (int) $context->input['post_id'], \Lsec\Content\ContentIndex::EXTRACTED, (string) $context->input['hash'] );
						}
						$places    = array();
						foreach ( (array) ( $context->output_of( 'match_places' )['places'] ?? array() ) as $name => $result ) {
							$place           = $repo->save_place( $result, 'ai_extraction' );
							$places[ $name ] = $place['status'];
						}
						return StepResult::done(
							$saved + array(
								'places'   => $places,
								'rejected' => count( (array) ( $extracted['rejected'] ?? array() ) ),
							)
						);
					}
				},
			)
		);
	}

	/**
	 * Startet die Erkennung für einen Beitrag. Gleicher Inhalt (Hash) ergibt denselben Ablauf.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param int                  $post_id Beitrag.
	 * @param string               $hash    Inhalts-Hash.
	 * @param array<string, mixed> $extra   Zusätzlicher Startkontext (nur für Selbsttests).
	 * @return array{run_id: int, created: bool}
	 */
	public static function start( Plugin $plugin, int $post_id, string $hash, array $extra = array() ): array {
		return $plugin->engine->start(
			self::TYPE,
			'post',
			$post_id,
			array(
				'post_id' => $post_id,
				'hash'    => $hash,
			) + $extra,
			array( 'hash' => $hash ) + ( array() === $extra ? array() : array( 'extra' => $extra ) )
		);
	}

	/**
	 * KI-Dienst des Ablaufs. Für Selbsttests eine Attrappe mit fester Antwort, sonst die Einstellung.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param array<string, mixed> $input  Startkontext.
	 */
	public static function ai( Plugin $plugin, array $input ): AiService {
		if ( isset( $input['stub_response'] ) ) {
			return new AiService( new SelftestAiClient( 0.0, (string) $input['stub_response'] ), new AiCallRepository(), new PriceTable(), AiClientFactory::budget() );
		}
		return $plugin->ai->service();
	}
}
