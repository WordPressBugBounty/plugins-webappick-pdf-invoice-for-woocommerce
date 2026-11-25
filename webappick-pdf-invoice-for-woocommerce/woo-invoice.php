<?php
/**
 * Challan – PDF Invoice & Packing Slip for WooCommerce.
 *
 * @package  Challan
 * Plugin Name:  PDF Invoices & Packing Slips for WooCommerce – Challan Invoice Plugin
 * Plugin URI:   https://webappick.com
 * Description:  WooCommerce PDF invoice generator with automatic invoice email attachment. Create packing slips, shipping labels, credit notes, multilingual.
 * Version:      4.0.2
 * Author:       WebAppick
 * Author URI:   https://webappick.com
 * License:      GPLv2
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  webappick-pdf-invoice-for-woocommerce
 * Domain Path:  /languages
 * Requires Plugins: woocommerce
 * WP Requirement & Test
 * Requires at least: 6.6
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 3.2
 * WC tested up to: 11.2
 **/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'CHALLAN_VERSION' ) ) {
	define( 'CHALLAN_VERSION', '4.0.2' );
}

if ( ! defined( 'CHALLAN_ROOT_FILE' ) ) {
	define( 'CHALLAN_ROOT_FILE', __FILE__ );
}

if ( ! defined( 'CHALLAN_PATH' ) ) {
	define( 'CHALLAN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'CHALLAN_URL' ) ) {
	define( 'CHALLAN_URL', plugin_dir_url( __FILE__ ) );
}

// Declare WooCommerce HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

require_once CHALLAN_PATH . 'includes/Autoloader.php';
\Challan\Autoloader::register();

/**
 * Whether the Challan Pro extension is active.
 *
 * Challan Pro attaches to `challan_pro_active` to unlock pro-flagged
 * settings and document types. UI gating keys off the same signal via
 * the bootstrap payload.
 *
 * @return bool
 */
function challan_is_pro_active() {
	/**
	 * Filter whether Challan Pro is active. Pro returns true.
	 *
	 * @param bool $active Defaults to false.
	 */
	return (bool) apply_filters( 'challan_pro_active', false );
}

/**
 * Whether "Enable invoicing" is on. When it is off Challan stays out of
 * orders, as v1 did: no numbers, email attachments, order-screen and
 * order-list buttons, bulk actions, customer downloads or checkout fields
 * (the settings screens and previews keep working). v1 Free read an
 * empty value as off; v1 Pro only '0'.
 *
 * @return bool
 */
function challan_invoicing_enabled() {
	$raw = get_option( 'wpifw_invoicing', '1' );
	$on  = challan_is_pro_active() ? '0' !== (string) $raw : ( ! empty( $raw ) && '0' !== (string) $raw );

	/**
	 * Filter whether invoicing is on.
	 *
	 * @param bool $on Whether invoicing is on.
	 */
	return (bool) apply_filters( 'challan_invoicing_enabled', $on );
}

/**
 * Boot the plugin.
 *
 * The v2 modules (settings-api, admin-ui, documents-api) are wired inside
 * \Challan\Plugin. See Development/01-modules/ in the ecosystem repo.
 */
function challan_run() {
	load_plugin_textdomain(
		'webappick-pdf-invoice-for-woocommerce',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	// Challan Pro 4.x (v1) is a standalone plugin that deactivates this one
	// on its next load. Booting beside it would run the upgrade steps and
	// change settings v1 Pro still reads (its template, paper…), so stay
	// idle and steer the admin to Pro 5, as CTX Feed does for its pre-8 Pro:
	// a one-click update when WordPress lists one (the licence is active),
	// else the WebAppick account downloads page.
	if ( defined( 'CHALLAN_PRO_VERSION' ) && (int) CHALLAN_PRO_VERSION < 5 ) {
		add_action( 'admin_notices', 'challan_legacy_pro_notice' );
		add_action( 'network_admin_notices', 'challan_legacy_pro_notice' );
		return;
	}

	\Challan\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'challan_run' );

/**
 * Notice beside Challan Pro 4.x: Challan 4 waits for Challan Pro 5.
 *
 * @return void
 */
function challan_legacy_pro_notice() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$basename  = defined( 'CHALLAN_PRO_ROOT_FILE' ) ? plugin_basename( CHALLAN_PRO_ROOT_FILE ) : 'webappick-pdf-invoice-for-woocommerce-pro/woo-invoice-pro.php';
	$transient = get_site_transient( 'update_plugins' );
	$listed    = is_object( $transient ) && ! empty( $transient->response[ $basename ] );

	if ( $listed ) {
		$url    = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $basename ) ), 'upgrade-plugin_' . $basename );
		$button = __( 'Update Challan Pro now', 'webappick-pdf-invoice-for-woocommerce' );
		$target = '';
	} else {
		$url    = 'https://webappick.com/my-account/api-downloads/';
		$button = __( 'Download Challan Pro 5', 'webappick-pdf-invoice-for-woocommerce' );
		$target = ' target="_blank" rel="noopener noreferrer"';
	}

	printf(
		'<div class="notice notice-warning"><p>%1$s</p><p><a class="button button-primary" href="%2$s"%3$s>%4$s</a></p></div>',
		wp_kses_post( __( '<strong>Challan 4</strong> needs <strong>Challan Pro 5</strong>. You are running Challan Pro 4, which keeps working on its own until you update it — please update Challan Pro to version 5.', 'webappick-pdf-invoice-for-woocommerce' ) ),
		esc_url( $url ),
		$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string above.
		esc_html( $button )
	);
}

/**
 * Load the v1 global functions and classes for custom code written against
 * Challan 3.x / Pro 4.x (see includes/Compat/v1-functions.php). Loaded once
 * every plugin file is in, and not while Challan Pro 4.x is active — it
 * still defines its own.
 */
function challan_load_v1_compat() {
	if ( defined( 'CHALLAN_PRO_VERSION' ) && (int) CHALLAN_PRO_VERSION < 5 ) {
		return;
	}
	require_once CHALLAN_PATH . 'includes/Compat/v1-functions.php';
}
add_action( 'plugins_loaded', 'challan_load_v1_compat', 1 );

/**
 * Activation: seed v1 defaults on fresh installs, protect the upload
 * dirs, and start the background font-pack download (v1 behavior —
 * fonts only, TLS verified; the PDF engine itself ships in the plugin).
 */
register_activation_hook( __FILE__, array( \Challan\Activation::class, 'activate' ) );

/**
 * Deactivation: stop Challan's scheduled jobs (settings and files stay).
 */
register_deactivation_hook( __FILE__, array( \Challan\Activation::class, 'deactivate' ) );
