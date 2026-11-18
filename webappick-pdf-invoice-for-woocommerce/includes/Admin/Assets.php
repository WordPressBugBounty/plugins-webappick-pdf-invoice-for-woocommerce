<?php
/**
 * Admin asset loading for the v2 SPA.
 *
 * @package Challan
 */

namespace Challan\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Enqueues the compiled app ONLY on the Challan admin page — never
 * plugin-wide (a v1 mistake we are not repeating).
 */
class Assets {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue the SPA bundle on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function maybe_enqueue( $hook ) {
		if ( 'toplevel_page_' . Menu::SLUG !== $hook ) {
			return;
		}

		$asset_file = CHALLAN_PATH . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		// Media modal for logo/background pickers.
		wp_enqueue_media();

		wp_enqueue_script(
			'challan-admin-app',
			CHALLAN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Admin screen strings: WordPress.org language packs, or JSON files
		// next to the .mo files in /languages (wp i18n make-json).
		wp_set_script_translations( 'challan-admin-app', 'webappick-pdf-invoice-for-woocommerce', CHALLAN_PATH . 'languages' );

		wp_enqueue_style(
			'challan-admin-app',
			CHALLAN_URL . 'build/index.css',
			array(),
			$asset['version']
		);

		wp_add_inline_script(
			'challan-admin-app',
			'window.challanAdmin = ' . wp_json_encode(
				array(
					'root'  => esc_url_raw( rest_url() ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}
}
