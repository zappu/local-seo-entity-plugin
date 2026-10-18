<?php
/**
 * Bootstrap für Unit-Tests ohne WordPress.
 *
 * @package Lsec
 */

$lsec_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $lsec_autoload ) ) {
	require_once $lsec_autoload;
}

// Fallback-Autoloader, falls nur "composer dump-autoload" lief.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefixes = array(
			'Lsec\\Tests\\' => __DIR__ . '/',
			'Lsec\\'        => dirname( __DIR__ ) . '/src/',
		);
		foreach ( $prefixes as $prefix => $dir ) {
			if ( str_starts_with( $class_name, $prefix ) ) {
				$file = $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( file_exists( $file ) ) {
					require_once $file;
				}
				return;
			}
		}
	}
);
