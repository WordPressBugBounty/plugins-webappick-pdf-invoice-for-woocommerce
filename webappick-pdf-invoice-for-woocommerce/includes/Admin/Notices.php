<?php
/**
 * Admin notices.
 *
 * @package Challan
 */

namespace Challan\Admin;

use Challan\Documents\FontDownloader;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Operational notices (v1 set, minus its dead/promo ones):
 * WooCommerce missing (installed-vs-absent variants), uploads not
 * writable, and font-download progress on the Challan page.
 */
class Notices {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render applicable notices.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$this->woocommerce_notice();
		$this->uploads_notice();
		$this->fonts_notice();
	}

	/**
	 * WooCommerce inactive/absent (v1 distinguished the two).
	 *
	 * @return void
	 */
	private function woocommerce_notice() {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}

		$installed = file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' );

		if ( $installed ) {
			$url  = wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=woocommerce/woocommerce.php' ), 'activate-plugin_woocommerce/woocommerce.php' );
			$text = sprintf(
				/* translators: %s: activation link. */
				__( 'Challan requires WooCommerce. WooCommerce is installed but not active — <a href="%s">activate it now</a>.', 'webappick-pdf-invoice-for-woocommerce' ),
				esc_url( $url )
			);
		} else {
			$url  = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ), 'install-plugin_woocommerce' );
			$text = sprintf(
				/* translators: %s: install link. */
				__( 'Challan requires WooCommerce. <a href="%s">Install WooCommerce</a> to start generating invoices.', 'webappick-pdf-invoice-for-woocommerce' ),
				esc_url( $url )
			);
		}

		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $text ) );
	}

	/**
	 * Uploads directory not writable.
	 *
	 * @return void
	 */
	private function uploads_notice() {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) && wp_is_writable( $uploads['basedir'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Challan cannot write to your uploads folder. PDF generation and fonts need wp-content/uploads to be writable.', 'webappick-pdf-invoice-for-woocommerce' )
		);
	}

	/**
	 * Background font download progress — Challan page only.
	 *
	 * @return void
	 */
	private function fonts_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'toplevel_page_' . Menu::SLUG !== $screen->id ) {
			return;
		}

		$status = FontDownloader::status();
		if ( $status['complete'] || 0 === $status['total'] || $status['done'] >= $status['total'] ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: downloaded count, 2: total count. */
					__( 'Challan is downloading additional fonts in the background (%1$d of %2$d done). Documents already work — extended language coverage improves as fonts arrive.', 'webappick-pdf-invoice-for-woocommerce' ),
					(int) $status['done'],
					(int) $status['total']
				)
			)
		);
	}
}
