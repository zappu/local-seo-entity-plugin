<?php
/**
 * Einrichtungsassistent (Stufe F, Nachtrag 22.10.).
 *
 * @package Lsec
 */

namespace Lsec\Setup;

use Lsec\Admin\DashboardData;
use Lsec\Content\ContentIndex;
use Lsec\Content\ContentIndexWorkflow;
use Lsec\Content\ContentReader;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\BusinessProfile;
use Lsec\Entities\PlaceResolver;
use Lsec\Entities\ProfileAssistant;
use Lsec\Entities\ServiceArea;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Integrations\Wikidata\WikidataClient;
use Lsec\Production\StyleProfiler;
use Lsec\Production\StyleProfileRepository;
use Lsec\Research\Keywords\KeywordNormalizer;
use Lsec\Research\Keywords\KeywordSyncWorkflow;
use Lsec\Topics\TopicRepository;
use Lsec\Topics\TopicResearchWorkflow;
use Lsec\Topics\TopicSimilarity;

/**
 * Führt Schritt für Schritt durch die Einrichtung und hakt jeden Schritt ab, sobald er erledigt ist.
 * Der Stand wird jedes Mal aus der Website gelesen, nicht gespeichert: Fehlt später etwas (z. B. der
 * Cronjob), ist der Schritt wieder offen.
 *
 * 1. Technik prüfen (Schlüssel, Cronjob, Search Console, Rank Math)
 * 2. KI-Einstellungen mit erprobten Werten
 * 3. Website lesen (Profil aus den eigenen Seiten, Servicegebiet aus der Firmenadresse)
 * 4. Schreibstil (aus einem Freitext oder aus den eigenen Seiten)
 * 5. Alles starten (Index, Entity-Erkennung, Keyword-Abgleich, erste Themenrecherche)
 * 6. Entscheidungen (unsichere Entitäten, Themen freigeben)
 *
 * Nachtrag 23.10. (0.8.1, Test auf der Website einer Zahnarztpraxis): Die KI-Einstellungen stehen vor
 * „Website lesen“, weil das Lesen schon ein Modell braucht.
 */
final class SetupWizard {

	/**
	 * Schritte.
	 */
	public const STEPS = array(
		'technik'        => 'Technik prüfen',
		'ki'             => 'KI-Einstellungen',
		'website'        => 'Website lesen',
		'stil'           => 'Schreibstil',
		'start'          => 'Alles starten',
		'entscheidungen' => 'Entscheidungen',
	);

	/**
	 * Erprobte Werte für OpenRouter (Stufen D und E, 22. und 23.10.2026).
	 */
	public const RECOMMENDED = array(
		'ai_model'           => 'openai/gpt-6-luna',
		'ai_writing_model'   => 'anthropic/claude-sonnet-5.5',
		'ai_review_model'    => 'anthropic/claude-sonnet-5.5',
		'ai_research_model'  => 'perplexity/sonar-pro',
		'ai_web_engine'      => 'standard',
		'ai_web_max_results' => 3,
	);

	/**
	 * Standardradius des Servicegebiets um den Firmensitz.
	 */
	public const DEFAULT_RADIUS_KM = 25.0;

	/**
	 * Stand aller Schritte.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{steps: list<array<string, mixed>>, done: int, total: int}
	 */
	public static function status( Plugin $plugin ): array {
		$data    = DashboardData::collect( $plugin );
		$profile = ( new BusinessProfile() )->get();
		$steps   = array(
			self::technik( $data ),
			self::ki(),
			self::website( $profile ),
			self::stil(),
			self::start( $data, $profile ),
			self::entscheidungen( $data ),
		);
		$done    = count( array_filter( $steps, static fn( array $s ): bool => (bool) $s['done'] ) );
		return array(
			'steps' => $steps,
			'done'  => $done,
			'total' => count( $steps ),
		);
	}

	/**
	 * Schritt 1: Technik.
	 *
	 * @param array<string, mixed> $data Dashboard-Daten.
	 * @return array<string, mixed>
	 */
	private static function technik( array $data ): array {
		$provider = (string) $data['ai']['provider'];
		$key      = 'none' !== (string) $data['ai']['key_source'];
		$items    = array(
			self::item( 'Schlüssel für ' . $provider, $key, $key ? 'hinterlegt (' . $data['ai']['key_source'] . ')' : 'in wp-config.php eintragen', $key ? '' : "define( 'LSEC_" . strtoupper( $provider ) . "_API_KEY', 'hier-den-Schlüssel-einsetzen' );" ),
			self::cron_item( (bool) $data['cron']['ok'] ),
			self::gsc_item( ! empty( $data['gsc']['credentials'] ), (string) $data['gsc']['property'] ),
			self::textfocus_item( 'none' !== (string) $data['textfocus']['key_source'], '' !== (string) $data['textfocus']['lang'] ),
			self::item( 'Rank Math', ! empty( $data['integrations']['rank_math'] ), ! empty( $data['integrations']['rank_math'] ) ? 'aktiv' : 'nicht aktiv; SEO-Angaben landen dann als Vorschlag am Entwurf', '', true ),
			self::item( 'Datenbank', ! empty( $data['plugin']['schema_ok'] ), ! empty( $data['plugin']['schema_ok'] ) ? 'auf dem Stand' : 'wp lsec migrate', '' ),
		);
		return self::step( 'technik', $key && $data['cron']['ok'] && ! empty( $data['plugin']['schema_ok'] ), $items, null, 'Von Hand bleiben der Schlüssel in wp-config.php, der Cronjob in Plesk und die Freigabe des Dienstkontos in der Search Console.' );
	}

	/**
	 * Cronjob: So wie es bei Plesk zuverlässig läuft (Nachtrag 23.10., 0.8.1). Die Aufgabe des WP Toolkits für
	 * wp-cron.php reicht nicht; eine Aufgabe „Befehl ausführen“ scheitert ohne Shell-Zugang des Abonnements.
	 * Zuverlässig ist WP-CLI im Ordner private und eine Aufgabe „PHP-Skript ausführen“ mit vollem Pfad.
	 *
	 * @param bool $ok Cronjob läuft.
	 * @return array{label: string, ok: bool, detail: string, paste: list<string>, optional: bool}
	 */
	private static function cron_item( bool $ok ): array {
		if ( $ok ) {
			return self::item( 'Cronjob alle 5 Minuten', true, 'läuft' );
		}
		$paths = self::cron_paths();
		$paste = array();
		$intro = '';
		if ( ! file_exists( $paths['phar'] ) ) {
			$intro   = 'Zuerst WP-CLI als root in den Ordner private legen (erster Block), dann ';
			$paste[] = 'curl -sSL -o ' . $paths['phar'] . " https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar\nchown --reference=" . $paths['site'] . ' ' . $paths['phar'] . "\nchmod 644 " . $paths['phar'];
		}
		$paste[] = $paths['phar'];
		$paste[] = '--path=' . $paths['site'] . ' lsec cron';
		return self::item(
			'Cronjob alle 5 Minuten',
			false,
			$intro . 'in Plesk unter „Geplante Aufgaben“ des Abonnements eine Aufgabe „PHP-Skript ausführen“ anlegen: Skriptpfad und Argumente wie unten, ohne Leerzeichen davor; Zeitplan Cron-Stil */5 * * * *; Benachrichtigen „Nur Fehler“. Die Aufgabe des WP Toolkits für wp-cron.php allein reicht nicht.',
			$paste
		);
	}

	/**
	 * Pfade für den Cronjob.
	 *
	 * @return array{site: string, phar: string}
	 */
	public static function cron_paths(): array {
		$wordpress = untrailingslashit( ABSPATH );
		return array(
			'site' => $wordpress,
			'phar' => dirname( $wordpress ) . '/private/wp-cli.phar',
		);
	}

	/**
	 * Search Console: Schlüsseldatei, Rechte, Adresse des Dienstkontos, Property.
	 *
	 * @param bool   $readable Schlüsseldatei lesbar.
	 * @param string $property Property.
	 * @return array{label: string, ok: bool, detail: string, paste: list<string>, optional: bool}
	 */
	private static function gsc_item( bool $readable, string $property ): array {
		if ( ! $readable ) {
			return self::item( 'Search Console', false, 'Schlüsseldatei des Dienstkontos fehlt (JSON-Datei im Ordner private neben dem Webroot); empfohlen, nicht zwingend', array(), true );
		}
		$file   = \Lsec\Cli\GscCommand::credentials_file();
		$detail = '' === $property ? 'Schlüsseldatei gefunden (' . basename( $file ) . '). Property festlegen: wp lsec gsc sites' : 'verbunden: ' . $property;
		$paste  = array();
		$open   = false;
		if ( '' === $property ) {
			$email = \Lsec\Cli\GscCommand::client_email( $file );
			if ( '' !== $email ) {
				$detail .= '. Diese Adresse in der Search Console bei der Property als Nutzer („Eingeschränkt“) eintragen:';
				$paste[] = $email;
			}
		}
		$mode = @fileperms( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $mode && 0 !== ( $mode & 0044 ) ) {
			$open    = true;
			$detail .= ' Achtung: Die Schlüsseldatei ist für andere lesbar; nur der Eigentümer sollte sie lesen dürfen:';
			$paste[] = 'chmod 600 ' . $file;
		}
		return self::item( 'Search Console', '' !== $property && ! $open, $detail, $paste, true );
	}

	/**
	 * Textfocus für Suchvolumen und verwandte Keywords (empfohlen). Nachtrag 23.10. (0.8.1): Die zweite
	 * Testseite hatte keinen Schlüssel, der Assistent erwähnte das nicht.
	 *
	 * @param bool $key  Schlüssel vorhanden.
	 * @param bool $lang Sprachcode gespeichert.
	 * @return array{label: string, ok: bool, detail: string, paste: list<string>, optional: bool}
	 */
	private static function textfocus_item( bool $key, bool $lang ): array {
		if ( ! $key ) {
			return self::item( 'Textfocus (Suchvolumen)', false, 'empfohlen: Schlüssel in wp-config.php eintragen; ohne ihn fehlen Suchvolumen und verwandte Keywords', "define( 'LSEC_TEXTFOCUS_API_KEY', 'hier-den-Schlüssel-einsetzen' );", true );
		}
		if ( ! $lang ) {
			return self::item( 'Textfocus (Suchvolumen)', false, 'Schlüssel hinterlegt; Sprachcode noch nicht gespeichert', 'wp lsec textfocus langs --search=german --save=de', true );
		}
		return self::item( 'Textfocus (Suchvolumen)', true, 'eingerichtet', array(), true );
	}

	/**
	 * Schritt 3: Website lesen.
	 *
	 * @param array<string, mixed> $profile Profil.
	 * @return array<string, mixed>
	 */
	private static function website( array $profile ): array {
		$has_org  = '' !== (string) $profile['organization']['name'] && null !== $profile['organization']['id'];
		$area     = (array) $profile['service_area'];
		$has_area = array() !== $area && '' !== (string) ( $area['label'] ?? '' );
		$services = array_values( array_map( static fn( array $s ): string => (string) $s['name'], (array) $profile['services'] ) );
		$missing  = $has_org ? self::services_without_page( $services ) : array();
		$items    = array(
			self::item( 'Unternehmensprofil', $has_org, $has_org ? sprintf( '%s · %d Standorte · %d Leistungen', $profile['organization']['name'], count( (array) $profile['locations'] ), count( $services ) ) : 'noch nicht angelegt' ),
			self::item( 'Servicegebiet', $has_area, $has_area ? (string) $area['label'] . ( (float) ( $area['radius_km'] ?? 0 ) > 0 ? ' und ' . (float) $area['radius_km'] . ' km Umkreis' : '' ) : 'aus der Firmenadresse mit Umkreis' ),
		);
		if ( array() !== $missing ) {
			$items[] = self::item( 'Leistungen ohne eigene Seite', false, implode( ', ', $missing ) . ': ohne Seite, die sie beschreibt – Entwürfe zu diesen Leistungen beschreiben nur allgemeines Fachwissen', array(), true );
		}
		return self::step(
			'website',
			$has_org && $has_area,
			$items,
			array(
				'label' => $has_org ? 'Website erneut lesen und Profil ergänzen' : 'Website lesen',
				'cost'  => 'etwa 0,01–0,03 USD',
			),
			'' === (string) Settings::get( 'ai_model' ) ? 'Zuerst die KI-Einstellungen übernehmen (Schritt 2): Das Lesen braucht ein Modell.' : 'Liest Startseite, Impressum, Kontakt und Leistungsseiten. Vorhandene Angaben bleiben, fehlende werden ergänzt.'
		);
	}

	/**
	 * Schritt 2: KI-Einstellungen.
	 *
	 * @return array<string, mixed>
	 */
	private static function ki(): array {
		$provider = (string) Settings::get( 'ai_provider' );
		$items    = array();
		$open     = false;
		foreach ( self::RECOMMENDED as $key => $value ) {
			$current = Settings::get( $key );
			$ok      = 'openrouter' === $provider ? (string) $current === (string) $value : ( 'ai_model' !== $key || '' !== (string) $current );
			if ( 'ai_model' === $key && '' === (string) $current ) {
				$open = true;
			}
			$items[] = self::item( self::label( $key ), $ok, '' !== (string) $current ? (string) $current : 'leer', '', 'ai_model' !== $key );
		}
		return self::step(
			'ki',
			! $open,
			$items,
			'openrouter' === $provider ? array(
				'label' => 'Erprobte Werte übernehmen',
				'cost'  => 'kostenlos',
			) : null,
			'openrouter' === $provider ? 'Text und Prüfung mit Claude Sonnet (liest sich am natürlichsten), alles andere mit GPT-6 Luna, Recherche mit Perplexity Sonar Pro. Ein Entwurf kostet damit etwa 0,15–0,25 USD.' : 'Die erprobten Werte gelten für OpenRouter. Bei ' . $provider . ' das Hauptmodell in den Einstellungen eintragen.'
		);
	}

	/**
	 * Schritt 4: Schreibstil.
	 *
	 * @return array<string, mixed>
	 */
	private static function stil(): array {
		$active = ( new StyleProfileRepository() )->active();
		$tone   = null === $active ? '' : (string) ( $active['profile']['tone'] ?? '' );
		return self::step(
			'stil',
			null !== $active,
			array( self::item( 'Style Mimic', null !== $active, null === $active ? 'noch kein Profil' : 'Version ' . (int) $active['version'] . ( '' !== $tone ? ': ' . $tone : '' ) ) ),
			array(
				'label' => null === $active ? 'Stil aus den eigenen Seiten ableiten' : 'Stil neu ableiten',
				'cost'  => 'etwa 0,01–0,03 USD',
				'text'  => true,
			),
			'Ohne Text werden die eigenen Seiten mit den meisten Impressionen und genug Text gelesen. Mit Text (z. B. einem Prompt aus einem anderen Schreibwerkzeug) wird dieser in ein Profil übertragen; Vorgaben, die den Regeln des Plugins widersprechen, werden gemeldet und nicht übernommen.'
		);
	}

	/**
	 * Schritt 5: Alles starten.
	 *
	 * @param array<string, mixed> $data    Dashboard-Daten.
	 * @param array<string, mixed> $profile Profil.
	 * @return array<string, mixed>
	 */
	private static function start( array $data, array $profile ): array {
		$index     = (array) $data['content']['index'];
		$indexed   = (int) ( $index['indexed'] ?? 0 ) > 0;
		$entities  = array_sum( (array) $data['content']['entities'] ) > 0;
		$sync_run  = self::open_run( KeywordSyncWorkflow::TYPE );
		$index_run = self::open_run( ContentIndexWorkflow::TYPE );
		$keywords  = $sync_run > 0 || self::ran( KeywordSyncWorkflow::TYPE );
		$topics    = array_sum( ( new TopicRepository() )->counts() ) > 0;
		$items     = array(
			self::item( 'Inhaltsindex', $indexed || $index_run > 0, sprintf( '%d von %d Inhalten gelesen', (int) ( $index['indexed'] ?? 0 ), (int) ( $index['published'] ?? 0 ) ) . ( $index_run > 0 ? sprintf( '; läuft (Ablauf %d)', $index_run ) : '' ) ),
			self::item( 'Entity-Erkennung', $entities, $entities ? 'Entitäten vorhanden' : 'startet nach dem Index über den Cronjob' ),
			self::item( 'Keyword-Abgleich', $keywords, $sync_run > 0 ? sprintf( 'läuft (Ablauf %d); danach täglich', $sync_run ) : ( $keywords ? 'gelaufen; danach täglich' : 'Search Console und Suchvolumen' ) ),
			self::item( 'Erste Themenrecherche', $topics, $topics ? 'Themen vorhanden' : 'mit Websuche und Faktenprüfung' ),
		);
		$ready     = null !== $profile['organization']['id'];
		return self::step(
			'start',
			$indexed && $keywords && $topics,
			$items,
			$ready ? array(
				'label' => 'Fehlendes starten',
				'cost'  => 'Index und Abgleich kostenlos bzw. wenige Cent, Entity-Erkennung etwa 0,001 USD je Inhalt, Themenrecherche etwa 0,05–0,15 USD',
			) : null,
			$ready ? 'Die Abläufe arbeitet der Cronjob ab; der Stand erscheint im Dashboard.' : 'Zuerst die Website lesen (Schritt 3).'
		);
	}

	/**
	 * Schritt 6: Entscheidungen.
	 *
	 * @param array<string, mixed> $data Dashboard-Daten.
	 * @return array<string, mixed>
	 */
	private static function entscheidungen( array $data ): array {
		$entities = (array) $data['content']['entities'];
		$topics   = ( new TopicRepository() )->counts();
		$open     = (int) ( $topics['proposed'] ?? 0 );
		$approved = (int) ( $topics['approved'] ?? 0 ) + (int) ( $topics['in_production'] ?? 0 ) + (int) ( $topics['produced'] ?? 0 );
		$unsure   = (int) ( $entities['candidate'] ?? 0 );
		return self::step(
			'entscheidungen',
			$approved > 0,
			array(
				self::item( 'Unsichere Entitäten', 0 === $unsure, 0 === $unsure ? 'keine offen' : $unsure . ' Kandidaten prüfen (Seite „Entitäten“)', '', true ),
				self::item( 'Themen freigeben', $approved > 0, sprintf( '%d vorgeschlagen, %d freigegeben oder geschrieben (Seite „Themen“)', $open, $approved ) ),
			),
			null,
			'Freigegebene Themen schreibt der Cronjob als Entwürfe, höchstens so viele am Tag wie eingestellt.'
		);
	}

	/**
	 * Führt die Aktion eines Schritts aus.
	 *
	 * @param Plugin               $plugin  Plugin.
	 * @param string               $step    website, ki, stil oder start.
	 * @param array<string, mixed> $options radius (km), text (Stilvorgabe), force (vorhandene Werte ersetzen).
	 * @return array{messages: list<string>, cost: float}
	 * @throws \InvalidArgumentException Bei unbekanntem Schritt.
	 * @throws \RuntimeException Ohne KI-Modell oder wenn der Schritt scheitert.
	 */
	public static function run( Plugin $plugin, string $step, array $options = array() ): array {
		if ( in_array( $step, array( 'website', 'stil', 'start' ), true ) && '' === (string) Settings::get( 'ai_model' ) ) {
			throw new \RuntimeException( 'Zuerst die KI-Einstellungen übernehmen (Schritt 2).' );
		}
		return match ( $step ) {
			'website' => self::read_website( $plugin, (float) ( $options['radius'] ?? self::DEFAULT_RADIUS_KM ) ),
			'ki'      => self::apply_ai( ! empty( $options['force'] ) ),
			'stil'    => self::build_style( $plugin, (string) ( $options['text'] ?? '' ) ),
			'start'   => self::start_all( $plugin ),
			default   => throw new \InvalidArgumentException( 'Unbekannter Schritt: ' . $step . '. Erlaubt: website, ki, stil, start.' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
	}

	/**
	 * Website lesen, Profil ergänzen, Servicegebiet aus der Firmenadresse.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param float  $radius Umkreis in km.
	 * @return array{messages: list<string>, cost: float}
	 * @throws \RuntimeException Ohne lesbaren Text.
	 */
	private static function read_website( Plugin $plugin, float $radius ): array {
		$messages = array();
		$urls     = self::profile_urls();
		$read     = ( new ProfileAssistant( $plugin->http ) )->read( $urls );
		foreach ( $read['pages'] as $page ) {
			$messages[] = sprintf( 'Gelesen: %s (%d Zeichen)', $page['url'], $page['chars'] );
		}
		if ( '' === $read['text'] ) {
			throw new \RuntimeException( 'Von der Website ließ sich kein Text lesen.' );
		}
		$site   = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) . ' (' . home_url( '/' ) . ')';
		$result = ProfileAssistant::analyze( $plugin->ai->service(), $read['text'], $site );
		$repo   = new BusinessProfile();
		$merged = self::merge( $repo->get(), $result['proposal'] );
		if ( '' === (string) $merged['profile']['organization']['name'] ) {
			throw new \RuntimeException( 'Auf den Seiten wurde kein Firmenname gefunden. Bitte das Profil auf der Seite „Unternehmen“ anlegen.' );
		}
		$repo->save( $merged['profile'], get_current_user_id() );
		$messages = array_merge( $messages, $merged['added'] );
		foreach ( $result['dropped'] as $item ) {
			$messages[] = 'Nicht übernommen (steht so nicht auf den Seiten): ' . $item;
		}
		if ( array() === (array) Settings::get( 'profile_source_urls' ) ) {
			Settings::set( 'profile_source_urls', $urls );
		}

		$area = (array) Settings::get( 'service_area' );
		if ( array() === $area || '' === (string) ( $area['label'] ?? '' ) ) {
			$city = '';
			foreach ( (array) $merged['profile']['locations'] as $location ) {
				if ( '' !== (string) $location['city'] ) {
					$city = (string) $location['city'];
					break;
				}
			}
			if ( '' === $city ) {
				$messages[] = 'Servicegebiet: kein Ort in der Firmenadresse gefunden; bitte auf der Seite „Unternehmen“ festlegen.';
			} else {
				try {
					$wikidata = WikidataClient::for_site( $plugin->http );
					$place    = ( new PlaceResolver( $wikidata ) )->resolve( $city, null, get_locale() );
					$qid      = (string) ( $place['chosen']['id'] ?? '' );
					if ( '' === $qid ) {
						$messages[] = 'Servicegebiet: ' . $city . ' bei Wikidata nicht eindeutig gefunden; bitte auf der Seite „Unternehmen“ wählen.';
					} else {
						$found = ServiceArea::from_wikidata( $wikidata, $qid, $radius );
						Settings::set( 'service_area', $found['area']->to_array() );
						$messages[] = sprintf( 'Servicegebiet: %s und %s km Umkreis (%s)', $found['area']->label, $radius, $qid );
					}
				} catch ( \Throwable $e ) {
					$messages[] = 'Servicegebiet: Wikidata nicht erreichbar (' . $plugin->logger->redact( $e->getMessage() ) . ').';
				}
			}
		}
		$missing = self::services_without_page( array_map( static fn( array $s ): string => (string) $s['name'], (array) $merged['profile']['services'] ) );
		if ( array() !== $missing ) {
			$messages[] = 'Leistungen ohne Seite, die sie beschreibt: ' . implode( ', ', $missing );
		}
		return array(
			'messages' => array_values( $messages ),
			'cost'     => (float) ( $result['cost'] ?? 0.0 ),
		);
	}

	/**
	 * Führt Profil und Vorschlag zusammen: vorhandene Angaben bleiben, leere Felder und neue Einträge kommen dazu.
	 *
	 * @param array<string, mixed> $profile  Profil wie aus BusinessProfile::get().
	 * @param array<string, mixed> $proposal Vorschlag aus ProfileAssistant::verify().
	 * @return array{profile: array<string, mixed>, added: list<string>}
	 */
	public static function merge( array $profile, array $proposal ): array {
		$added = array();
		foreach ( array( 'name', 'description', 'phone', 'email' ) as $field ) {
			$value = trim( (string) ( $proposal['organization'][ $field ] ?? '' ) );
			if ( '' !== $value && '' === trim( (string) ( $profile['organization'][ $field ] ?? '' ) ) ) {
				$profile['organization'][ $field ] = $value;
				$added[]                           = 'Unternehmen, ' . self::label( $field ) . ': ' . $value;
			}
		}
		foreach ( array( 'locations', 'services', 'brands', 'competitors' ) as $list ) {
			$known = array();
			foreach ( (array) ( $profile[ $list ] ?? array() ) as $item ) {
				$known[] = KeywordNormalizer::fold( 'locations' === $list ? (string) ( $item['street'] ?? '' ) . ' ' . (string) ( $item['city'] ?? '' ) : (string) $item['name'] );
			}
			foreach ( (array) ( $proposal[ $list ] ?? array() ) as $item ) {
				$key = KeywordNormalizer::fold( 'locations' === $list ? (string) ( $item['street'] ?? '' ) . ' ' . (string) ( $item['city'] ?? '' ) : (string) ( $item['name'] ?? '' ) );
				if ( '' === trim( $key ) || in_array( $key, $known, true ) ) {
					continue;
				}
				$profile[ $list ][] = array( 'id' => null ) + (array) $item;
				$known[]            = $key;
				$added[]            = self::label( $list ) . ': ' . (string) ( $item['name'] ?? '' );
			}
		}
		return array(
			'profile' => $profile,
			'added'   => $added,
		);
	}

	/**
	 * Erprobte KI-Werte übernehmen; vorhandene Werte nur mit $force.
	 *
	 * @param bool $force Vorhandene Werte ersetzen.
	 * @return array{messages: list<string>, cost: float}
	 * @throws \RuntimeException Bei anderem Anbieter als OpenRouter.
	 */
	private static function apply_ai( bool $force ): array {
		if ( 'openrouter' !== (string) Settings::get( 'ai_provider' ) ) {
			throw new \RuntimeException( 'Die erprobten Werte gelten für OpenRouter (Einstellung ai_provider).' );
		}
		$messages = array();
		foreach ( self::RECOMMENDED as $key => $value ) {
			$current = Settings::get( $key );
			$default = Settings::DEFAULTS[ $key ];
			if ( (string) $current === (string) $value ) {
				continue;
			}
			if ( ! $force && (string) $current !== (string) $default ) {
				$messages[] = sprintf( '%s bleibt %s (ersetzen: „Vorhandene Werte ersetzen“ bzw. --force)', self::label( $key ), (string) $current );
				continue;
			}
			Settings::set( $key, $value );
			$messages[] = sprintf( '%s: %s', self::label( $key ), (string) $value );
		}
		if ( array() === $messages ) {
			$messages[] = 'Alle erprobten Werte sind schon eingestellt.';
		}
		return array(
			'messages' => $messages,
			'cost'     => 0.0,
		);
	}

	/**
	 * Style Mimic aus einem Freitext oder aus den eigenen Seiten.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param string $text   Stilvorgabe; leer = eigene Seiten.
	 * @return array{messages: list<string>, cost: float}
	 * @throws \RuntimeException Ohne geeignete Seiten.
	 */
	private static function build_style( Plugin $plugin, string $text ): array {
		$profiler = new StyleProfiler( $plugin->ai->service() );
		$messages = array();
		if ( '' !== trim( $text ) ) {
			$built = $profiler->from_text( $text );
			foreach ( $built['conflicts'] as $conflict ) {
				$messages[] = 'Nicht übernommen (widerspricht den Regeln des Plugins): ' . $conflict;
			}
		} else {
			$posts = self::style_posts();
			if ( array() === $posts ) {
				throw new \RuntimeException( 'Keine eigenen Seiten mit genug Text gefunden. Bitte eine Stilvorgabe als Text eingeben.' );
			}
			$built = $profiler->build( $posts, array(), array() );
			foreach ( $posts as $id ) {
				$messages[] = 'Gelesen: ' . html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			}
		}
		$version = ( new StyleProfileRepository() )->save( $built['profile'], $built['inputs'], get_current_user_id() );
		array_unshift( $messages, sprintf( 'Style Mimic Version %d gespeichert und aktiv: %s', $version, (string) ( $built['profile']['tone'] ?? '' ) ) );
		return array(
			'messages' => $messages,
			'cost'     => (float) ( $built['cost'] ?? 0.0 ),
		);
	}

	/**
	 * Startet, was noch fehlt: Index (mit Entity-Erkennung), Keyword-Abgleich, erste Themenrecherche.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{messages: list<string>, cost: float}
	 * @throws \RuntimeException Ohne Unternehmensprofil.
	 */
	private static function start_all( Plugin $plugin ): array {
		if ( null === ( new BusinessProfile() )->organization_row() ) {
			throw new \RuntimeException( 'Zuerst die Website lesen (Schritt 3): Ohne Unternehmensprofil gibt es keine Themen.' );
		}
		$messages = array();
		$stats    = ContentIndex::stats();
		$open     = self::open_run( ContentIndexWorkflow::TYPE );
		if ( $open > 0 ) {
			$messages[] = sprintf( 'Inhaltsindex läuft (Ablauf %d).', $open );
		} elseif ( 0 === (int) $stats['indexed'] ) {
			$run        = ContentIndexWorkflow::start( $plugin, false, array() );
			$messages[] = null === $run ? 'Inhaltsindex läuft.' : sprintf( 'Inhaltsindex gestartet (Ablauf %d, %d Inhalte); die Entity-Erkennung folgt.', $run['run_id'], (int) $stats['pending'] );
		} else {
			$messages[] = sprintf( 'Inhaltsindex vorhanden (%d Inhalte).', (int) $stats['indexed'] );
		}
		$open = self::open_run( KeywordSyncWorkflow::TYPE );
		if ( $open > 0 ) {
			$messages[] = sprintf( 'Keyword-Abgleich läuft (Ablauf %d).', $open );
		} elseif ( ! self::ran( KeywordSyncWorkflow::TYPE ) ) {
			$gsc        = '' !== (string) Settings::get( 'gsc_property' );
			$run        = KeywordSyncWorkflow::start(
				$plugin,
				array(
					'gsc'     => $gsc,
					'volumes' => true,
					'reason'  => 'setup',
				)
			);
			$messages[] = null === $run ? 'Keyword-Abgleich läuft.' : sprintf( 'Keyword-Abgleich gestartet (Ablauf %d%s).', $run['run_id'], $gsc ? '' : ', ohne Search Console' );
		} else {
			$messages[] = 'Keyword-Abgleich ist schon gelaufen; er wiederholt sich täglich.';
		}
		if ( 0 === array_sum( ( new TopicRepository() )->counts() ) && ! $plugin->runs->has_active( TopicResearchWorkflow::TYPE ) ) {
			try {
				$run        = TopicResearchWorkflow::start(
					$plugin,
					array(
						'service' => '',
						'region'  => '',
						'force'   => false,
					)
				);
				$messages[] = sprintf( 'Erste Themenrecherche gestartet (Ablauf %d, etwa 0,05–0,15 USD).', $run['run_id'] );
			} catch ( \RuntimeException $e ) {
				$messages[] = 'Themenrecherche: ' . $e->getMessage();
			}
		} else {
			$messages[] = 'Themen oder eine laufende Recherche sind schon vorhanden.';
		}
		return array(
			'messages' => $messages,
			'cost'     => 0.0,
		);
	}

	/**
	 * Seiten für das Profil: Startseite, Impressum, Kontakt, Über uns und Leistungsseiten (höchstens acht).
	 *
	 * @return list<string>
	 */
	public static function profile_urls(): array {
		$saved = array_values( array_filter( array_map( 'strval', (array) Settings::get( 'profile_source_urls' ) ) ) );
		if ( array() !== $saved ) {
			return $saved;
		}
		$urls  = array( home_url( '/' ) );
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		$rank  = array(
			'impressum'  => 1,
			'kontakt'    => 2,
			'ueber-uns'  => 3,
			'über-uns'   => 3,
			'about'      => 3,
			'leistungen' => 4,
			'service'    => 5,
		);
		$found = array();
		foreach ( $pages as $page ) {
			$slug = KeywordNormalizer::fold( $page->post_name . ' ' . $page->post_title );
			foreach ( $rank as $word => $order ) {
				if ( str_contains( $slug, KeywordNormalizer::fold( $word ) ) ) {
					$found[ $order * 1000 + count( $found ) ] = (string) get_permalink( $page );
					break;
				}
			}
		}
		ksort( $found );
		return array_values( array_slice( array_unique( array_merge( $urls, array_values( $found ) ) ), 0, 8 ) );
	}

	/**
	 * Leistungen, zu denen keine eigene Seite und kein Beitrag mit passendem Titel gefunden wird.
	 *
	 * @param string[] $services Leistungen.
	 * @return list<string>
	 */
	public static function services_without_page( array $services ): array {
		global $wpdb;
		$missing = array();
		foreach ( $services as $service ) {
			$stems = self::service_stems( $service );
			$found = false;
			foreach ( $stems as $stem ) {
				// Titel oder Inhalt: Leistungen stehen oft gemeinsam auf einer Seite „Leistungen“ (Zahnarztpraxis, 0.8.1).
				$like  = '%' . $wpdb->esc_like( $stem ) . '%';
				$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page', 'post', 'product') AND ( post_title LIKE %s OR post_content LIKE %s )", $like, $like ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( $found ) {
					break;
				}
			}
			if ( ! $found && array() !== $stems ) {
				$missing[] = $service;
			}
		}
		return $missing;
	}

	/**
	 * Wortstämme einer Leistung für die Suche: die ersten sechs Buchstaben der ersten beiden Leistungswörter,
	 * gefaltet und mit Umlaut („zahnae“ und „zahnä“).
	 *
	 * @param string $service Leistung.
	 * @return list<string>
	 */
	public static function service_stems( string $service ): array {
		$out = array();
		foreach ( array_slice( TopicSimilarity::vocabulary( array( $service ) ), 0, 2 ) as $word ) {
			$stem   = mb_substr( $word, 0, 6 );
			$out[]  = $stem;
			$umlaut = strtr(
				$stem,
				array(
					'ae' => 'ä',
					'oe' => 'ö',
					'ue' => 'ü',
				)
			);
			if ( $umlaut !== $stem ) {
				$out[] = $umlaut;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Eigene Seiten für den Style Mimic: die mit den meisten Impressionen in der Search Console, sonst die
	 * zuletzt bearbeiteten, jeweils mit mindestens 300 Wörtern; höchstens sechs.
	 *
	 * @return list<int>
	 */
	public static function style_posts(): array {
		global $wpdb;
		$metrics = Schema::table( $wpdb->prefix, 'keyword_metrics' );
		$ids     = array();
		$rows    = (array) $wpdb->get_col( "SELECT page_url FROM {$metrics} WHERE page_url <> '' GROUP BY page_url ORDER BY SUM(impressions) DESC LIMIT 30" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $url ) {
			$id = url_to_postid( (string) $url );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$recent = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'modified',
				'fields'         => 'ids',
			)
		);
		$out    = array();
		foreach ( array_unique( array_merge( $ids, array_map( 'intval', $recent ) ) ) as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || (int) get_option( 'page_on_front' ) === $post->ID ) {
				continue;
			}
			$words = str_word_count( ContentReader::read( $post )['text'] );
			if ( $words >= 300 ) {
				$out[] = $post->ID;
			}
			if ( count( $out ) >= 6 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Gab es schon einen erfolgreichen Ablauf dieses Typs?
	 *
	 * @param string $type Ablauftyp.
	 */
	private static function ran( string $type ): bool {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'runs' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s AND status = 'succeeded'", $type ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Offener Ablauf dieses Typs (eingeplant, läuft oder wartet); 0 = keiner. Nachtrag 23.10. (0.8.1): Der
	 * Assistent meldete „schon gelaufen“, obwohl der Abgleich nur eingeplant war.
	 *
	 * @param string $type Ablauftyp.
	 */
	private static function open_run( string $type ): int {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'runs' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND status IN ('queued', 'running', 'waiting') ORDER BY id DESC LIMIT 1", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Punkt eines Schritts.
	 *
	 * @param string          $label    Bezeichnung.
	 * @param bool            $ok       Erledigt.
	 * @param string          $detail   Stand oder Hinweis.
	 * @param string|string[] $paste    Texte zum Einfügen (Befehl, Zeile für wp-config.php), je Eintrag ein Block.
	 * @param bool            $optional Empfohlen, aber für den Schritt nicht nötig.
	 * @return array{label: string, ok: bool, detail: string, paste: list<string>, optional: bool}
	 */
	private static function item( string $label, bool $ok, string $detail, string|array $paste = array(), bool $optional = false ): array {
		return array(
			'label'    => $label,
			'ok'       => $ok,
			'detail'   => $detail,
			'paste'    => array_values( array_filter( array_map( 'strval', (array) $paste ), static fn( string $p ): bool => '' !== $p ) ),
			'optional' => $optional,
		);
	}

	/**
	 * Schritt.
	 *
	 * @param string                           $key    Schlüssel.
	 * @param bool                             $done   Erledigt.
	 * @param array<int, array<string, mixed>> $items  Punkte.
	 * @param array<string, mixed>|null        $action Aktion (label, cost, text).
	 * @param string                           $note   Erläuterung.
	 * @return array<string, mixed>
	 */
	private static function step( string $key, bool $done, array $items, ?array $action, string $note ): array {
		return array(
			'key'    => $key,
			'title'  => self::STEPS[ $key ],
			'done'   => $done,
			'items'  => $items,
			'action' => $action,
			'note'   => $note,
		);
	}

	/**
	 * Deutsche Bezeichnung eines Felds.
	 *
	 * @param string $key Feld.
	 */
	private static function label( string $key ): string {
		return array(
			'ai_model'           => 'Hauptmodell',
			'ai_writing_model'   => 'Schreibmodell',
			'ai_review_model'    => 'Prüfmodell',
			'ai_research_model'  => 'Recherchemodell',
			'ai_web_engine'      => 'Suchmaschine',
			'ai_web_max_results' => 'Treffer der Websuche',
			'name'               => 'Name',
			'description'        => 'Kurzbeschreibung',
			'phone'              => 'Telefon',
			'email'              => 'E-Mail',
			'locations'          => 'Standort',
			'services'           => 'Leistung',
			'brands'             => 'Marke',
			'competitors'        => 'Wettbewerber',
		)[ $key ] ?? $key;
	}
}
