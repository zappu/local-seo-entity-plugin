<?php
/**
 * Suche nach Geheimnissen im Klartext.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Security;

/**
 * Restprüfung M8: Kein Geheimnis darf im Klartext in Datenbank oder Protokoll stehen.
 * Gesucht wird nach dem ganzen Wert und nach einem Ausschnitt aus der Mitte, damit auch
 * abgeschnittene Kopien auffallen. Ausgegeben werden nur Fundorte und Anzahl, nie der Wert.
 */
final class SecretScan {

	/**
	 * Tabellen außer den Plugin-Tabellen, in denen WordPress und Action Scheduler Texte ablegen.
	 */
	private const WP_TABLES = array( 'options', 'postmeta', 'usermeta', 'actionscheduler_actions', 'actionscheduler_logs' );

	/**
	 * Suchmuster für einen Wert: der ganze Wert und ab 24 Zeichen ein Ausschnitt aus der Mitte.
	 *
	 * @param string $value Geheimnis.
	 * @return list<string>
	 */
	public static function needles( string $value ): array {
		$value = trim( $value );
		if ( strlen( $value ) < 8 ) {
			return array();
		}
		$out = array( $value );
		if ( strlen( $value ) >= 24 ) {
			$out[] = substr( $value, (int) ( strlen( $value ) / 2 ) - 8, 16 );
		}
		return $out;
	}

	/**
	 * Suchmuster für einen privaten Schlüssel (PEM): zwei Zeilen aus der Mitte des Schlüssels.
	 * Der JSON-Export enthält Zeilenumbrüche als \n; gesucht wird deshalb zeilenweise.
	 *
	 * @param string $pem Privater Schlüssel.
	 * @return list<string>
	 */
	public static function pem_needles( string $pem ): array {
		$lines = array_values(
			array_filter(
				array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', $pem ) ),
				static fn( string $l ): bool => '' !== $l && ! str_starts_with( $l, '-----' )
			)
		);
		if ( count( $lines ) < 4 ) {
			return array();
		}
		$middle = (int) ( count( $lines ) / 2 );
		return array( $lines[ $middle - 1 ], $lines[ $middle ] );
	}

	/**
	 * Durchsucht Plugin-Tabellen und WordPress-Tabellen mit Texten.
	 *
	 * @param string[] $needles Suchmuster.
	 * @return list<array{table: string, column: string, rows: int}>
	 */
	public static function scan_database( array $needles ): array {
		global $wpdb;
		if ( array() === $needles ) {
			return array();
		}
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lsec_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( self::WP_TABLES as $name ) {
			$full = $wpdb->prefix . $name;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ) === $full ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$tables[] = $full;
			}
		}

		$hits = array();
		foreach ( $tables as $table ) {
			$table = (string) $table;
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$columns = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
			foreach ( $columns as $column ) {
				$name = (string) ( $column['Field'] ?? '' );
				$type = strtolower( (string) ( $column['Type'] ?? '' ) );
				if ( ! preg_match( '/char|text/', $type ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
					continue;
				}
				$where  = implode( ' OR ', array_fill( 0, count( $needles ), "{$name} LIKE %s" ) );
				$params = array_map( static fn( string $n ): string => '%' . $wpdb->esc_like( $n ) . '%', $needles );
				// Die verschlüsselte Ablage ist kein Fund; sie enthält keinen Klartext.
				$exclude = '';
				if ( $wpdb->prefix . 'options' === $table && 'option_value' === $name ) {
					$exclude  = ' AND option_name NOT LIKE %s';
					$params[] = $wpdb->esc_like( 'lsec_secret_' ) . '%';
				}
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ({$where}){$exclude}", $params ) );
				if ( $count > 0 ) {
					$hits[] = array(
						'table'  => $table,
						'column' => $name,
						'rows'   => $count,
					);
				}
			}
			// phpcs:enable
		}
		return $hits;
	}

	/**
	 * Zählt Zeilen einer Protokolldatei mit Treffern. Liest höchstens die letzten 50 MB.
	 *
	 * @param string   $path    Datei.
	 * @param string[] $needles Suchmuster.
	 * @return int|null Anzahl oder null, wenn die Datei nicht lesbar ist.
	 */
	public static function scan_file( string $path, array $needles ): ?int {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return null;
		}
		$size = (int) filesize( $path );
		if ( $size > 50 * 1024 * 1024 ) {
			fseek( $handle, -50 * 1024 * 1024, SEEK_END );
		}
		$count = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			foreach ( $needles as $needle ) {
				if ( str_contains( $line, $needle ) ) {
					++$count;
					break;
				}
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count;
	}
}
