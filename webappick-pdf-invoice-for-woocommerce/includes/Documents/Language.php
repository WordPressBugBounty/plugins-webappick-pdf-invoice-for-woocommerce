<?php
/**
 * Document language (v1 Pro "PDF Document Language").
 *
 * @package Challan
 */

namespace Challan\Documents;

use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Prints each order's documents in the chosen language:
 *
 * - `wpifw_pdf_document_language` = '' / 'wpifw_pdf_site_language': the
 *   site language (nothing switches);
 * - 'wpifw_pdf_order_language': the language the customer checked out in
 *   (order meta `woo_invoice_order_lang`, saved at checkout);
 * - a locale code (de_DE, ar…): always that language.
 *
 * The switch runs through v1's actions (`woo_invoice_switch_language` /
 * `woo_invoice_restore_language`), whose default callbacks switch WPML or
 * Polylang and the WordPress locale, so translated labels follow. Arabic,
 * Persian, Hebrew and Urdu documents print right-to-left (filterable
 * through v1's `woo_invoice_set_rtl`). A Challan Pro feature.
 */
final class Language {

	/**
	 * Order meta holding the checkout locale (v1 key).
	 */
	const ORDER_META = 'woo_invoice_order_lang';

	/**
	 * Locales v1 printed right-to-left.
	 */
	const RTL = array( 'ar', 'ary', 'fa', 'fa_IR', 'he', 'he_IL', 'ur' );

	/**
	 * Undo steps for each active switch (innermost last).
	 *
	 * @var array<int,array<int,callable>>
	 */
	private static $stack = array();

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'save_order_language' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'save_order_language' ) );
		add_action( 'woo_invoice_switch_language', array( __CLASS__, 'switch_to' ) );
		add_action( 'woo_invoice_restore_language', array( __CLASS__, 'restore' ) );
		// Labels follow the switched locale.
		add_action( 'change_locale', array( __CLASS__, 'reload_textdomain' ) );
	}

	/**
	 * Remember the language the customer checked out in.
	 *
	 * @param int|\WC_Order $order Order or id.
	 * @return void
	 */
	public static function save_order_language( $order ) {
		// v1: inactive while invoicing is off.
		if ( ! challan_invoicing_enabled() ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( self::ORDER_META, get_locale() );
			$order->save_meta_data();
		}
	}

	/**
	 * The locale an order's documents print in; '' for the site language.
	 *
	 * @param Repository $settings Settings source.
	 * @param \WC_Order  $order    Order.
	 * @return string
	 */
	public static function locale_for( Repository $settings, \WC_Order $order ) {
		if ( ! challan_is_pro_active() ) {
			return '';
		}
		$choice = (string) $settings->get( 'wpifw_pdf_document_language' );
		if ( '' === $choice || 'wpifw_pdf_site_language' === $choice ) {
			return '';
		}
		if ( 'wpifw_pdf_order_language' === $choice ) {
			return (string) $order->get_meta( self::ORDER_META, true );
		}

		return $choice;
	}

	/**
	 * Whether an order's documents print right-to-left: the RTL setting,
	 * or an RTL document language; through v1's woo_invoice_set_rtl.
	 *
	 * @param Repository $settings Settings source.
	 * @param \WC_Order  $order    Order.
	 * @return bool
	 */
	public static function is_rtl( Repository $settings, \WC_Order $order ) {
		$locale = self::locale_for( $settings, $order );
		$locale = '' !== $locale ? $locale : get_locale();
		$rtl    = $settings->get( 'wpifw_rtl' ) || ( challan_is_pro_active() && in_array( $locale, self::RTL, true ) ) ? 'rtl' : '';

		/** Preserved v1 filter ('rtl' or ''). */
		return 'rtl' === apply_filters( 'woo_invoice_set_rtl', $rtl, $order );
	}

	/**
	 * Switch to an order's document language (no-op for the site
	 * language). Pair every call with end().
	 *
	 * @param Repository $settings Settings source.
	 * @param \WC_Order  $order    Order.
	 * @return bool Whether a switch ran.
	 */
	public static function begin( Repository $settings, \WC_Order $order ) {
		$locale = self::locale_for( $settings, $order );
		if ( '' === $locale ) {
			return false;
		}
		/** Preserved v1 action. */
		do_action( 'woo_invoice_switch_language', $locale );

		return true;
	}

	/**
	 * Back to the language before begin().
	 *
	 * @return void
	 */
	public static function end() {
		/** Preserved v1 action. */
		do_action( 'woo_invoice_restore_language' );
	}

	/**
	 * Default woo_invoice_switch_language callback: WPML / Polylang
	 * language, then the WordPress locale.
	 *
	 * @param string $locale Locale (de_DE) or language code.
	 * @return void
	 */
	public static function switch_to( $locale ) {
		$locale = (string) $locale;
		$undo   = array();
		if ( '' === $locale ) {
			self::$stack[] = $undo;
			return;
		}

		// WPML.
		if ( class_exists( 'SitePress', false ) ) {
			$current = (string) apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's API.
			$code    = self::wpml_code( $locale );
			if ( '' !== $code && $code !== $current ) {
				do_action( 'wpml_switch_language', $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's API.
				$undo[] = static function () use ( $current ) {
					do_action( 'wpml_switch_language', $current ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's API.
				};
			}
		}

		// Polylang.
		if ( function_exists( 'PLL' ) && isset( PLL()->model ) ) {
			$language = PLL()->model->get_language( $locale );
			if ( $language ) {
				$previous      = isset( PLL()->curlang ) ? PLL()->curlang : null;
				PLL()->curlang = $language;
				$undo[]        = static function () use ( $previous ) {
					PLL()->curlang = $previous;
				};
			}
		}

		if ( determine_locale() !== $locale ) {
			if ( switch_to_locale( $locale ) ) {
				$undo[] = 'restore_previous_locale';
			} else {
				// WordPress lacks that language pack: still print Challan's
				// own labels in it from the bundled translations.
				$mo = CHALLAN_PATH . 'languages/webappick-pdf-invoice-for-woocommerce-' . $locale . '.mo';
				if ( is_readable( $mo ) ) {
					unload_textdomain( 'webappick-pdf-invoice-for-woocommerce', true );
					load_textdomain( 'webappick-pdf-invoice-for-woocommerce', $mo, $locale );
					$undo[] = array( __CLASS__, 'reload_textdomain' );
				}
			}
		}

		self::$stack[] = $undo;
	}

	/**
	 * Default woo_invoice_restore_language callback: undo the last switch.
	 *
	 * @return void
	 */
	public static function restore() {
		$undo = array_pop( self::$stack );
		foreach ( array_reverse( (array) $undo ) as $step ) {
			call_user_func( $step );
		}
	}

	/**
	 * Reload Challan's translations for the new locale.
	 *
	 * @return void
	 */
	public static function reload_textdomain() {
		unload_textdomain( 'webappick-pdf-invoice-for-woocommerce', true );
		// Bundled translations (the document may need a language WordPress.org has no pack for).
		load_plugin_textdomain( 'webappick-pdf-invoice-for-woocommerce', false, dirname( plugin_basename( CHALLAN_ROOT_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}

	/**
	 * WPML's language code for a locale ('fr' for fr_FR); codes pass through.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private static function wpml_code( $locale ) {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's API.
		if ( ! is_array( $languages ) ) {
			return '';
		}
		foreach ( $languages as $code => $language ) {
			if ( (string) $code === $locale || ( isset( $language['default_locale'] ) && $language['default_locale'] === $locale ) ) {
				return (string) $code;
			}
		}

		return '';
	}
}
