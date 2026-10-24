<?php
/**
 * Beitragsbild für einen Beitrag: erst die Mediathek, sonst ein KI-Bild.
 *
 * @package Lsec
 */

namespace Lsec\Media;

use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Integrations\AI\AiClientFactory;
use Lsec\Integrations\AI\AiException;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiResponse;
use Lsec\Integrations\AI\OpenRouterImageClient;
use Lsec\Topics\TopicRepository;
use Lsec\Workflow\RetryableException;

/**
 * Nachtrag 24.10.2026 (0.10.0, Wunsch von Thomas): Entwürfe bekamen kein Beitragsbild. Reihenfolge:
 * 1. vorhandenes Beitragsbild bleibt (außer mit force),
 * 2. passendes Bild aus der Mediathek, freie zuerst (ImagePicker),
 * 3. wenn keines passt und image_mode = library_ai: KI-Bild über OpenRouter (ImagePrompter, OpenRouterImageClient).
 * Ergebnis und Grund stehen in den Post-Metas _lsec_image_source und _lsec_image_note.
 */
final class FeaturedImage {

	public const SOURCE = '_lsec_image_source';

	public const NOTE = '_lsec_image_note';

	/**
	 * Zwischenspeicher der Parameter je Bildmodell.
	 */
	public const MODELS_CACHE = 'lsec_image_models';

	/**
	 * Angaben zum Beitrag für Bildwahl und Bildauftrag.
	 *
	 * @param int $post_id Beitrag.
	 * @return array{title: string, keyword: string, region: string, service: string, excerpt: string, topic: array<string, mixed>}
	 */
	public static function article( int $post_id ): array {
		$post     = get_post( $post_id );
		$title    = html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$excerpt  = null === $post ? '' : (string) $post->post_excerpt;
		$excerpt  = '' !== trim( $excerpt ) ? $excerpt : wp_trim_words( wp_strip_all_tags( null === $post ? '' : (string) $post->post_content ), 70, '' );
		$topic_id = (int) get_post_meta( $post_id, '_lsec_topic_id', true );
		$topic    = $topic_id > 0 ? ( new TopicRepository() )->detail( $topic_id ) : null;
		$keywords = null === $topic ? array() : (array) $topic['keywords'];
		$primary  = array_values( array_filter( $keywords, static fn( array $k ): bool => 'primary' === $k['role'] ) );
		$service  = '';
		foreach ( null === $topic ? array() : (array) $topic['entities'] as $entity ) {
			if ( 'service' === $entity['type'] ) {
				$service = (string) $entity['name'];
				break;
			}
		}
		return array(
			'title'   => $title,
			'keyword' => array() !== $primary ? (string) $primary[0]['keyword'] : '',
			'region'  => null === $topic ? '' : (string) $topic['region'],
			'service' => $service,
			'excerpt' => $excerpt,
			'topic'   => array(
				'title'    => $title,
				'keywords' => $keywords,
				'region'   => null === $topic ? '' : (string) $topic['region'],
			),
		);
	}

	/**
	 * Setzt das Beitragsbild.
	 *
	 * Außerdem möglich: WaitException, wenn das KI-Budget des Ablaufs erreicht ist, und RuntimeException, wenn
	 * das Bild nicht gespeichert werden kann.
	 *
	 * @param Plugin               $plugin  Plugin.
	 * @param int                  $post_id Beitrag.
	 * @param int|null             $run_id  Ablauf (Budget und Kosten).
	 * @param array<string, mixed> $options mode (statt Einstellung), force (vorhandenes ersetzen), generate (gleich KI-Bild),
	 *                                      stub (Prüfskript: array mit image_id, ohne KI).
	 * @return array{source: string, image_id: int, note: string, cost: float|null}
	 * @throws AiException        Bei Fehlern der KI.
	 * @throws RetryableException Bei vorübergehenden Fehlern.
	 */
	public static function assign( Plugin $plugin, int $post_id, ?int $run_id, array $options = array() ): array {
		$mode     = (string) ( $options['mode'] ?? Settings::get( 'image_mode' ) );
		$generate = ! empty( $options['generate'] );
		if ( 'off' === $mode && ! $generate ) {
			return self::finish( $post_id, 'off', 0, 'Beitragsbilder sind ausgeschaltet (Einstellungen → Bilder).' );
		}
		$current = (int) get_post_thumbnail_id( $post_id );
		if ( $current > 0 && empty( $options['force'] ) ) {
			return self::result( 'kept', $current, 'Der Beitrag hat schon ein Beitragsbild.' );
		}
		if ( array_key_exists( 'stub', $options ) ) {
			$id = (int) ( ( (array) $options['stub'] )['image_id'] ?? 0 );
			if ( $id > 0 && wp_attachment_is_image( $id ) ) {
				set_post_thumbnail( $post_id, $id );
				return self::finish( $post_id, 'library', $id, 'Testlauf: Bild aus der Testdatei.' );
			}
			return self::finish( $post_id, 'none', 0, 'Testlauf ohne Bild.' );
		}

		$article = self::article( $post_id );
		$ai      = $plugin->ai->service();
		$note    = '';
		if ( ! $generate ) {
			$images = array_values( array_filter( ImageLibrary::candidates(), static fn( array $i ): bool => (int) $i['id'] !== $current ) );
			if ( array() === $images ) {
				$note = 'Keine geeigneten Bilder in der Mediathek.';
			} else {
				$short    = ImagePicker::shortlist( $images, $article['topic'] );
				$response = $ai->structured( new AiRequest( 'image_choice', ImagePicker::system(), ImagePicker::prompt( $article, $short ), ImagePicker::schema(), 'choice', false, 1000, 90 ), $run_id );
				$choice   = ImagePicker::choice( (array) $response->data, $short );
				if ( $choice['id'] > 0 ) {
					set_post_thumbnail( $post_id, $choice['id'] );
					return self::finish( $post_id, 'library', $choice['id'], 'Aus der Mediathek' . ( 'acceptable' === $choice['fit'] ? ' (passt allgemein)' : '' ) . ': ' . $choice['reason'] );
				}
				$note = 'Kein passendes Bild in der Mediathek' . ( '' !== $choice['reason'] ? ': ' . $choice['reason'] : '.' );
			}
			if ( 'library_ai' !== $mode ) {
				return self::finish( $post_id, 'none', 0, $note );
			}
		}

		$budget = AiClientFactory::budget();
		if ( null === $run_id && 'month' === $budget->status()['exceeded'] ) {
			return self::finish( $post_id, 'none', 0, trim( $note . ' KI-Monatsbudget erreicht, kein KI-Bild.' ) );
		}
		$budget->assert_available( $run_id );
		$client   = $plugin->ai->image_client( (string) Settings::get( 'ai_image_model' ) );
		$response = $ai->structured( new AiRequest( 'image_prompt', ImagePrompter::system(), ImagePrompter::prompt( $article, ImageLibrary::business() ), ImagePrompter::schema(), 'image_prompt', false, 1500, 90 ), $run_id );
		$meta     = ImagePrompter::normalize( (array) $response->data, $article['title'] );
		$log      = new AiCallRepository();
		$hash     = hash( 'sha256', 'image_generation' . "\0" . $client->model . "\0" . $meta['prompt'] );
		try {
			$image = $client->generate( $meta['prompt'], (string) Settings::get( 'image_aspect' ), (string) Settings::get( 'image_resolution' ), self::supported( $client ) );
		} catch ( AiException | RetryableException $e ) {
			$log->record( 'image_generation', 'openrouter', $client->model, $e instanceof RetryableException ? 'retryable_error' : 'fatal_error', null, $hash, $run_id );
			throw $e;
		}
		$log->record( 'image_generation', 'openrouter', $client->model, 'ok', new AiResponse( 'openrouter', $image['model'], '', null, array(), null, null, $image['cost'], $image['duration_ms'] ), $hash, $run_id );
		$id = ImageLibrary::save_generated( $image['bytes'], $image['type'], $meta, $post_id, $client->model, $image['cost'] );
		set_post_thumbnail( $post_id, $id );
		return self::finish( $post_id, 'generated', $id, trim( ( '' !== $note ? $note . ' ' : '' ) . 'KI-Bild erzeugt (' . $client->model . ').' ), $image['cost'] );
	}

	/**
	 * Parameter des Bildmodells, einen Tag zwischengespeichert.
	 *
	 * @param OpenRouterImageClient $client Bildmodell.
	 * @return list<string>|null
	 */
	private static function supported( OpenRouterImageClient $client ): ?array {
		$cache = get_transient( self::MODELS_CACHE );
		if ( ! is_array( $cache ) ) {
			$cache = $client->models();
			if ( array() !== $cache ) {
				set_transient( self::MODELS_CACHE, $cache, DAY_IN_SECONDS );
			}
		}
		return OpenRouterImageClient::supported_parameters( $cache, $client->model );
	}

	/**
	 * Ergebnis vermerken.
	 *
	 * @param int        $post_id Beitrag.
	 * @param string     $source  library, generated, none, off.
	 * @param int        $id      Anhang.
	 * @param string     $note    Grund.
	 * @param float|null $cost    Kosten des KI-Bilds.
	 * @return array{source: string, image_id: int, note: string, cost: float|null}
	 */
	private static function finish( int $post_id, string $source, int $id, string $note, ?float $cost = null ): array {
		update_post_meta( $post_id, self::SOURCE, $source );
		update_post_meta( $post_id, self::NOTE, wp_slash( mb_substr( $note, 0, 500 ) ) );
		return self::result( $source, $id, $note, $cost );
	}

	/**
	 * Ergebnis.
	 *
	 * @param string     $source Quelle.
	 * @param int        $id     Anhang.
	 * @param string     $note   Grund.
	 * @param float|null $cost   Kosten.
	 * @return array{source: string, image_id: int, note: string, cost: float|null}
	 */
	private static function result( string $source, int $id, string $note, ?float $cost = null ): array {
		return array(
			'source'   => $source,
			'image_id' => $id,
			'note'     => $note,
			'cost'     => $cost,
		);
	}
}
