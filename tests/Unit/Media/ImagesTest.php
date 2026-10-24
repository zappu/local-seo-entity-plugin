<?php
/**
 * Tests für Beitragsbilder: Bilder an die KI, Bildmodell, Beschreibung, Wahl, Bildauftrag (0.10.0).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Media;

use Lsec\Integrations\AI\AiException;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AnthropicClient;
use Lsec\Integrations\AI\OpenAiClient;
use Lsec\Integrations\AI\OpenRouterClient;
use Lsec\Integrations\AI\OpenRouterImageClient;
use Lsec\Media\ImageDescriber;
use Lsec\Media\ImagePicker;
use Lsec\Media\ImagePrompter;
use Lsec\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Bausteine ohne WordPress.
 */
final class ImagesTest extends TestCase {

	private const PIXEL = 'data:image/jpeg;base64,/9j/4AAQ';

	/**
	 * Anfrage mit Bild.
	 */
	private static function request(): AiRequest {
		return new AiRequest( 'image_description', 'System', 'Beschreibe.', ImageDescriber::schema(), 'image', false, 500, 60, 3, '', array( self::PIXEL ) );
	}

	public function test_openrouter_sends_image_as_image_url(): void {
		$body    = ( new OpenRouterClient( new FakeTransport( array() ), 'k', 'openai/gpt-6-luna' ) )->build_body( self::request() );
		$content = $body['messages'][1]['content'];
		$this->assertSame( 'text', $content[0]['type'] );
		$this->assertSame( 'Beschreibe.', $content[0]['text'] );
		$this->assertSame( array( 'url' => self::PIXEL ), $content[1]['image_url'] );
	}

	public function test_without_images_user_stays_text(): void {
		$body = ( new OpenRouterClient( new FakeTransport( array() ), 'k', 'm' ) )->build_body( new AiRequest( 't', 's', 'u' ) );
		$this->assertSame( 'u', $body['messages'][1]['content'] );
	}

	public function test_anthropic_and_openai_send_images(): void {
		$anthropic = ( new AnthropicClient( new FakeTransport( array() ), 'k', 'claude' ) )->build_body( self::request() );
		$block     = $anthropic['messages'][0]['content'][0];
		$this->assertSame( 'image', $block['type'] );
		$this->assertSame( 'image/jpeg', $block['source']['media_type'] );
		$this->assertSame( '/9j/4AAQ', $block['source']['data'] );
		$this->assertSame( 'text', $anthropic['messages'][0]['content'][1]['type'] );

		$openai = ( new OpenAiClient( new FakeTransport( array() ), 'k', 'gpt' ) )->build_body( self::request() );
		$this->assertSame( 'input_image', $openai['input'][0]['content'][1]['type'] );
		$this->assertSame( self::PIXEL, $openai['input'][0]['content'][1]['image_url'] );
	}

	public function test_with_user_keeps_images(): void {
		$this->assertSame( array( self::PIXEL ), self::request()->with_user( 'neu' )->images );
	}

	public function test_image_client_sends_only_supported_parameters(): void {
		$client = new OpenRouterImageClient( new FakeTransport( array() ), 'k', 'black-forest-labs/flux-3-image' );
		$body   = $client->build_body( 'a photo', '16:9', '1K', array( 'resolution', 'aspect_ratio', 'n' ) );
		$this->assertSame( '16:9', $body['aspect_ratio'] );
		$this->assertSame( '1K', $body['resolution'] );
		$this->assertArrayNotHasKey( 'output_format', $body );

		$body = $client->build_body( 'a photo', '16:9', '1K', array( 'aspect_ratio', 'quality', 'output_format' ) );
		$this->assertArrayNotHasKey( 'resolution', $body );
		$this->assertSame( 'webp', $body['output_format'] );

		$body = $client->build_body( 'a photo', '16:9', '1K', null );
		$this->assertSame( '16:9', $body['aspect_ratio'] );
		$this->assertArrayNotHasKey( 'resolution', $body );
	}

	public function test_image_client_reads_image_and_cost(): void {
		$png       = "\x89PNG\r\n\x1a\nrest";
		$transport = new FakeTransport(
			array(
				FakeTransport::json(
					array(
						'data'  => array( array( 'b64_json' => base64_encode( $png ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'usage' => array( 'cost' => 0.048 ),
					)
				),
			)
		);
		$image     = ( new OpenRouterImageClient( $transport, 'k', 'black-forest-labs/flux-3-image' ) )->generate( 'a photo of a dentist chair', '16:9', '1K', array( 'resolution', 'aspect_ratio' ) );
		$this->assertSame( $png, $image['bytes'] );
		$this->assertSame( 'image/png', $image['type'] );
		$this->assertSame( 0.048, $image['cost'] );
		$this->assertSame( OpenRouterImageClient::ENDPOINT, $transport->requests[0]['url'] );
		$this->assertSame( 'a photo of a dentist chair', $transport->requests[0]['body']['prompt'] );
	}

	public function test_image_client_without_image_fails(): void {
		$this->expectException( AiException::class );
		OpenRouterImageClient::parse( array( 'data' => array() ), 'm' );
	}

	public function test_supported_parameters_from_catalog(): void {
		$catalog = array(
			'data' => array(
				array(
					'id'                   => 'a/b',
					'supported_parameters' => array(
						'resolution'   => array(),
						'aspect_ratio' => array(),
					),
				),
			),
		);
		$this->assertSame( array( 'resolution', 'aspect_ratio' ), OpenRouterImageClient::supported_parameters( $catalog, 'a/b' ) );
		$this->assertNull( OpenRouterImageClient::supported_parameters( $catalog, 'x/y' ) );
	}

	public function test_description_is_cleaned_and_rated(): void {
		$data = ImageDescriber::normalize(
			array(
				'alt'       => 'Zahnärztin reinigt Zähne einer Patientin',
				'motif'     => 'Behandlungsraum',
				'keywords'  => array( 'Zahnreinigung', 'zahnreinigung', ' Prophylaxe ', '' ),
				'kind'      => 'photo',
				'watermark' => false,
				'text'      => false,
			),
			1600
		);
		$this->assertSame( array( 'Zahnreinigung', 'Prophylaxe' ), $data['keywords'] );
		$this->assertTrue( $data['suitable'] );
		$this->assertFalse( ImageDescriber::normalize( array( 'kind' => 'photo' ), 600 )['suitable'], 'zu schmal' );
		$this->assertFalse(
			ImageDescriber::normalize(
				array(
					'kind'      => 'photo',
					'watermark' => true,
				),
				2000
			)['suitable'],
			'Wasserzeichen'
		);
		$this->assertFalse( ImageDescriber::normalize( array( 'kind' => 'logo' ), 2000 )['suitable'] );
		$this->assertSame( 'other', ImageDescriber::normalize( array( 'kind' => 'meme' ), 2000 )['kind'] );
	}

	public function test_shortlist_prefers_matching_and_free_images(): void {
		$images = array(
			array(
				'id'       => 1,
				'used'     => 0,
				'alt'      => 'Hund am Strand',
				'keywords' => array( 'Strand' ),
			),
			array(
				'id'       => 2,
				'used'     => 2,
				'alt'      => 'Zahnreinigung beim Zahnarzt',
				'keywords' => array( 'Zahnreinigung', 'Prophylaxe' ),
			),
			array(
				'id'       => 3,
				'used'     => 0,
				'alt'      => 'Professionelle Zahnreinigung',
				'keywords' => array( 'Zahnreinigung' ),
			),
		);
		$topic  = array(
			'title'    => 'Professionelle Zahnreinigung in Wees',
			'keywords' => array( array( 'keyword' => 'zahnreinigung wees' ) ),
			'region'   => 'Wees',
		);
		$short  = ImagePicker::shortlist( $images, $topic );
		$this->assertSame( 3, $short[0]['id'], 'mehr Treffer und frei' );
		$this->assertSame( 2, $short[1]['id'] );
		$this->assertSame( 1, $short[2]['id'] );
		$this->assertStringContainsString( '#2 | schon Beitragsbild von 2', ImagePicker::prompt( array( 'title' => 'Zahnreinigung' ), $short ) );
	}

	public function test_choice_only_from_shortlist(): void {
		$short = array(
			array(
				'id'   => 5,
				'used' => 0,
			),
		);
		$this->assertSame(
			5,
			ImagePicker::choice(
				array(
					'image_id' => 5,
					'fit'      => 'good',
					'reason'   => 'passt',
				),
				$short
			)['id']
		);
		$this->assertSame(
			0,
			ImagePicker::choice(
				array(
					'image_id' => 9,
					'fit'      => 'good',
					'reason'   => '',
				),
				$short
			)['id'],
			'nicht in der Vorauswahl'
		);
		$this->assertSame(
			0,
			ImagePicker::choice(
				array(
					'image_id' => 5,
					'fit'      => 'none',
					'reason'   => '',
				),
				$short
			)['id']
		);
	}

	public function test_prompt_answer_is_cleaned(): void {
		$meta = ImagePrompter::normalize(
			array(
				'prompt'   => 'Realistic photo of dental cleaning instruments on a tray, no text, no logos',
				'alt'      => 'Instrumente für die Zahnreinigung',
				'title'    => 'Zahnreinigung',
				'filename' => 'Zahnreinigung Prophylaxe Instrumente.jpg',
				'keywords' => array( 'Zahnreinigung' ),
			),
			'Titel'
		);
		$this->assertSame( 'zahnreinigung-prophylaxe-instrumente', $meta['filename'] );
		$this->assertSame( 'grosse-uebung', ImagePrompter::slug( 'Große Übung' ) );
		$this->expectException( \InvalidArgumentException::class );
		ImagePrompter::normalize( array( 'prompt' => 'kurz' ), 'Titel' );
	}
}
