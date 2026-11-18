<?php
/**
 * Update guard for Challan 3.x (v1). Not used by this version.
 *
 * When WordPress updates Challan 3.x to this version, the old 3.x code stays
 * loaded for the rest of that request, and its `upgrader_process_complete`
 * hooks (includes/upgrader/ in 3.3–3.7) run after the new files are in
 * place. They try to download fonts and the old PDF library, reading files
 * this version doesn't have. The shims in includes/classes/ and
 * includes/configs/ load this guard, which removes the remaining v1 hooks of
 * this plugin so the update request finishes cleanly. Other plugins' hooks
 * are left alone.
 *
 * @package Challan
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'challan_stop_v1_update_hooks' ) ) {
	/**
	 * Remove this plugin's v1 `upgrader_process_complete` callbacks.
	 *
	 * @return void
	 */
	function challan_stop_v1_update_hooks() {
		global $wp_filter;
		if ( empty( $wp_filter['upgrader_process_complete'] ) || ! $wp_filter['upgrader_process_complete'] instanceof WP_Hook ) {
			return;
		}
		$v1_dir = wp_normalize_path( dirname( __DIR__ ) ) . '/upgrader/';
		foreach ( $wp_filter['upgrader_process_complete']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( ! ( $function instanceof Closure ) && ! ( is_string( $function ) && function_exists( $function ) ) ) {
					continue;
				}
				try {
					$file = ( new ReflectionFunction( $function ) )->getFileName();
				} catch ( ReflectionException $e ) {
					continue;
				}
				if ( $file && 0 === strpos( wp_normalize_path( $file ), $v1_dir ) ) {
					remove_action( 'upgrader_process_complete', $function, $priority );
				}
			}
		}
	}
}

challan_stop_v1_update_hooks();
