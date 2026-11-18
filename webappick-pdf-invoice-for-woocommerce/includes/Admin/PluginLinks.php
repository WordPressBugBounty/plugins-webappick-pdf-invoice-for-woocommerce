<?php
/**
 * Plugins-page action links.
 *
 * @package Challan
 */

namespace Challan\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Settings / Get Pro / Documentation links on the plugin row (v1 set).
 */
class PluginLinks {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'plugin_action_links_' . plugin_basename( CHALLAN_ROOT_FILE ), array( $this, 'add_links' ) );
	}

	/**
	 * Prepend the Challan links.
	 *
	 * @param array<string,string> $links Existing links.
	 * @return array<string,string>
	 */
	public function add_links( $links ) {
		$challan_links = array(
			'settings' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ),
				esc_html__( 'Settings', 'webappick-pdf-invoice-for-woocommerce' )
			),
			'get_pro'  => sprintf(
				'<a href="%s" target="_blank" rel="noopener" style="color:#93003c;font-weight:600">%s</a>',
				'https://webappick.com/plugin/woocommerce-pdf-invoice-packing-slips/',
				esc_html__( 'Get Pro', 'webappick-pdf-invoice-for-woocommerce' )
			),
			'docs'     => sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				'https://webappick.com/docs/',
				esc_html__( 'Documentation', 'webappick-pdf-invoice-for-woocommerce' )
			),
		);

		return array_merge( $challan_links, $links );
	}
}
