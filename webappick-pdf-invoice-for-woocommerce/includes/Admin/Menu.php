<?php
/**
 * Admin menu — mounts the v2 SPA on the legacy menu slug.
 *
 * @package Challan
 */

namespace Challan\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers the Challan admin menu.
 *
 * COMPATIBILITY CONTRACT: the top-level slug `webappick-woo-invoice`,
 * menu title, capability and icon match v1 exactly — bookmarks and
 * third-party deep links keep working.
 */
class Menu {

	const SLUG = 'webappick-woo-invoice';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Register the top-level menu page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Challan', 'webappick-pdf-invoice-for-woocommerce' ),
			__( 'Challan', 'webappick-pdf-invoice-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render_app' ),
			'dashicons-media-spreadsheet',
			55
		);
	}

	/**
	 * SPA mount point. The admin-ui module enqueues the React bundle
	 * targeting #challan-admin-app; until it ships this renders a stub.
	 *
	 * @return void
	 */
	public function render_app() {
		echo '<div id="challan-admin-app"></div>';
	}
}
