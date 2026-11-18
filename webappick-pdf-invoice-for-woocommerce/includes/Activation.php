<?php
/**
 * Activation routines.
 *
 * @package Challan
 */

namespace Challan;

use Challan\Documents\FontDownloader;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Fresh-install seeding (v1 defaults preserved so new installs behave
 * exactly like v1 ones), protected upload directories, and the
 * background font download. Never overwrites existing values — safe on
 * upgraded sites.
 */
final class Activation {

	/**
	 * Run on register_activation_hook.
	 *
	 * @return void
	 */
	public static function activate() {
		// A store without v1 data is a new install: the upgrader's v1
		// migrations (Upgrader::migrate_v1_free_store()) must not touch it.
		if ( false === get_option( 'wpifw_invoice_no' ) && false === get_option( Upgrader::NEW_INSTALL_OPTION ) ) {
			add_option( Upgrader::NEW_INSTALL_OPTION, CHALLAN_VERSION );
			// New stores print the customer note (v1 left it unset: off).
			add_option( 'wpifw_show_order_note', '1' );
		}

		self::seed_defaults();
		self::protect_upload_dirs();
		FontDownloader::schedule();

		if ( false === get_option( 'woo-invoice-activation-time' ) ) {
			update_option( 'woo-invoice-activation-time', time() );
		}
	}

	/**
	 * Seed the v1 activator defaults — add_option only (never clobbers).
	 *
	 * @return void
	 */
	private static function seed_defaults() {
		$defaults = array(
			'wpifw_invoicing'                   => '1',
			'wpifw_order_email'                 => '1',
			'wpifw_download'                    => '1',
			'wpifw_disid'                       => 'SKU',
			'wpifw_invoice_no'                  => '1',
			// New installs start on Simple, the default template (upgraders
			// are moved by Upgrader::migrate_legacy_template()).
			'wpifw_templateid'                  => 'challan-simple',
			'wpifw_currency_code'               => '0',
			'wpifw_payment_method_show'         => '1',
			'wpifw_display_phone'               => '0',
			'wpifw_display_email'               => '0',
			'wpifw_email_attach_check_list'     => array( 'new_order', 'customer_completed_order', 'customer_processing_order', 'customer_refunded_order', 'customer_invoice' ),
			'wpifw_invoice_download_check_list' => array( 'processing', 'completed', 'payment_complete', 'always_allow' ),
		);

		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value );
		}

		// Seller identity from the store (v1 behavior).
		if ( false === get_option( 'wpifw_cname' ) ) {
			add_option( 'wpifw_cname', get_bloginfo( 'name' ) );
		}

		if ( false === get_option( 'wpifw_cdetails' ) && function_exists( 'WC' ) && WC()->countries instanceof \WC_Countries ) {
			$countries = WC()->countries;
			$parts     = array_filter(
				array(
					$countries->get_base_address(),
					$countries->get_base_address_2(),
					trim( $countries->get_base_city() . ' ' . $countries->get_base_postcode() ),
					$countries->get_base_state(),
					isset( $countries->countries[ $countries->get_base_country() ] ) ? $countries->countries[ $countries->get_base_country() ] : $countries->get_base_country(),
				)
			);
			if ( array() !== $parts ) {
				add_option( 'wpifw_cdetails', implode( "\n", $parts ) );
			}
		}

		// Logo from the theme's custom logo (v1 behavior).
		if ( false === get_option( 'wpifw_logo_attachment_image_id' ) ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $logo_id > 0 ) {
				add_option( 'wpifw_logo_attachment_image_id', (string) $logo_id );
			}
		}

		// Delivery-address label template (v1 seeded macro template).
		if ( false === get_option( 'wpifw_delivery_address_buyer' ) ) {
			add_option(
				'wpifw_delivery_address_buyer',
				"{{shipping_first_name}} {{shipping_last_name}}\n{{shipping_company}}\n{{shipping_address_1}}\n{{shipping_address_2}}\n{{shipping_city}} - {{shipping_postcode}}\n{{shipping_state}}\n{{shipping_country}}"
			);
		}
	}

	/**
	 * Unschedule the attachment cleanup and the font download (re-queued
	 * on the next activation or update).
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Documents\EmailAttachments::CLEANUP_HOOK );
		wp_clear_scheduled_hook( Documents\FontDownloader::CRON_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Documents\FontDownloader::CRON_HOOK, array(), Documents\FontDownloader::AS_GROUP );
		}
	}

	/**
	 * .htaccess deny + index.html in the challan upload dirs (v1 used
	 * .htaccess; v2 previously shipped index.html only).
	 *
	 * @return void
	 */
	public static function protect_upload_dirs() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		$base = trailingslashit( $uploads['basedir'] ) . 'challan';

		foreach ( array( $base, "$base/tmp", "$base/attachments", "$base/fonts" ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				continue;
			}
			if ( ! file_exists( "$dir/.htaccess" ) ) {
				file_put_contents( "$dir/.htaccess", "deny from all\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			}
			if ( ! file_exists( "$dir/index.html" ) ) {
				file_put_contents( "$dir/index.html", '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			}
		}
	}
}
