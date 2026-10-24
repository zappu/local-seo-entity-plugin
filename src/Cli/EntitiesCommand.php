<?php
/**
 * WP-CLI: Entitäten und Ortsabgleich.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Content\IndexItem;
use Lsec\Core\Settings;
use Lsec\Entities\EntityExtractionWorkflow;
use Lsec\Entities\EntityExtractor;
use Lsec\Entities\EntityRepository;
use Lsec\Research\Keywords\KeywordNormalizer;
use Lsec\Entities\PlaceResolver;
use Lsec\Entities\TextfocusEntityImport;
use Lsec\Entities\ServiceArea;
use Lsec\Integrations\Wikidata\WikidataClient;
use Lsec\Workflow\RetryableException;
use WP_CLI;

/**
 * Ortsabgleich über Wikidata (Prüfpunkt M18) und Servicegebiet.
 */
final class EntitiesCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin           $plugin   Plugin.
	 * @param EntityRepository $entities Speicher.
	 */
	public function __construct( private readonly Plugin $plugin, private readonly EntityRepository $entities ) {}

	/**
	 * Gleicht einen Ortsnamen mit Wikidata ab.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Ortsname, z. B. Burgdorf.
	 *
	 * [--[no-]area]
	 * : Servicegebiet als Kontext verwenden. Mit --no-area ohne Kontext abgleichen.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--locale=<locale>]
	 * : Locale.
	 * ---
	 * default: de_DE
	 * ---
	 *
	 * [--save]
	 * : Ergebnis als Entität speichern.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec entities resolve-place Burgdorf --no-area
	 *     wp lsec entities resolve-place Burgdorf --save
	 *
	 * @subcommand resolve-place
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function resolve_place( array $args, array $assoc_args ): void {
		$area = ( isset( $assoc_args['area'] ) && false === $assoc_args['area'] ) ? null : ServiceArea::from_settings();
		WP_CLI::log( null === $area ? 'Servicegebiet: keins' : 'Servicegebiet: ' . $area->label . ' (' . implode( ', ', $area->wikidata_ids ) . ( $area->radius_km > 0 ? ', ' . $area->radius_km . ' km' : '' ) . ')' );
		try {
			$result = ( new PlaceResolver( $this->wikidata() ) )->resolve( (string) $args[0], $area, (string) ( $assoc_args['locale'] ?? 'de_DE' ) );
		} catch ( RetryableException $e ) {
			WP_CLI::error( $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$rows = array();
		foreach ( $result['candidates'] as $c ) {
			$rows[] = array(
				'wikidata'     => $c['id'],
				'beschreibung' => $c['description'],
				'liegt_in'     => implode( ', ', array_slice( (array) $c['located_in'], 0, 4 ) ),
				'entfernung'   => null === $c['distance_km'] ? '' : $c['distance_km'] . ' km',
				'im_gebiet'    => null === $c['in_area'] ? '' : ( $c['in_area'] ? 'ja' : 'nein' ),
				'gewählt'      => null !== $result['chosen'] && $result['chosen']['id'] === $c['id'] ? '✓' : '',
			);
		}
		if ( array() !== $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'wikidata', 'beschreibung', 'liegt_in', 'entfernung', 'im_gebiet', 'gewählt' ) );
		}
		WP_CLI::log( sprintf( 'Status: %s – %s', $result['status'], $result['reason'] ) );
		if ( ! empty( $assoc_args['save'] ) ) {
			$saved = $this->entities->save_place( $result );
			WP_CLI::success(
				$saved['kept_decision']
					? sprintf( 'Entität %d hat bereits eine Entscheidung (%s), nicht überschrieben.', $saved['id'], $saved['status'] )
					: sprintf( 'Entität %d gespeichert (%s).', $saved['id'], $saved['status'] )
			);
		}
	}

	/**
	 * Legt das Servicegebiet fest, z. B. „Region Hannover“.
	 *
	 * Sucht die Verwaltungseinheit bei Wikidata und speichert Q-ID und Mittelpunkt.
	 *
	 * ## OPTIONS
	 *
	 * [<name>]
	 * : Name der Verwaltungseinheit. Ohne Angabe wird das aktuelle Gebiet angezeigt.
	 *
	 * [--id=<qid>]
	 * : Q-ID direkt angeben, wenn die Suche mehrdeutig ist.
	 *
	 * [--radius=<km>]
	 * : Zusätzlich alle Orte im Umkreis um den Mittelpunkt zählen.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--clear]
	 * : Servicegebiet entfernen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec entities service-area "Region Hannover"
	 *     wp lsec entities service-area "Region Hannover" --radius=40
	 *
	 * @subcommand service-area
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function service_area( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['clear'] ) ) {
			Settings::set( 'service_area', array() );
			WP_CLI::success( 'Servicegebiet entfernt.' );
			return;
		}
		if ( array() === $args && empty( $assoc_args['id'] ) ) {
			$area = ServiceArea::from_settings();
			WP_CLI::log( null === $area ? 'Kein Servicegebiet festgelegt.' : (string) wp_json_encode( $area->to_array(), JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$wikidata = $this->wikidata();
		try {
			$id = (string) ( $assoc_args['id'] ?? '' );
			if ( '' === $id ) {
				$hits = $wikidata->search( (string) $args[0], 'de', 5 );
				foreach ( $hits as $hit ) {
					WP_CLI::log( sprintf( '  %s  %s – %s', $hit['id'], $hit['label'], $hit['description'] ) );
				}
				if ( array() === $hits ) {
					WP_CLI::error( 'Nichts gefunden.' );
				}
				$id = $hits[0]['id'];
			}
			$found = ServiceArea::from_wikidata( $wikidata, $id, (float) ( $assoc_args['radius'] ?? 0 ) );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$area = $found['area'];
		Settings::set( 'service_area', $area->to_array() );
		WP_CLI::success( sprintf( 'Servicegebiet: %s (%s, %s)%s', $area->label, $id, $found['description'], $area->radius_km > 0 ? ', Umkreis ' . $area->radius_km . ' km' : '' ) );
	}

	/**
	 * Erkennt Entitäten in einem Beitrag oder einer Kategorie und speichert sie als Kandidaten mit Fundstellen (M11).
	 *
	 * Geht auch für Arten, die von der automatischen Erkennung ausgenommen sind (z. B. Blogbeiträge).
	 *
	 * Ruft die KI auf (Kosten laut Modell, meist wenige Cent).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Beitrag, Seite oder Produkt (auch Elementor), term:<id> für eine Kategorie.
	 *
	 * [--dry-run]
	 * : Nur anzeigen, nichts speichern.
	 *
	 * [--queue]
	 * : Als Ablauf über den Cronjob ausführen (mit Ortsabgleich und KI-Budget), statt sofort hier.
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function extract( array $args, array $assoc_args ): void {
		$item = IndexItem::from_ref( (string) ( $args[0] ?? '' ) );
		if ( null === $item ) {
			WP_CLI::error( 'Inhalt nicht gefunden.' );
		}
		$content = $item->read();
		$title   = $item->title();
		if ( ! empty( $assoc_args['queue'] ) ) {
			$hash = \Lsec\Content\ContentIndex::hash( $title, $content['text'] );
			$run  = EntityExtractionWorkflow::start_item( $this->plugin, $item, $hash, array( 'manual' => gmdate( 'c' ) ) );
			WP_CLI::success( 'Ablauf ' . $run['run_id'] . ' eingeplant. Stand: wp lsec runs list' );
			return;
		}
		WP_CLI::log( sprintf( '„%s“: %d Wörter (%s)', $title, $content['words'], $content['source'] ) );
		try {
			$service = $this->plugin->ai->service();
			$result  = ( new EntityExtractor( $service ) )->extract( $title, $content['text'], $this->entities->known_names(), null, $this->entities->profile_services() );
		} catch ( RetryableException $e ) {
			WP_CLI::error( 'Vorübergehend (' . $e->error_code . '): ' . $this->plugin->logger->redact( $e->getMessage() ) );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $this->plugin->logger->redact( $e->getMessage() ) );
		}
		$rows = array();
		foreach ( $result['entities'] as $entity ) {
			$rows[] = array(
				'typ'         => $entity['type'],
				'name'        => $entity['name'],
				'sicherheit'  => number_format( $entity['confidence'], 2, ',', '' ),
				'fundstellen' => count( $entity['evidence'] ),
				'beispiel'    => mb_substr( $entity['evidence'][0]['excerpt'], 0, 70 ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'typ', 'name', 'sicherheit', 'fundstellen', 'beispiel' ) );
		foreach ( $result['rejected'] as $item ) {
			WP_CLI::log( 'Verworfen: ' . $item['name'] . ' (' . $item['reason'] . ')' );
		}
		foreach ( $result['ambiguities'] as $item ) {
			WP_CLI::log( 'Mehrdeutig: ' . $item['name'] . ' – ' . $item['reason'] . ' (Abgleich: wp lsec entities resolve-place "' . $item['name'] . '")' );
		}
		WP_CLI::log( 'Kosten: ' . ( null === $result['cost'] ? 'unbekannt' : '$' . number_format( $result['cost'], 4 ) ) );
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( 'Probelauf, nichts gespeichert.' );
			return;
		}
		$saved = $this->entities->save_extraction( $item->id, $result['entities'], $item->object_type );
		$item->set_meta( \Lsec\Content\ContentIndex::EXTRACTED, \Lsec\Content\ContentIndex::hash( $title, $content['text'] ) );
		WP_CLI::success( sprintf( '%d neue Kandidaten, %d vorhandene Entitäten, %d Fundstellen gespeichert.', $saved['created'], $saved['reused'], $saved['mentions'] ) );
	}

	/**
	 * Übernimmt die Entitäten-Tabelle einer Textfocus-Analyse (Excel-Export) als Kandidaten.
	 *
	 * Die Wikidata-Zuordnung von Textfocus wird nur als Hinweis gespeichert. Orte lassen sich danach mit
	 * resolve-place gegen das Servicegebiet prüfen.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Excel- oder CSV-Datei.
	 *
	 * [--dry-run]
	 * : Nur anzeigen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec entities import flaechenreinigung_Semantic_analysis.xlsx --dry-run
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function import( array $args, array $assoc_args ): void {
		$path = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( 'Datei nicht gefunden oder nicht lesbar: ' . $path );
		}
		try {
			$rows = TextfocusEntityImport::from_file( $path, basename( $path ) );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$area  = ServiceArea::from_settings();
		$table = array();
		foreach ( $rows as $row ) {
			$table[] = array(
				'typ'       => $row['type'],
				'name'      => $row['name'],
				'textfocus' => $row['entity'] . ( '' !== $row['wikidata'] ? ' (' . $row['wikidata'] . ')' : '' ),
				'relevanz'  => null === $row['relevance'] ? '' : number_format( $row['relevance'], 2, ',', '' ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $table, array( 'typ', 'name', 'textfocus', 'relevanz' ) );
		$places = array_values( array_filter( $rows, static fn( array $r ): bool => 'place' === $r['type'] ) );
		if ( array() !== $places ) {
			WP_CLI::log( sprintf( '%d Orte. Abgleich mit dem Servicegebiet%s: wp lsec entities resolve-place "<Name>" --save', count( $places ), null === $area ? ' (noch keins festgelegt)' : ' ' . $area->label ) );
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( count( $rows ) . ' Entitäten gelesen, Probelauf, nichts gespeichert.' );
			return;
		}
		$saved = $this->entities->save_textfocus( $rows );
		WP_CLI::success( sprintf( '%d neue Kandidaten, %d vorhandene Entitäten ergänzt.', $saved['created'], $saved['reused'] ) );
	}

	/**
	 * Bestätigt oder verwirft eine oder mehrere Entitäten; wird mit Benutzer und Zeit protokolliert.
	 *
	 * Einträge aus dem Unternehmensprofil und zusammengeführte Entitäten werden übersprungen.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Entität, mehrere mit Komma (z. B. 12,13,14).
	 *
	 * <decision>
	 * : confirmed oder rejected.
	 *
	 * [--user=<user>]
	 * : Benutzer, in dessen Namen entschieden wird (WP-CLI-Standardoption).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec entities decide 12,13,14 rejected
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function decide( array $args, array $assoc_args ): void {
		// Nachtrag 24.10. (0.9.2): mehrere Entitäten mit Komma, z. B. 12,13,14.
		$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) $args[0] ) ) ) );
		try {
			$out = $this->entities->decide_many( $ids, (string) $args[1], get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( array() === $out['changed'] && array() === $out['unchanged'] ) {
			WP_CLI::error( 'Nichts geändert: Entität nicht gefunden, zusammengeführt oder aus dem Unternehmensprofil (dort pflegen).' );
		}
		$this->plugin->logger->audit(
			'Entität entschieden',
			array(
				'entity_ids' => $out['changed'],
				'decision'   => (string) $args[1],
				'user_id'    => get_current_user_id(),
				'via'        => 'cli',
			)
		);
		$done = array_merge( $out['changed'], $out['unchanged'] );
		sort( $done );
		WP_CLI::success( 'Entität ' . implode( ', ', $done ) . ': ' . $args[1] . ( array() !== $out['skipped'] ? '; übersprungen: ' . implode( ', ', $out['skipped'] ) : '' ) );
	}

	/**
	 * Ändert eine Entität: Name, Typ, Beschreibung, Status, Schreibweisen.
	 *
	 * Ein geänderter Name bleibt als Schreibweise erhalten. Einträge aus dem Unternehmensprofil: nur
	 * Beschreibung und Schreibweisen.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Entität.
	 *
	 * [--name=<name>]
	 * : Neuer Name.
	 *
	 * [--type=<type>]
	 * : organization, brand, place, service_area, service, product, person, competitor oder concept.
	 *
	 * [--description=<text>]
	 * : Beschreibung (leer = löschen).
	 *
	 * [--status=<status>]
	 * : candidate, probable, unresolved, confirmed oder rejected.
	 *
	 * [--add-alias=<alias>]
	 * : Weitere Schreibweise; mehrere mit |.
	 *
	 * [--remove-alias=<alias>]
	 * : Schreibweise entfernen; mehrere mit |.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec entities update 44 --status=rejected
	 *     wp lsec entities update 66 --name=Gehrden --type=place
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function update( array $args, array $assoc_args ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$fields = array();
		foreach ( array( 'name', 'type', 'description', 'status' ) as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$fields[ $key ] = (string) $assoc_args[ $key ];
			}
		}
		if ( isset( $assoc_args['add-alias'] ) || isset( $assoc_args['remove-alias'] ) ) {
			$detail            = $this->entities->detail( $id );
			$aliases           = (array) ( $detail['aliases'] ?? array() );
			$split             = static fn( string $v ): array => array_values( array_filter( array_map( 'trim', explode( '|', $v ) ) ) );
			$remove            = array_map( array( KeywordNormalizer::class, 'normalize' ), $split( (string) ( $assoc_args['remove-alias'] ?? '' ) ) );
			$aliases           = array_filter( $aliases, static fn( string $a ): bool => ! in_array( KeywordNormalizer::normalize( $a ), $remove, true ) );
			$fields['aliases'] = array_merge( array_values( $aliases ), $split( (string) ( $assoc_args['add-alias'] ?? '' ) ) );
		}
		if ( array() === $fields ) {
			WP_CLI::error( 'Nichts zu ändern. Optionen: --name, --type, --description, --status, --add-alias, --remove-alias.' );
		}
		try {
			$changes = $this->entities->update( $id, $fields, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( array() === $changes ) {
			WP_CLI::success( 'Unverändert.' );
			return;
		}
		$this->plugin->logger->audit(
			'Entität bearbeitet',
			array(
				'entity_id' => $id,
				'changes'   => $changes,
				'user_id'   => get_current_user_id(),
				'via'       => 'cli',
			)
		);
		WP_CLI::success( 'Entität ' . $id . ' geändert: ' . implode( ', ', array_keys( $changes ) ) . '.' );
	}

	/**
	 * Löscht eine Entität mit Schreibweisen, Fundstellen und Beziehungen.
	 *
	 * Anders als „abgelehnt“ merkt sich das Plugin den Namen nicht; eine spätere Erkennung kann ihn wieder
	 * vorschlagen. Für Begriffe, die nie wieder auftauchen sollen: update <id> --status=rejected.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Entität.
	 *
	 * [--yes]
	 * : Ohne Rückfrage.
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function delete( array $args, array $assoc_args ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$detail = $this->entities->detail( $id );
		if ( null === $detail ) {
			WP_CLI::error( 'Entität nicht gefunden.' );
		}
		WP_CLI::confirm( sprintf( '„%s“ mit %d Fundstellen löschen?', $detail['canonical_name'], count( (array) $detail['mentions'] ) ), $assoc_args );
		try {
			$out = $this->entities->delete( $id );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->plugin->logger->audit(
			'Entität gelöscht',
			array(
				'entity_id' => $id,
				'name'      => (string) $detail['canonical_name'],
				'deleted'   => $out['deleted'],
				'user_id'   => get_current_user_id(),
				'via'       => 'cli',
			)
		);
		WP_CLI::success( sprintf( 'Gelöscht: %d Entität(en), %d Fundstellen.', $out['deleted'], $out['mentions'] ) );
	}

	/**
	 * Zeigt eine Entität mit Aliassen, Fundstellen und Beziehungen.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Entität.
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
	public function show( array $args, array $assoc_args ): void {
		$detail = $this->entities->detail( (int) $args[0] );
		if ( null === $detail ) {
			WP_CLI::error( 'Entität nicht gefunden.' );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $detail, JSON_UNESCAPED_UNICODE ) );
			return;
		}
		WP_CLI::log( sprintf( '%d %s (%s) · %s · %s', $detail['id'], $detail['canonical_name'], $detail['type'], $detail['status'], $detail['origin'] ) );
		if ( array() !== $detail['aliases'] ) {
			WP_CLI::log( 'Aliasse: ' . implode( ', ', $detail['aliases'] ) );
		}
		foreach ( $detail['merged_from'] as $source ) {
			WP_CLI::log( sprintf( 'Zusammengeführt: %d %s (%s)', $source['id'], $source['canonical_name'], $source['type'] ) );
		}
		foreach ( $detail['relations'] as $relation ) {
			WP_CLI::log( sprintf( 'Beziehung: %s → %s → %s', $relation['source_name'], $relation['relation_type'], $relation['target_name'] ) );
		}
		if ( array() !== $detail['mentions'] ) {
			WP_CLI\Utils\format_items( 'table', $detail['mentions'], array( 'object_id', 'title', 'location', 'excerpt' ) );
		}
	}

	/**
	 * Zeigt Entitäten. Zusammengeführte nur mit --status=merged.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Typ, z. B. place.
	 *
	 * [--status=<status>]
	 * : Status, z. B. unresolved.
	 *
	 * [--search=<text>]
	 * : Teil des Namens oder eines Alias.
	 *
	 * [--min-confidence=<wert>]
	 * : Schwächere Kandidaten ausblenden (0 bis 1). Standard: Einstellung entity_min_confidence; 0 zeigt alle.
	 *
	 * [--limit=<n>]
	 * : Anzahl.
	 * ---
	 * default: 50
	 * ---
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
		$min  = (float) ( $assoc_args['min-confidence'] ?? Settings::get( 'entity_min_confidence' ) );
		$rows = $this->entities->list(
			(string) ( $assoc_args['type'] ?? '' ),
			(string) ( $assoc_args['status'] ?? '' ),
			(int) ( $assoc_args['limit'] ?? 50 ),
			$min > 0 ? $min : null,
			(string) ( $assoc_args['search'] ?? '' )
		);
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'type', 'canonical_name', 'status', 'confidence', 'posts', 'mentions', 'aliases', 'origin', 'merged_into' ) );
	}

	/**
	 * Legt eine Entität von Hand an (Kandidat oder bestätigt).
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : organization, brand, place, service_area, service, product, person, competitor oder concept.
	 *
	 * <name>
	 * : Name.
	 *
	 * [--confirmed]
	 * : Gleich bestätigen.
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function add( array $args, array $assoc_args ): void {
		try {
			$out = $this->entities->add( (string) $args[0], (string) $args[1], empty( $assoc_args['confirmed'] ) ? 'candidate' : 'confirmed', 'manual', get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( ( $out['created'] ? 'Angelegt: ' : 'Schon vorhanden: ' ) . $out['id'] );
	}

	/**
	 * Führt Entitäten zusammen: Die Namen der Quellen werden Aliasse des Ziels, Fundstellen und Beziehungen wandern mit.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : Ziel-Entität.
	 *
	 * <source>...
	 * : Eine oder mehrere Quell-Entitäten.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec entities merge 12 31 44 45
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function merge( array $args, array $assoc_args ): void {
		$target  = (int) array_shift( $args );
		$sources = array_map( 'intval', $args );
		try {
			$out = $this->entities->merge( $target, $sources, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->plugin->logger->audit(
			'Entitäten zusammengeführt',
			array(
				'target'  => $target,
				'sources' => $out['merged'],
				'user_id' => get_current_user_id(),
				'via'     => 'cli',
			)
		);
		WP_CLI::success( sprintf( '%d in %d zusammengeführt: %d neue Aliasse, %d Fundstellen übernommen.', count( $out['merged'] ), $target, $out['aliases'], $out['mentions'] ) );
	}

	/**
	 * Schlägt Schreibweisen der eigenen Firma oder Marke zum Zusammenführen vor.
	 *
	 * Grundlage sind bestätigte Organisationen und Marken, z. B. aus dem Unternehmensprofil.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Alle Vorschläge übernehmen.
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand suggest-merges
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function suggest_merges( array $args, array $assoc_args ): void {
		$suggestions = $this->entities->merge_suggestions();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) && empty( $assoc_args['apply'] ) ) {
			WP_CLI::line( (string) wp_json_encode( $suggestions, JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( array() === $suggestions ) {
			WP_CLI::success( 'Keine Vorschläge. Grundlage sind bestätigte Organisationen und Marken.' );
			return;
		}
		foreach ( $suggestions as $group ) {
			WP_CLI::log( sprintf( 'Ziel %d %s (%s):', $group['target']['id'], $group['target']['name'], $group['target']['type'] ) );
			foreach ( $group['candidates'] as $c ) {
				WP_CLI::log( sprintf( '  %d %s (%s, %s, %d Fundstellen)', $c['id'], $c['name'], $c['type'], $c['status'], $c['mentions'] ) );
			}
			if ( ! empty( $assoc_args['apply'] ) ) {
				$this->merge( array( (string) $group['target']['id'], ...array_map( static fn( array $c ): string => (string) $c['id'], $group['candidates'] ) ), array() );
			}
		}
	}

	/**
	 * Wikidata-Zugang mit Zwischenspeicher.
	 */
	private function wikidata(): WikidataClient {
		return WikidataClient::for_site( $this->plugin->http );
	}
}
