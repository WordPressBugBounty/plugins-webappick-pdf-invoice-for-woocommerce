<?php
/**
 * Update shim for Challan 3.3–3.7 (v1). Not used by this version.
 *
 * When WordPress updates Challan 3.x to this version, the old 3.x code is
 * still loaded for the rest of that request. One of its
 * `upgrader_process_complete` hooks loads this path (its default font download) after the new
 * files are in place, unless the class was already loaded. Without the file
 * the update request stops with a fatal error, so the update is reported as
 * failed (and a background update can leave the site in maintenance mode).
 *
 * The class does nothing, and loading the file stops the remaining v1
 * update hooks (see v1-update-guard.php): fonts and the PDF library are
 * handled by this version itself.
 *
 * @package Challan
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/v1-update-guard.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile, PEAR.NamingConventions.ValidClassName, WordPress.NamingConventions.ValidFunctionName -- v1's class and method names.
if ( ! class_exists( 'Challan_PluginDefaultFontDownloader', false ) ) {
	/**
	 * Stand-in for the v1 class of the same name.
	 */
	class Challan_PluginDefaultFontDownloader {

		/**
		 * Does nothing (v1 downloaded files here).
		 *
		 * @return void
		 */
		public static function downloadDefaultFonts() {}
	}
}
