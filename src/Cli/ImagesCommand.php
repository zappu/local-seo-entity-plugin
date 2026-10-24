<?php
/**
 * WP-CLI: Bilder der Mediathek und Beitragsbilder.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Media\FeaturedImage;
use Lsec\Media\ImageDescriptionWorkflow;
use Lsec\Media\ImageLibrary;
use WP_CLI;

/**
 * Bilder der Mediathek beschreiben und auflisten, Beitragsbilder setzen.
 *
 * Nachtrag 24.10.2026 (0.10.0).
 */
final class ImagesCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Zahlen: Bilder, beschrieben, offen, geeignet, frei, KI-Bilder.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );
		$stats = ImageLibrary::stats();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $stats ) );
			return;
		}
		WP_CLI::log( sprintf( 'Bilder in der Mediathek: %d · beschrieben: %d · offen: %d (davon %d mit Fehler, neuer Versuch nach einem Tag)', $stats['images'], $stats['described'], $stats['pending'], $stats['errors'] ) );
		WP_CLI::log( sprintf( 'Als Beitragsbild geeignet: %d · davon frei: %d · schon genutzt: %d · KI-Bilder: %d', $stats['suitable'], $stats['free'], $stats['used'], $stats['generated'] ) );
	}

	/**
	 * Beschreibt Bilder ohne Beschreibung sofort (sonst macht das der Cronjob).
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Nur diese Bilder (auch schon beschriebene werden neu beschrieben).
	 *
	 * [--limit=<n>]
	 * : Höchstzahl offener Bilder.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--queue]
	 * : Nicht sofort, sondern als Ablauf für den Cronjob (für viele Bilder).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec images describe --limit=50
	 *     wp lsec images describe 1412 1413
	 *
	 * @param string[]              $args       Bilder.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function describe( array $args, array $assoc_args ): void {
		$ids = array_values( array_filter( array_map( 'intval', $args ) ) );
		if ( ! empty( $assoc_args['queue'] ) ) {
			$run = ImageDescriptionWorkflow::start( $this->plugin, $ids );
			if ( null === $run ) {
				WP_CLI::error( 'Es läuft schon eine Bildbeschreibung.' );
			}
			WP_CLI::success( 'Ablauf ' . $run['run_id'] . ' eingeplant; der Cronjob beschreibt die Bilder.' );
			return;
		}
		if ( array() === $ids ) {
			$ids = ImageLibrary::pending_ids( max( 1, (int) ( $assoc_args['limit'] ?? 20 ) ) );
		}
		if ( array() === $ids ) {
			WP_CLI::success( 'Alle Bilder sind beschrieben.' );
			return;
		}
		$plugin   = $this->plugin;
		$ai       = ImageLibrary::lazy( static fn() => ImageDescriptionWorkflow::vision( $plugin ) );
		$business = ImageLibrary::business();
		$counts   = array();
		foreach ( $ids as $id ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				WP_CLI::warning( $id . ': kein Bild.' );
				continue;
			}
			$result                      = ImageLibrary::describe( $id, $ai, $business );
			$info                        = ImageLibrary::description( $id );
			$counts[ $result['status'] ] = ( $counts[ $result['status'] ] ?? 0 ) + 1;
			WP_CLI::log( sprintf( '%d %s: %s%s', $id, $result['status'], $result['note'], null !== $info && 'described' === $result['status'] ? ' [' . implode( ', ', array_map( 'strval', (array) $info['keywords'] ) ) . ']' . ( empty( $info['suitable'] ) ? ' – nicht als Beitragsbild' : '' ) : '' ) );
		}
		WP_CLI::success( sprintf( '%d beschrieben, %d klein, %d Fehler.', $counts['described'] ?? 0, $counts['small'] ?? 0, $counts['error'] ?? 0 ) );
	}

	/**
	 * Bilder, die als Beitragsbild taugen.
	 *
	 * ## OPTIONS
	 *
	 * [--free]
	 * : Nur ungenutzte.
	 *
	 * [--format=<format>]
	 * : table, csv oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function list( array $args, array $assoc_args ): void {
		unset( $args );
		$rows = ImageLibrary::candidates();
		if ( ! empty( $assoc_args['free'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $r ): bool => 0 === (int) $r['used'] ) );
		}
		Output::items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			array_map(
				static fn( array $r ): array => array(
					'id'         => $r['id'],
					'genutzt'    => $r['used'],
					'ki'         => $r['generated'] ? 'ja' : '',
					'alt'        => $r['alt'],
					'stichworte' => implode( ', ', array_map( 'strval', (array) $r['keywords'] ) ),
				),
				$rows
			),
			array( 'id', 'genutzt', 'ki', 'alt', 'stichworte' )
		);
	}

	/**
	 * Setzt das Beitragsbild eines vorhandenen Beitrags: Mediathek, sonst KI-Bild (nach Einstellung).
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Beitrag.
	 *
	 * [--force]
	 * : Vorhandenes Beitragsbild ersetzen.
	 *
	 * [--generate]
	 * : Gleich ein KI-Bild erzeugen (etwa 0,05 USD), ohne in der Mediathek zu suchen.
	 *
	 * [--library]
	 * : Nur die Mediathek, kein KI-Bild.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec images assign 1360
	 *     wp lsec images assign 1360 --force --generate
	 *
	 * @param string[]              $args       Beitrag.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function assign( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		if ( null === get_post( $post_id ) ) {
			WP_CLI::error( 'Beitrag nicht gefunden: ' . $post_id );
		}
		$options = array(
			'force'    => ! empty( $assoc_args['force'] ),
			'generate' => ! empty( $assoc_args['generate'] ),
		);
		if ( ! empty( $assoc_args['library'] ) ) {
			$options['mode'] = 'library';
		}
		if ( ! $options['generate'] && array() !== ImageLibrary::pending_ids( 1 ) ) {
			WP_CLI::log( 'Hinweis: Es gibt Bilder ohne Beschreibung; die zählen erst nach wp lsec images describe.' );
		}
		try {
			$result = FeaturedImage::assign( $this->plugin, $post_id, null, $options );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $this->plugin->logger->redact( $e->getMessage() ) );
		}
		$this->plugin->logger->audit(
			'Beitragsbild gesetzt',
			array(
				'post_id'  => $post_id,
				'source'   => $result['source'],
				'image_id' => $result['image_id'],
				'user_id'  => get_current_user_id(),
			)
		);
		$label = ProductionCommand::IMAGE_SOURCES[ $result['source'] ] ?? $result['source'];
		if ( $result['image_id'] > 0 ) {
			WP_CLI::success( sprintf( 'Beitragsbild %s: Anhang %d. %s%s', $label, $result['image_id'], $result['note'], null !== $result['cost'] ? sprintf( ' Kosten %s USD.', number_format( (float) $result['cost'], 3, ',', '' ) ) : '' ) );
			return;
		}
		WP_CLI::warning( 'Kein Beitragsbild: ' . $result['note'] );
	}

	/**
	 * Bildmodelle bei OpenRouter (ohne Kosten). Preise stehen auf openrouter.ai beim Modell.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Nur Modelle, deren Kennung den Text enthält (z. B. flux).
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function models( array $args, array $assoc_args ): void {
		unset( $args );
		$current = (string) \Lsec\Core\Settings::get( 'ai_image_model' );
		try {
			$catalog = $this->plugin->ai->image_client( '' !== $current ? $current : 'black-forest-labs/flux-3-image' )->models();
		} catch ( \Throwable $e ) {
			WP_CLI::error( $this->plugin->logger->redact( $e->getMessage() ) );
		}
		$search = strtolower( (string) ( $assoc_args['search'] ?? '' ) );
		$rows   = array();
		foreach ( (array) ( $catalog['data'] ?? array() ) as $entry ) {
			$id = is_array( $entry ) ? (string) ( $entry['id'] ?? '' ) : '';
			if ( '' === $id || ( '' !== $search && ! str_contains( strtolower( $id ), $search ) ) ) {
				continue;
			}
			$rows[] = array(
				'modell'    => $id . ( $id === $current ? ' (eingestellt)' : '' ),
				'parameter' => implode( ', ', array_keys( (array) ( $entry['supported_parameters'] ?? array() ) ) ),
			);
		}
		Output::items( 'table', $rows, array( 'modell', 'parameter' ) );
	}
}
