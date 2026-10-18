<?php
/**
 * WP-CLI: Google Search Console.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Integrations\SearchConsole\SearchConsoleClient;
use Lsec\Integrations\SearchConsole\SearchConsoleException;
use Lsec\Integrations\SearchConsole\ServiceAccount;
use Lsec\Research\Keywords\KeywordRepository;
use Lsec\Workflow\RetryableException;
use WP_CLI;

/**
 * Suchanfragen über ein Dienstkonto abrufen (Prüfpunkt M9).
 */
final class GscCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin            $plugin   Plugin.
	 * @param KeywordRepository $keywords Keyword-Speicher.
	 */
	public function __construct( private readonly Plugin $plugin, private readonly KeywordRepository $keywords ) {}

	/**
	 * Pfad der Schlüsseldatei: Konstante LSEC_GSC_CREDENTIALS_FILE, sonst Einstellung gsc_credentials_file,
	 * sonst der Ordner private neben dem Webroot (Plesk).
	 */
	public static function credentials_file(): string {
		if ( defined( 'LSEC_GSC_CREDENTIALS_FILE' ) && '' !== (string) constant( 'LSEC_GSC_CREDENTIALS_FILE' ) ) {
			return (string) constant( 'LSEC_GSC_CREDENTIALS_FILE' );
		}
		$setting = (string) Settings::get( 'gsc_credentials_file' );
		if ( '' !== $setting ) {
			return $setting;
		}
		return dirname( untrailingslashit( ABSPATH ) ) . '/private/gsc-service-account.json';
	}

	/**
	 * Prüft Schlüsseldatei und Anmeldung bei Google und zeigt die freigegebenen Properties.
	 *
	 * ## OPTIONS
	 *
	 * [--save=<property>]
	 * : Property als Einstellung gsc_property speichern, z. B. sc-domain:example.de.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function sites( array $args, array $assoc_args ): void {
		$this->run(
			function ( SearchConsoleClient $client, ServiceAccount $account ) use ( $assoc_args ): void {
				WP_CLI::log( 'Dienstkonto: ' . $account->client_email );
				$sites = $client->sites();
				if ( array() === $sites ) {
					WP_CLI::warning( 'Anmeldung klappt, aber das Dienstkonto sieht noch keine Property. In der Search Console unter Einstellungen → Nutzer und Berechtigungen ' . $account->client_email . ' hinzufügen.' );
					return;
				}
				WP_CLI\Utils\format_items( 'table', $sites, array( 'url', 'permission' ) );
				if ( ! empty( $assoc_args['save'] ) ) {
					$property = (string) $assoc_args['save'];
					if ( ! in_array( $property, array_column( $sites, 'url' ), true ) ) {
						WP_CLI::error( 'Diese Property ist für das Dienstkonto nicht freigegeben.' );
					}
					Settings::set( 'gsc_property', $property );
					WP_CLI::success( 'gsc_property = ' . $property );
				} elseif ( 1 === count( $sites ) && '' === (string) Settings::get( 'gsc_property' ) ) {
					Settings::set( 'gsc_property', $sites[0]['url'] );
					WP_CLI::success( 'Einzige Property übernommen: ' . $sites[0]['url'] );
				}
			}
		);
	}

	/**
	 * Ruft die Suchanfragen der letzten Tage ab und speichert sie als Quelle gsc.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Zeitraum in Tagen, endet drei Tage vor heute (endgültige Daten).
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--country=<country>]
	 * : Land (ISO alpha-3, z. B. deu, aut, che) oder all.
	 *
	 * [--property=<property>]
	 * : Property. Standard: Einstellung gsc_property.
	 *
	 * [--limit=<n>]
	 * : Zeilen in der Anzeige.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--dry-run]
	 * : Nur abrufen und anzeigen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec gsc fetch --dry-run
	 *     wp lsec gsc fetch --days=90
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function fetch( array $args, array $assoc_args ): void {
		$property = (string) ( $assoc_args['property'] ?? Settings::get( 'gsc_property' ) );
		if ( '' === $property ) {
			WP_CLI::error( 'Keine Property festgelegt. Erst: wp lsec gsc sites --save=<Property>' );
		}
		$country = (string) ( $assoc_args['country'] ?? Settings::get( 'gsc_country' ) );
		$country = 'all' === $country ? '' : $country;
		$period  = SearchConsoleClient::period( (int) ( $assoc_args['days'] ?? 28 ), time() );

		$this->run(
			function ( SearchConsoleClient $client ) use ( $property, $country, $period, $assoc_args ): void {
				WP_CLI::log( sprintf( 'Property %s · %s bis %s · Land %s', $property, $period['start'], $period['end'], '' === $country ? 'alle' : $country ) );
				$rows = $client->search_analytics( $property, $period['start'], $period['end'], $country );
				$agg  = SearchConsoleClient::aggregate( $rows );
				WP_CLI::log( sprintf( '%d Zeilen (Suchanfrage × Seite), %d Suchanfragen.', count( $rows ), count( $agg ) ) );

				$table = array();
				foreach ( array_slice( $agg, 0, (int) ( $assoc_args['limit'] ?? 20 ) ) as $row ) {
					$table[] = array(
						'suchanfrage'  => $row['keyword'],
						'klicks'       => $row['clicks'],
						'impressionen' => $row['impressions'],
						'ctr'          => number_format( $row['ctr'] * 100, 1, ',', '' ) . ' %',
						'position'     => number_format( $row['position'], 1, ',', '' ),
						'seite'        => (string) wp_parse_url( $row['page'], PHP_URL_PATH ),
					);
				}
				if ( array() !== $table ) {
					WP_CLI\Utils\format_items( 'table', $table, array( 'suchanfrage', 'klicks', 'impressionen', 'ctr', 'position', 'seite' ) );
				}
				if ( ! empty( $assoc_args['dry-run'] ) ) {
					WP_CLI::success( 'Probelauf, nichts gespeichert.' );
					return;
				}
				$user  = get_current_user_id();
				$saved = $this->keywords->save_gsc( $property, $period, $agg, 'de_DE', $user > 0 ? $user : null );
				WP_CLI::success( sprintf( 'Import %d: %d neue, %d vorhandene Keywords mit Werten aus der Search Console.', $saved['import_id'], $saved['created'], $saved['updated'] ) );
			}
		);
	}

	/**
	 * Erstellt Client und übersetzt Fehler.
	 *
	 * @param callable(SearchConsoleClient, ServiceAccount): void $callback Aktion.
	 */
	private function run( callable $callback ): void {
		try {
			$account = ServiceAccount::from_file( self::credentials_file(), $this->plugin->http );
			$callback( new SearchConsoleClient( $account, $this->plugin->http ), $account );
		} catch ( SearchConsoleException $e ) {
			WP_CLI::error( $e->error_code . ': ' . $this->plugin->logger->redact( $e->getMessage() ) );
		} catch ( RetryableException $e ) {
			WP_CLI::error( 'Vorübergehend (' . $e->error_code . '): ' . $this->plugin->logger->redact( $e->getMessage() ) );
		}
	}
}
