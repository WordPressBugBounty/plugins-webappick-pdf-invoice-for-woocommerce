<?php
/**
 * Legacy submenu slug compatibility.
 *
 * @package Challan
 */

namespace Challan\Compat;

use Challan\Admin\Menu;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Redirects for the v1 submenus: v1 registered `webappick-woo-docs` and `webappick-woo-pro-vs-free`
 * submenus. Bookmarks to those slugs redirect into the SPA routes.
 */
class LegacyMenus {

	/**
	 * Legacy slug => SPA hash route.
	 *
	 * @var array<string,string>
	 */
	private const REDIRECTS = array(
		'webappick-woo-docs'        => '#/help',
		'webappick-woo-pro-vs-free' => '#/pro',
	);

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Redirect legacy page slugs before any output.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::REDIRECTS[ $page ] ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::SLUG . self::REDIRECTS[ $page ] ) );
		exit;
	}
}
