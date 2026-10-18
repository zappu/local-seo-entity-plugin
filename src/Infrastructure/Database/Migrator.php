<?php
/**
 * Legt Tabellen an und aktualisiert sie.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Database;

/**
 * Führt dbDelta() aus, wenn die gespeicherte Schemaversion veraltet ist.
 */
final class Migrator {

	/**
	 * Migriert, falls nötig.
	 *
	 * @param bool $force Auch bei aktueller Version ausführen.
	 * @return bool True, wenn dbDelta() gelaufen ist.
	 */
	public static function maybe_migrate( bool $force = false ): bool {
		$installed = (int) get_option( Schema::VERSION_OPTION, 0 );
		if ( ! $force && $installed >= Schema::VERSION ) {
			return false;
		}
		self::migrate();
		return true;
	}

	/**
	 * Führt dbDelta() für alle Tabellen aus und speichert die Schemaversion.
	 */
	public static function migrate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$statements = Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate() );
		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}

		update_option( Schema::VERSION_OPTION, Schema::VERSION, true );
	}

	/**
	 * Prüft, welche Tabellen fehlen.
	 *
	 * @return list<string> Fehlende Tabellen (ohne Präfix).
	 */
	public static function missing_tables(): array {
		global $wpdb;

		$missing = array();
		foreach ( Schema::TABLES as $table ) {
			$name  = Schema::table( $wpdb->prefix, $table );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			if ( $found !== $name ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * Löscht alle Plugin-Tabellen. Nur für die Deinstallation.
	 */
	public static function drop_all(): void {
		global $wpdb;

		foreach ( Schema::TABLES as $table ) {
			$name = Schema::table( $wpdb->prefix, $table );
			// Tabellennamen stammen aus einer festen Liste, nicht aus Eingaben.
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		delete_option( Schema::VERSION_OPTION );
	}
}
