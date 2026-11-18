<?php
/**
 * PSR-4 style autoloader for the Challan\ namespace.
 *
 * @package Challan
 */

namespace Challan;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Maps Challan\Foo\Bar to includes/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register(
			static function ( $class_name ) {
				if ( 0 !== strpos( $class_name, 'Challan\\' ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( 'Challan\\' ) );
				$path     = CHALLAN_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $path ) ) {
					require $path;
				}
			}
		);
	}
}
