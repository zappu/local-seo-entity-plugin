<?php
/**
 * Werkzeuge, die früher nur per WP-CLI gingen.
 *
 * @package Lsec
 */

namespace Lsec\Admin;

use Lsec\Cli\GscCommand;
use Lsec\Content\LocalCategory;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Infrastructure\Database\Migrator;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Integrations\AI\Json;
use Lsec\Integrations\SearchConsole\SearchConsoleClient;
use Lsec\Integrations\SearchConsole\ServiceAccount;
use Lsec\Integrations\Textfocus\ProviderCallRepository;
use Lsec\Integrations\Textfocus\TextfocusService;
use Lsec\Media\ImageDescriptionWorkflow;
use Lsec\Media\ImageLibrary;
use Lsec\Setup\SetupWizard;

/**
 * Aufgaben für die Knöpfe im Backend (Nachtrag 24.10.2026, 0.11.0, Wunsch von Thomas: keine Befehle in der
 * Shell für den Betrieb). Jede Aufgabe liefert ok, eine Meldung und Einzelheiten; Geheimnisse tauchen darin
 * nie auf.
 */
final class Tools {

	public const WPCLI_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

	/**
	 * Datenbank auf den Stand des Plugins bringen.
	 *
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function migrate(): array {
		$ran     = Migrator::maybe_migrate( true );
		$missing = Migrator::missing_tables();
		if ( array() !== $missing ) {
			return self::result( false, 'Tabellen fehlen nach der Aktualisierung: ' . implode( ', ', $missing ) );
		}
		return self::result( true, ( $ran ? 'Datenbank geprüft und auf dem Stand des Plugins' : 'Datenbank ist aktuell' ) . ', Schemaversion ' . Schema::VERSION . '.' );
	}

	/**
	 * Lädt WP-CLI für den Cronjob in den Ordner private neben dem Webroot und prüft die Prüfsumme.
	 * Ersetzt die Befehle curl, chown und chmod als root: PHP läuft bei Plesk als Benutzer des Abonnements,
	 * dem gehört die Datei dann ohnehin.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function install_wp_cli( Plugin $plugin ): array {
		$target = SetupWizard::cron_paths()['phar'];
		$dir    = dirname( $target );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::result( false, 'Der Ordner ' . $dir . ' fehlt und lässt sich nicht anlegen. Bitte in Plesk im Dateimanager neben httpdocs einen Ordner „private“ anlegen.' );
		}
		if ( ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return self::result( false, 'Der Ordner ' . $dir . ' ist für WordPress nicht beschreibbar.' );
		}
		$hash = $plugin->http->request( 'GET', self::WPCLI_URL . '.sha512', array(), null, 30 );
		$file = $plugin->http->request( 'GET', self::WPCLI_URL, array(), null, 120 );
		if ( 200 !== $hash['status'] || 200 !== $file['status'] ) {
			return self::result( false, 'WP-CLI ließ sich nicht laden (HTTP ' . $file['status'] . '/' . $hash['status'] . ( null !== ( $file['error'] ?? null ) ? ': ' . $file['error'] : '' ) . ').' );
		}
		$expected = strtolower( substr( trim( $hash['body'] ), 0, 128 ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{128}$/', $expected ) || ! hash_equals( $expected, hash( 'sha512', $file['body'] ) ) ) {
			return self::result( false, 'Die Prüfsumme von WP-CLI stimmt nicht; die Datei wurde nicht gespeichert.' );
		}
		$temp = $target . '.part';
		if ( false === file_put_contents( $temp, $file['body'] ) || ! rename( $temp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $temp );
			return self::result( false, 'WP-CLI ließ sich nicht in ' . $dir . ' speichern.' );
		}
		chmod( $target, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		$plugin->logger->audit(
			'WP-CLI geladen',
			array(
				'file'    => $target,
				'user_id' => get_current_user_id(),
			)
		);
		return self::result( true, sprintf( 'WP-CLI gespeichert: %s (%s MB, Prüfsumme in Ordnung). Jetzt die geplante Aufgabe in Plesk anlegen.', $target, number_format( strlen( $file['body'] ) / 1048576, 1, ',', '' ) ) );
	}

	/**
	 * Setzt die Rechte der Schlüsseldatei der Search Console auf 600 (nur der Eigentümer liest sie).
	 *
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function protect_gsc_key(): array {
		$file = GscCommand::credentials_file();
		if ( ! is_file( $file ) ) {
			return self::result( false, 'Keine Schlüsseldatei der Search Console gefunden.' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		if ( ! @chmod( $file, 0600 ) ) {
			return self::result( false, 'Die Rechte ließen sich nicht ändern; die Datei gehört einem anderen Benutzer. In Plesk im Dateimanager bei der Datei „Berechtigungen ändern“ und nur dem Eigentümer Lesen und Schreiben erlauben.' );
		}
		clearstatcache( true, $file );
		return self::result( true, 'Die Schlüsseldatei ' . basename( $file ) . ' ist jetzt nur für den Eigentümer lesbar.' );
	}

	/**
	 * Properties, die das Dienstkonto in der Search Console sehen darf.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{ok: bool, message: string, details: list<string>, options: list<array{value: string, label: string}>, current: string}
	 */
	public static function gsc_sites( Plugin $plugin ): array {
		$current = (string) Settings::get( 'gsc_property' );
		try {
			$account = ServiceAccount::from_file( GscCommand::credentials_file(), $plugin->http );
			$sites   = ( new SearchConsoleClient( $account, $plugin->http ) )->sites();
		} catch ( \Throwable $e ) {
			return self::result( false, 'Search Console: ' . $plugin->logger->redact( $e->getMessage() ) ) + array(
				'options' => array(),
				'current' => $current,
			);
		}
		$options = array_map(
			static fn( array $s ): array => array(
				'value' => (string) $s['url'],
				'label' => (string) $s['url'],
			),
			$sites
		);
		$message = array() === $options
			? 'Die Anmeldung klappt, aber das Dienstkonto sieht noch keine Property. In der Search Console unter Einstellungen → Nutzer und Berechtigungen diese Adresse hinzufügen: ' . $account->client_email
			: count( $options ) . ' Property(s) freigegeben.';
		return self::result( array() !== $options, $message ) + array(
			'options' => $options,
			'current' => $current,
		);
	}

	/**
	 * Speichert die Property, wenn das Dienstkonto sie sehen darf.
	 *
	 * @param Plugin $plugin   Plugin.
	 * @param string $property Property.
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function save_gsc_property( Plugin $plugin, string $property ): array {
		$sites = self::gsc_sites( $plugin );
		if ( ! in_array( $property, array_column( $sites['options'], 'value' ), true ) ) {
			return self::result( false, 'Diese Property ist für das Dienstkonto nicht freigegeben.' );
		}
		Settings::set( 'gsc_property', $property );
		return self::result( true, 'Property gespeichert: ' . $property . '. Der nächste Keyword-Abgleich holt die Suchanfragen.' );
	}

	/**
	 * Sprachcode für Textfocus nach der Sprache der Website festlegen.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function textfocus_lang( Plugin $plugin ): array {
		try {
			$langs = TextfocusService::from_settings( $plugin->http, $plugin->secrets, new ProviderCallRepository(), static fn( int $seconds ) => sleep( $seconds ) )->langs();
		} catch ( \Throwable $e ) {
			return self::result( false, 'Textfocus: ' . $plugin->logger->redact( $e->getMessage() ) );
		}
		$match = self::pick_lang( $langs, get_locale() );
		if ( null === $match ) {
			return self::result( false, 'Textfocus kennt keine passende Sprache für ' . get_locale() . '.' );
		}
		Settings::set( 'textfocus_lang', $match['id'] );
		Settings::set( 'textfocus_locale', '' !== $match['locale'] ? $match['locale'] : TextfocusService::locale_from_code( $match['id'] ) );
		return self::result( true, sprintf( 'Textfocus-Sprache: %s (%s).', $match['label'], $match['id'] ) );
	}

	/**
	 * Passende Sprache zur Locale der Website: gleiche Locale, sonst gleiche Sprache, sonst Deutsch.
	 *
	 * @param list<array{id: string, label: string, locale: string}> $langs  Sprachen von Textfocus.
	 * @param string                                                 $locale Locale der Website, z. B. de_DE.
	 * @return array{id: string, label: string, locale: string}|null
	 */
	public static function pick_lang( array $langs, string $locale ): ?array {
		$locale   = str_replace( '-', '_', $locale );
		$language = strtolower( substr( $locale, 0, 2 ) );
		foreach ( array(
			static fn( array $l ): bool => 0 === strcasecmp( str_replace( '-', '_', $l['locale'] ), $locale ),
			static fn( array $l ): bool => strtolower( $l['id'] ) === $language,
			static fn( array $l ): bool => 'de' === strtolower( $l['id'] ),
		) as $test ) {
			foreach ( $langs as $lang ) {
				if ( $test( $lang ) ) {
					return $lang;
				}
			}
		}
		return null;
	}

	/**
	 * Modelle zur Auswahl: Text (OpenRouter, nur mit strukturierter Ausgabe) oder Bild.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param string $kind   text oder image.
	 * @return array{ok: bool, message: string, details: list<string>, models: list<string>}
	 */
	public static function models( Plugin $plugin, string $kind ): array {
		$cache = get_transient( 'lsec_model_list_' . $kind );
		if ( is_array( $cache ) && array() !== $cache ) {
			return self::result( true, count( $cache ) . ' Modelle.' ) + array( 'models' => array_values( array_map( 'strval', $cache ) ) );
		}
		$models = array();
		try {
			if ( 'image' === $kind ) {
				$current = (string) Settings::get( 'ai_image_model' );
				$catalog = $plugin->ai->image_client( '' !== $current ? $current : 'black-forest-labs/flux-3-image' )->models();
				foreach ( (array) ( $catalog['data'] ?? array() ) as $entry ) {
					$models[] = is_array( $entry ) ? (string) ( $entry['id'] ?? '' ) : '';
				}
			} else {
				if ( 'openrouter' !== (string) Settings::get( 'ai_provider' ) ) {
					return self::result( false, 'Eine Liste gibt es nur bei OpenRouter; beim Anbieter ' . Settings::get( 'ai_provider' ) . ' die Modellkennung von dessen Website eintragen.' ) + array( 'models' => array() );
				}
				$result = $plugin->http->request( 'GET', 'https://openrouter.ai/api/v1/models', array(), null, 30 );
				$data   = Json::decode( $result['body'] );
				foreach ( (array) ( $data['data'] ?? array() ) as $model ) {
					if ( is_array( $model ) && in_array( 'structured_outputs', (array) ( $model['supported_parameters'] ?? array() ), true ) ) {
						$models[] = (string) ( $model['id'] ?? '' );
					}
				}
			}
		} catch ( \Throwable $e ) {
			return self::result( false, 'Modellliste nicht abrufbar: ' . $plugin->logger->redact( $e->getMessage() ) ) + array( 'models' => array() );
		}
		$models = array_values( array_unique( array_filter( $models ) ) );
		sort( $models );
		if ( array() === $models ) {
			return self::result( false, 'Modellliste nicht abrufbar.' ) + array( 'models' => array() );
		}
		set_transient( 'lsec_model_list_' . $kind, $models, 6 * HOUR_IN_SECONDS );
		return self::result( true, count( $models ) . ' Modelle.' ) + array( 'models' => $models );
	}

	/**
	 * Sicherheitsprüfung: Schlüssel im Klartext und Zugriffsschutz der Schnittstellen.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function checks( Plugin $plugin ): array {
		$secrets = SystemChecks::secrets( $plugin );
		$rest    = SystemChecks::rest( false );
		$details = array();
		foreach ( $secrets['rows'] as $row ) {
			$details[] = sprintf( 'Schlüssel %s (%s): %s', $row['geheimnis'], $row['quelle'], 'keine' === $row['fundorte'] ? 'nirgends im Klartext' : 'gefunden in ' . $row['fundorte'] );
		}
		$details[] = 'Geprüfte Protokolle: ' . ( array() === $secrets['logs'] ? 'keine lesbaren' : implode( ', ', array_map( 'basename', $secrets['logs'] ) ) );
		foreach ( $rest['rows'] as $row ) {
			if ( '403' !== $row['besucher'] || '403' !== $row['abonnent'] || '403' === $row['administrator'] ) {
				$details[] = sprintf( 'Schnittstelle %s %s: Besucher %s, Abonnent %s, Administrator %s', $row['methode'], $row['route'], $row['besucher'], $row['abonnent'], $row['administrator'] );
			}
		}
		$details[] = sprintf( '%d Schnittstellen geprüft: %s', count( $rest['rows'] ), 0 === $rest['failed'] ? 'nur für Administratoren erreichbar' : $rest['failed'] . ' Abweichung(en)' );
		$ok        = 0 === $secrets['found'] && 0 === $rest['failed'] && array() !== $rest['rows'];
		$message   = $ok
			? 'Alles in Ordnung: kein Schlüssel im Klartext, alle Schnittstellen geschützt.'
			: ( $secrets['found'] > 0 ? sprintf( '%d Fundstelle(n) von Schlüsseln im Klartext. Die betroffenen Schlüssel beim Anbieter erneuern und die Fundstellen löschen. ', $secrets['found'] ) : '' ) . ( $rest['failed'] > 0 ? 'Mindestens eine Schnittstelle ist nicht richtig geschützt.' : '' );
		return self::result( $ok, trim( $message ), $details );
	}

	/**
	 * Hängengebliebene Abläufe wieder aufnehmen.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function watchdog( Plugin $plugin ): array {
		$resumed = $plugin->watchdog->run( 60 );
		return self::result( true, array() === $resumed ? 'Keine hängengebliebenen Abläufe.' : 'Wieder eingeplant: Ablauf ' . implode( ', ', $resumed ) . '.' );
	}

	/**
	 * Neue Bilder der Mediathek beschreiben lassen (als Ablauf über den Cronjob).
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function describe_images( Plugin $plugin ): array {
		$pending = count( ImageLibrary::pending_ids( ImageDescriptionWorkflow::MAX ) );
		if ( 0 === $pending ) {
			return self::result( true, 'Alle Bilder sind beschrieben.' );
		}
		$run = ImageDescriptionWorkflow::start( $plugin );
		if ( null === $run ) {
			return self::result( true, 'Die Bildbeschreibung läuft schon; der Cronjob arbeitet die Bilder ab.' );
		}
		return self::result( true, sprintf( '%d Bild(er) eingeplant (Ablauf %d). Der Cronjob beschreibt sie in den nächsten Minuten, etwa 0,002 USD je Bild.', $pending, (int) $run['run_id'] ) );
	}

	/**
	 * Vorhandene Ortsseiten der eingestellten Kategorie zuordnen.
	 *
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	public static function local_category(): array {
		if ( '' === trim( (string) Settings::get( 'local_category' ) ) ) {
			return self::result( false, 'Keine Kategorie für Ortsseiten eingestellt.' );
		}
		$posts   = LocalCategory::local_posts();
		$changed = array_values( array_filter( $posts, array( LocalCategory::class, 'assign' ) ) );
		$term    = get_term( LocalCategory::term_id( false ), 'category' );
		$name    = $term instanceof \WP_Term ? $term->name : '';
		if ( array() === $posts ) {
			return self::result( true, 'Kategorie „' . $name . '“ bereit. Noch keine Ortsseite vorhanden; neue Ortsseiten kommen automatisch hinein.' );
		}
		return self::result( true, sprintf( '%d Ortsseite(n) in „%s“, davon %d neu zugeordnet.', count( $posts ), $name, count( $changed ) ) );
	}

	/**
	 * Ergebnis.
	 *
	 * @param bool     $ok      Erfolgreich.
	 * @param string   $message Meldung.
	 * @param string[] $details Einzelheiten.
	 * @return array{ok: bool, message: string, details: list<string>}
	 */
	private static function result( bool $ok, string $message, array $details = array() ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
			'details' => array_values( $details ),
		);
	}
}
