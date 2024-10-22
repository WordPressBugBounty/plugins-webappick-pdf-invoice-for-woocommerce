<?php
/**
 * Scripts for showing notices
 *
 * @since      3.3.25
 * @package    Challan_Free
 * @subpackage Challan_Free/notices
 * @author     Anwar <anwar.webappick@gmail.com>
 * @link       https://webappick.com
 */
// If this file is called directly, abort.
if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! function_exists("challan_admin_notice_woocommerce_is_not_installed__error") ) {
    /**
     * Show an admin notice if WooCommerce is not installed or not activated.
     *
     * @return void
     */
    function challan_admin_notice_woocommerce_is_not_installed__error() {
        // Check if WooCommerce is installed and activated
        if ( ! class_exists( 'WooCommerce' ) ) {
            $class = 'notice notice-error';
            $woocommercePluginLink = '<a target="_blank" href="https://wordpress.org/plugins/woocommerce/">WooCommerce</a>';

            // Check if WooCommerce plugin is installed but not activated
            $plugins = get_plugins();
            $woocommerce_installed = isset( $plugins["woocommerce/woocommerce.php"] );

            if ( $woocommerce_installed ) {
                $message = __("<b>Challan</b> plugin has a peer dependency of $woocommercePluginLink plugin but it is not activated. Please activate $woocommercePluginLink plugin to enjoy the awesome features of the <b>Challan</b> plugin.", 'webappick-pdf-invoice-for-woocommerce');
            } else {
                // WooCommerce is neither installed nor activated
                $message = __("<b>Challan</b> plugin has a peer dependency of $woocommercePluginLink plugin but it is not installed. Please install $woocommercePluginLink plugin to enjoy the awesome features of the <b>Challan</b> plugin.", 'webappick-pdf-invoice-for-woocommerce');
            }

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message );
        }
    }

    add_action('admin_notices', 'challan_admin_notice_woocommerce_is_not_installed__error');
}
