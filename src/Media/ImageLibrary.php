<?php
/**
 * Bilder der Mediathek: Beschreibung, Kandidaten für Beitragsbilder, Speichern erzeugter Bilder.
 *
 * @package Lsec
 */

namespace Lsec\Media;

use Lsec\Entities\BusinessProfile;
use Lsec\Integrations\AI\AiException;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiService;
use Lsec\Workflow\RetryableException;
use Lsec\Workflow\WaitException;

/**
 * Nachtrag 24.10.2026 (0.10.0). Die Beschreibung steht als JSON in der Post-Meta _lsec_image des Anhangs; einen
 * vorhandenen Alternativtext überschreibt das Plugin nie. Als genutzt gilt ein Bild, das Beitragsbild eines Beitrags,
 * einer Seite oder eines Produkts ist (nicht im Papierkorb).
 */
final class ImageLibrary {

	public const META = '_lsec_image';

	public const ERROR = '_lsec_image_error';

	public const GENERATED = '_lsec_ai_generated';

	/**
	 * Mindestbreite eines Beitragsbilds in Pixeln.
	 */
	public const MIN_WIDTH = ImageDescriber::MIN_WIDTH;

	/**
	 * Kleinere Bilder (Symbole, Logos) werden ohne KI als ungeeignet vermerkt.
	 */
	public const SMALL = 400;

	/**
	 * Bilder je Durchgang.
	 */
	public const BATCH = 10;

	/**
	 * Bildformate, die beschrieben werden.
	 */
	public const MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );

	/**
	 * Nach einem Fehler erst nach einem Tag erneut versuchen.
	 */
	public const RETRY_AFTER = 86400;

	/**
	 * Bilder ohne Beschreibung, neueste zuerst.
	 *
	 * @param int $limit Höchstzahl.
	 * @return int[]
	 */
	public static function pending_ids( int $limit ): array {
		global $wpdb;
		$mimes = "'" . implode( "', '", self::MIMES ) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$mimes}) AND d.meta_id IS NULL
				AND ( e.meta_id IS NULL OR CAST(e.meta_value AS UNSIGNED) < %d )
				ORDER BY p.ID DESC LIMIT %d",
				self::META,
				self::ERROR,
				time() - self::RETRY_AFTER,
				max( 1, $limit )
			)
		);
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Angaben eines Anhangs.
	 *
	 * @param int $id Anhang.
	 * @return array{id: int, filename: string, title: string, alt: string, caption: string, width: int, height: int}
	 */
	public static function info( int $id ): array {
		$meta = (array) wp_get_attachment_metadata( $id );
		$file = (string) get_attached_file( $id );
		return array(
			'id'       => $id,
			'filename' => '' !== $file ? wp_basename( $file ) : '',
			'title'    => (string) get_the_title( $id ),
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'  => (string) wp_get_attachment_caption( $id ),
			'width'    => (int) ( $meta['width'] ?? 0 ),
			'height'   => (int) ( $meta['height'] ?? 0 ),
		);
	}

	/**
	 * Gespeicherte Beschreibung.
	 *
	 * @param int $id Anhang.
	 * @return array<string, mixed>|null
	 */
	public static function description( int $id ): ?array {
		$data = json_decode( (string) get_post_meta( $id, self::META, true ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Branche für die Einordnung: Name und bis zu sechs Leistungen aus dem Unternehmensprofil.
	 */
	public static function business(): string {
		$profile  = ( new BusinessProfile() )->get();
		$name     = (string) ( $profile['organization']['name'] ?? '' );
		$services = array_slice( array_map( static fn( array $s ): string => (string) $s['name'], (array) ( $profile['services'] ?? array() ) ), 0, 6 );
		return trim( $name . ( array() !== $services ? ' (Leistungen: ' . implode( ', ', $services ) . ')' : '' ) );
	}

	/**
	 * Beschreibt ein Bild. Kleine Bilder ohne KI; Fehler werden vermerkt und nach einem Tag erneut versucht.
	 *
	 * @param int                   $id       Anhang.
	 * @param callable(): AiService $ai       KI mit einem Modell, das Bilder liest (erst bei Bedarf angelegt).
	 * @param string                $business Branche.
	 * @param int|null              $run_id   Ablauf.
	 * @return array{id: int, status: string, note: string}
	 * @throws WaitException Wenn das KI-Budget erreicht ist.
	 */
	public static function describe( int $id, callable $ai, string $business, ?int $run_id = null ): array {
		$info = self::info( $id );
		if ( $info['width'] > 0 && $info['width'] < self::SMALL ) {
			self::store(
				$id,
				array(
					'alt'       => '',
					'motif'     => '',
					'keywords'  => array(),
					'kind'      => 'small',
					'watermark' => false,
					'text'      => false,
					'suitable'  => false,
				),
				''
			);
			return array(
				'id'     => $id,
				'status' => 'small',
				'note'   => 'kleiner als ' . self::SMALL . ' Pixel',
			);
		}
		$url = self::data_url( $id );
		if ( null === $url ) {
			update_post_meta( $id, self::ERROR, time() );
			return array(
				'id'     => $id,
				'status' => 'error',
				'note'   => 'Bilddatei nicht lesbar',
			);
		}
		try {
			$response = $ai()->structured(
				new AiRequest( 'image_description', ImageDescriber::system(), ImageDescriber::prompt( $info, $business ), ImageDescriber::schema(), 'image', false, 1500, 90, 3, '', array( $url ) ),
				$run_id
			);
		} catch ( AiException | RetryableException $e ) {
			update_post_meta( $id, self::ERROR, time() );
			return array(
				'id'     => $id,
				'status' => 'error',
				'note'   => mb_substr( $e->getMessage(), 0, 200 ),
			);
		}
		$data = ImageDescriber::normalize( (array) $response->data, $info['width'] );
		self::store( $id, $data, $response->model );
		return array(
			'id'     => $id,
			'status' => 'described',
			'note'   => $data['alt'],
		);
	}

	/**
	 * KI-Dienst, der erst beim ersten Aufruf angelegt wird.
	 *
	 * @param callable(): AiService $factory Erzeugt den Dienst.
	 * @return callable(): AiService
	 */
	public static function lazy( callable $factory ): callable {
		$service = null;
		return static function () use ( &$service, $factory ): AiService {
			$service = $service ?? $factory();
			return $service;
		};
	}

	/**
	 * Speichert die Beschreibung; setzt den Alternativtext nur, wenn keiner da ist.
	 *
	 * @param int                  $id    Anhang.
	 * @param array<string, mixed> $data  Beschreibung.
	 * @param string               $model Modell.
	 */
	public static function store( int $id, array $data, string $model ): void {
		update_post_meta(
			$id,
			self::META,
			wp_slash(
				(string) wp_json_encode(
					$data + array(
						'model' => $model,
						'at'    => gmdate( 'c' ),
					),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			)
		);
		delete_post_meta( $id, self::ERROR );
		if ( '' !== (string) ( $data['alt'] ?? '' ) && '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( (string) $data['alt'] ) );
		}
	}

	/**
	 * Bild verkleinert (höchstens 1024 Pixel) als data-URL für das Modell.
	 *
	 * @param int $id Anhang.
	 */
	public static function data_url( int $id ): ?string {
		$file = (string) get_attached_file( $id );
		if ( '' === $file || ! is_readable( $file ) ) {
			return null;
		}
		$editor = wp_get_image_editor( $file );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1024, 1024, false );
			$editor->set_quality( 80 );
			$tmp   = wp_tempnam( 'lsec-image' ) . '.jpg';
			$saved = $editor->save( $tmp, 'image/jpeg' );
			if ( ! is_wp_error( $saved ) && is_readable( (string) $saved['path'] ) ) {
				$bytes = (string) file_get_contents( (string) $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				wp_delete_file( (string) $saved['path'] );
				wp_delete_file( $tmp );
				return '' === $bytes ? null : 'data:image/jpeg;base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
			wp_delete_file( $tmp );
		}
		$type = (string) get_post_mime_type( $id );
		if ( ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || filesize( $file ) > 4 * MB_IN_BYTES ) {
			return null;
		}
		$bytes = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return '' === $bytes ? null : 'data:' . $type . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Wie oft ist ein Bild Beitragsbild? Anhang => Anzahl.
	 *
	 * @return array<int, int>
	 */
	public static function usage(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			"SELECT pm.meta_value AS image, COUNT(*) AS n FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_thumbnail_id' AND p.post_type NOT IN ('revision', 'attachment') AND p.post_status NOT IN ('trash', 'auto-draft')
			GROUP BY pm.meta_value",
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['image'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Beschriebene Bilder, die als Beitragsbild taugen, mit Nutzung.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function candidates(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'attachment'", self::META ), ARRAY_A );
		$usage = self::usage();
		$out   = array();
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row['meta_value'], true );
			if ( ! is_array( $data ) || empty( $data['suitable'] ) ) {
				continue;
			}
			$id    = (int) $row['post_id'];
			$file  = (string) get_attached_file( $id );
			$out[] = array(
				'id'        => $id,
				'used'      => $usage[ $id ] ?? 0,
				'alt'       => (string) ( $data['alt'] ?? '' ),
				'motif'     => (string) ( $data['motif'] ?? '' ),
				'keywords'  => (array) ( $data['keywords'] ?? array() ),
				'title'     => (string) get_the_title( $id ),
				'filename'  => '' !== $file ? wp_basename( $file ) : '',
				'generated' => '' !== (string) get_post_meta( $id, self::GENERATED, true ),
			);
		}
		return $out;
	}

	/**
	 * Zahlen für Bericht und Befehl.
	 *
	 * @return array{images: int, described: int, pending: int, errors: int, suitable: int, free: int, used: int, generated: int}
	 */
	public static function stats(): array {
		global $wpdb;
		$mimes = "'" . implode( "', '", self::MIMES ) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$images    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ({$mimes})" );
		$described = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'attachment'", self::META ) );
		$errors    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'attachment'", self::ERROR ) );
		$generated = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'attachment'", self::GENERATED ) );
		// phpcs:enable
		$candidates = self::candidates();
		$free       = count( array_filter( $candidates, static fn( array $c ): bool => 0 === (int) $c['used'] ) );
		return array(
			'images'    => $images,
			'described' => $described,
			'pending'   => max( 0, $images - $described ),
			'errors'    => $errors,
			'suitable'  => count( $candidates ),
			'free'      => $free,
			'used'      => count( $candidates ) - $free,
			'generated' => $generated,
		);
	}

	/**
	 * Speichert ein erzeugtes Bild in der Mediathek: höchstens 1600 Pixel breit, als WebP (sonst JPEG), mit
	 * Alternativtext, Beschreibung und Vermerk „KI-generiert“.
	 *
	 * @param string                                                                                  $bytes   Bild.
	 * @param string                                                                                  $type    Medientyp.
	 * @param array{prompt: string, alt: string, title: string, filename: string, keywords: string[]} $meta    Angaben aus dem Bildauftrag.
	 * @param int                                                                                     $post_id Beitrag (Eltern-Element).
	 * @param string                                                                                  $model   Bildmodell.
	 * @param float|null                                                                              $cost    Kosten in USD.
	 * @return int Anhang.
	 * @throws \RuntimeException Wenn das Bild nicht gespeichert werden kann.
	 */
	public static function save_generated( string $bytes, string $type, array $meta, int $post_id, string $model, ?float $cost ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$ext = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		)[ $type ] ?? 'png';
		$raw = wp_tempnam( 'lsec-generated' );
		file_put_contents( $raw . '.' . $ext, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_delete_file( $raw );
		$raw   .= '.' . $ext;
		$path   = $raw;
		$out    = $ext;
		$editor = wp_get_image_editor( $raw );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1600, 1600, false );
			$out   = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'webp' : 'jpg';
			$saved = $editor->save( $raw . '-out.' . $out, 'webp' === $out ? 'image/webp' : 'image/jpeg' );
			if ( ! is_wp_error( $saved ) ) {
				$path = (string) $saved['path'];
			} else {
				$out = $ext;
			}
		}
		$file = array(
			'name'     => $meta['filename'] . '.' . $out,
			'tmp_name' => $path,
		);
		$id   = media_handle_sideload( $file, $post_id, '' !== $meta['title'] ? $meta['title'] : null );
		if ( $path !== $raw ) {
			wp_delete_file( $raw );
		}
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $path );
			throw new \RuntimeException( 'Bild nicht gespeichert: ' . $id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => sprintf( 'KI-generiertes Bild (%s) für Beitrag %d.', $model, $post_id ),
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $meta['alt'] ) );
		update_post_meta(
			$id,
			self::GENERATED,
			wp_slash(
				(string) wp_json_encode(
					array(
						'model'  => $model,
						'prompt' => $meta['prompt'],
						'cost'   => $cost,
						'post'   => $post_id,
						'at'     => gmdate( 'c' ),
					),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			)
		);
		self::store(
			$id,
			array(
				'alt'       => $meta['alt'],
				'motif'     => $meta['alt'],
				'keywords'  => $meta['keywords'],
				'kind'      => 'photo',
				'watermark' => false,
				'text'      => false,
				'suitable'  => true,
			),
			$model
		);
		return (int) $id;
	}
}
