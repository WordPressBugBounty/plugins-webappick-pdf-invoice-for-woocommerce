<?php
/**
 * The v1 global functions and classes, answered by v2.
 *
 * Challan 3.x / Challan Pro 4.x exposed global PHP functions that themes,
 * snippets and other plugins call (invoice numbers in exports and emails,
 * order meta helpers, document HTML…). v2 is namespaced, so without these
 * such code would stop with "Call to undefined function". Each function
 * keeps its v1 name and signature and is defined only when nothing else
 * defines it. Loaded once all plugins are loaded and never beside v1 Pro
 * (see challan_load_v1_compat()), so v1's own definitions cannot clash.
 *
 * Internal v1 functions (installers, notices, font downloads, AJAX
 * handlers) are not provided: they did not serve outside code.
 *
 * @package Challan
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// ── Classes ──────────────────────────────────────────────────────────────

foreach ( array(
	'Woo_Invoice_Template'     => \Challan\Compat\V1Template::class,
	'Woo_Invoice_Pro_Template' => \Challan\Compat\V1Template::class,
	'Woo_Invoice_Helper'       => \Challan\Compat\V1Helper::class,
	'Woo_Invoice_Pro_Helper'   => \Challan\Compat\V1Helper::class,
) as $challan_v1_class => $challan_v2_class ) {
	if ( ! class_exists( $challan_v1_class, false ) ) {
		class_alias( $challan_v2_class, $challan_v1_class );
	}
}
unset( $challan_v1_class, $challan_v2_class );

// ── Invoice and credit-note numbers ──────────────────────────────────────

if ( ! function_exists( 'woo_invoice_get_invoice_number' ) ) {
	/**
	 * The order's invoice number (v1 Free).
	 *
	 * @param int|WC_Order $order_id Order or id.
	 * @return string|false
	 */
	function woo_invoice_get_invoice_number( $order_id ) {
		$order = woo_invoice_get_order( $order_id );
		return $order ? \Challan\Plugin::instance()->invoice_number()->for_order( $order ) : false;
	}
}

if ( ! function_exists( 'woo_invoice_pro_get_invoice_number' ) ) {
	/**
	 * The order's invoice number (v1 Pro): false for orders outside the
	 * numbered statuses (`woo_invoice_pro_generate_pdf_for_order_statuses`).
	 *
	 * @param int|WC_Order $id Order or id.
	 * @return string|false
	 */
	function woo_invoice_pro_get_invoice_number( $id ) {
		$order = woo_invoice_get_order( $id );
		if ( ! $order ) {
			return false;
		}
		$number = \Challan\Plugin::instance()->invoice_number()->for_order( $order );
		return '' !== $number ? $number : false;
	}
}

if ( ! function_exists( 'woo_invoice_pro_get_credit_note_number' ) ) {
	/**
	 * The order's credit-note number as its credit note prints it.
	 *
	 * @param int|WC_Order $id Order or id.
	 * @return string|false
	 */
	function woo_invoice_pro_get_credit_note_number( $id ) {
		$order = woo_invoice_get_order( $id );
		if ( ! $order ) {
			return false;
		}
		// Own credit-note numbering when the store uses it; otherwise the
		// credit note prints the invoice number.
		$number = class_exists( '\Challan\Pro\Documents\CreditNoteNumber' ) ? (string) \Challan\Pro\Documents\CreditNoteNumber::for_order( $order ) : '';
		$number = '' !== $number ? $number : \Challan\Plugin::instance()->invoice_number()->for_order( $order );
		return '' !== $number ? $number : false;
	}
}

if ( ! function_exists( 'woo_invoice_process_date_macros' ) ) {
	/**
	 * Replace {{day}}, {{month}}, {{year}} with the order's creation date.
	 *
	 * @param int    $order_id Order id.
	 * @param string $order_no Number with macros.
	 * @return string
	 */
	function woo_invoice_process_date_macros( $order_id, $order_no ) {
		$order   = woo_invoice_get_order( $order_id );
		$created = $order ? $order->get_date_created() : null;
		$created = $created ? $created : new WC_DateTime();
		return str_replace( array( '{{day}}', '{{month}}', '{{year}}' ), array( $created->date( 'd' ), $created->date( 'm' ), $created->date( 'Y' ) ), (string) $order_no );
	}
}

if ( ! function_exists( 'woo_invoice_pro_process_date_macros' ) ) {
	/**
	 * See woo_invoice_process_date_macros().
	 *
	 * @param int    $order_id Order id.
	 * @param string $order_no Number with macros.
	 * @return string
	 */
	function woo_invoice_pro_process_date_macros( $order_id, $order_no ) {
		return woo_invoice_process_date_macros( $order_id, $order_no );
	}
}

if ( ! function_exists( 'should_generate_invoice' ) ) {
	/**
	 * Whether the order gets invoice numbers and documents (v1 Pro).
	 *
	 * @param WC_Order|array|int $order Order, id, or array with id / order_id.
	 * @return bool
	 */
	function should_generate_invoice( $order ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- v1's exact public name.
		if ( is_array( $order ) ) {
			$order = $order['id'] ?? ( $order['order_id'] ?? 0 );
		}
		$order = woo_invoice_get_order( $order );
		if ( ! $order ) {
			/** Preserved v1 Pro filter. */
			return in_array( 'all', (array) apply_filters( 'woo_invoice_pro_generate_pdf_for_order_statuses', array( 'all' ) ), true );
		}
		return \Challan\Plugin::instance()->invoice_number()->should_number( $order );
	}
}

if ( ! function_exists( 'woo_invoice_pro_pdf_can_generate' ) ) {
	/**
	 * Whether the current user may generate the order's documents (v1 Pro).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	function woo_invoice_pro_pdf_can_generate( $order ) {
		/** Preserved v1 filter. */
		if ( ! apply_filters( 'wpifw_invoice_generate_ignore', true, $order ) ) {
			wp_die( esc_html__( 'Invoice can not generated', 'webappick-pdf-invoice-for-woocommerce' ) );
		}
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$roles = array_merge( array( 'administrator', 'shop_manager' ), (array) get_option( 'wpifw_invoice_can_generate', array() ) );
		foreach ( array_unique( $roles ) as $role ) {
			if ( wc_user_has_role( get_current_user_id(), $role ) ) {
				return true;
			}
		}
		return get_current_user_id() > 0 && get_current_user_id() === (int) $order->get_user_id();
	}
}

// ── Orders and meta ──────────────────────────────────────────────────────

if ( ! function_exists( 'woo_invoice_hpos_enabled' ) ) {
	/**
	 * Whether HPOS order storage is in use.
	 *
	 * @return bool
	 */
	function woo_invoice_hpos_enabled() {
		return class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}

if ( ! function_exists( 'woo_invoice_get_order' ) ) {
	/**
	 * A WC_Order from an order or id; false when invalid.
	 *
	 * @param int|string|WC_Order $order Order or id.
	 * @return WC_Order|false
	 */
	function woo_invoice_get_order( $order ) {
		if ( is_int( $order ) || ( is_string( $order ) && absint( $order ) > 0 ) ) {
			$order = wc_get_order( absint( $order ) );
		}
		return $order instanceof WC_Order ? $order : false;
	}
}

if ( ! function_exists( 'woo_invoice_get_meta' ) ) {
	/**
	 * Order meta (HPOS-safe).
	 *
	 * @param int|WC_Order $order     Order or id.
	 * @param string       $meta_key  Meta key.
	 * @param bool         $is_single Single value.
	 * @return mixed
	 */
	function woo_invoice_get_meta( $order, $meta_key, $is_single = true ) {
		$order = woo_invoice_get_order( $order );
		return $order ? $order->get_meta( $meta_key, $is_single ) : false;
	}
}

if ( ! function_exists( 'woo_invoice_update_meta' ) ) {
	/**
	 * Save order meta (HPOS-safe).
	 *
	 * @param int|WC_Order $order      Order or id.
	 * @param string       $meta_key   Meta key.
	 * @param mixed        $meta_value Value.
	 * @return bool
	 */
	function woo_invoice_update_meta( $order, $meta_key, $meta_value ) {
		$order = woo_invoice_get_order( $order );
		if ( ! $order ) {
			return false;
		}
		$order->update_meta_data( $meta_key, $meta_value );
		$order->save();
		return true;
	}
}

// v1 Pro's copies of the same helpers.
if ( ! function_exists( 'woo_invoice_pro_hpos_enabled' ) ) {
	/**
	 * See woo_invoice_hpos_enabled().
	 *
	 * @return bool
	 */
	function woo_invoice_pro_hpos_enabled() {
		return woo_invoice_hpos_enabled();
	}
}
if ( ! function_exists( 'woo_invoice_pro_get_order' ) ) {
	/**
	 * See woo_invoice_get_order().
	 *
	 * @param int|string|WC_Order $order Order or id.
	 * @return WC_Order|false
	 */
	function woo_invoice_pro_get_order( $order ) {
		return woo_invoice_get_order( $order );
	}
}
if ( ! function_exists( 'woo_invoice_pro_get_meta' ) ) {
	/**
	 * See woo_invoice_get_meta().
	 *
	 * @param int|WC_Order $order     Order or id.
	 * @param string       $meta_key  Meta key.
	 * @param bool         $is_single Single value.
	 * @return mixed
	 */
	function woo_invoice_pro_get_meta( $order, $meta_key, $is_single = true ) {
		return woo_invoice_get_meta( $order, $meta_key, $is_single );
	}
}
if ( ! function_exists( 'woo_invoice_pro_update_meta' ) ) {
	/**
	 * See woo_invoice_update_meta().
	 *
	 * @param int|WC_Order $order      Order or id.
	 * @param string       $meta_key   Meta key.
	 * @param mixed        $meta_value Value.
	 * @return bool
	 */
	function woo_invoice_pro_update_meta( $order, $meta_key, $meta_value ) {
		return woo_invoice_update_meta( $order, $meta_key, $meta_value );
	}
}

if ( ! function_exists( 'get_formatted_date' ) ) {
	/**
	 * The order date in the document date format (v1 Pro).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	function get_formatted_date( $order ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- v1's exact public name.
		return ( new \Challan\Compat\V1Helper() )->get_formatted_date( $order );
	}
}

if ( ! function_exists( 'is_current_status_checked' ) ) {
	/**
	 * Whether a status is in an allow list (v1 Pro).
	 *
	 * @param string   $status        Status.
	 * @param string[] $checked_array Allowed statuses.
	 * @return bool
	 */
	function is_current_status_checked( $status, $checked_array ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- v1's exact public name.
		if ( 'always_allow' === $status || in_array( $status, (array) $checked_array, true ) ) {
			return true;
		}
		foreach ( (array) $checked_array as $value ) {
			if ( false !== strpos( str_replace( '_', '-', (string) $value ), (string) $status ) ) {
				return true;
			}
		}
		return false;
	}
}

// ── Labels, language, RTL ────────────────────────────────────────────────

if ( ! function_exists( 'woo_invoice_filter_label' ) ) {
	/**
	 * A document label, translated and filtered (v1 scheme).
	 *
	 * @param string        $label    Label.
	 * @param WC_Order|null $order    Order.
	 * @param string        $template Document type.
	 * @return string
	 */
	function woo_invoice_filter_label( $label, $order, $template ) {
		/** Preserved v1 filter. */
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- v1 label scheme.
		return apply_filters( 'woo_invoice_filter_template_label', __( $label, 'webappick-pdf-invoice-for-woocommerce' ), $order, $template );
	}
}
if ( ! function_exists( 'woo_invoice_free_filter_label' ) ) {
	/**
	 * A document label, translated and filtered (v1 scheme).
	 *
	 * @param string        $label    Label.
	 * @param WC_Order|null $order    Order.
	 * @param string        $template Document type.
	 * @return string
	 */
	function woo_invoice_free_filter_label( $label, $order, $template ) {
		/** Preserved v1 filter. */
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- v1 label scheme.
		return apply_filters( 'woo_invoice_filter_template_label', __( $label, 'webappick-pdf-invoice-for-woocommerce' ), $order, $template );
	}
}

if ( ! function_exists( 'woo_invoice_switch_language_callback' ) ) {
	/**
	 * Switch WPML / Polylang and the locale to a language (v1 callback).
	 *
	 * @param string $language_code Locale or language code.
	 * @param bool   $cookie_lang   Unused (v1 signature).
	 * @return void
	 */
	function woo_invoice_switch_language_callback( $language_code, $cookie_lang = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $cookie_lang kept for the v1 public signature.
		\Challan\Documents\Language::switch_to( (string) $language_code );
	}
}
if ( ! function_exists( 'woo_invoice_free_switch_language_callback' ) ) {
	/**
	 * Switch WPML / Polylang and the locale to a language (v1 callback).
	 *
	 * @param string $language_code Locale or language code.
	 * @param bool   $cookie_lang   Unused (v1 signature).
	 * @return void
	 */
	function woo_invoice_free_switch_language_callback( $language_code, $cookie_lang = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $cookie_lang kept for the v1 public signature.
		\Challan\Documents\Language::switch_to( (string) $language_code );
	}
}
if ( ! function_exists( 'woo_invoice_restore_language_callback' ) ) {
	/**
	 * Undo the last language switch (v1 callback).
	 *
	 * @return void
	 */
	function woo_invoice_restore_language_callback() {
		\Challan\Documents\Language::restore();
	}
}
if ( ! function_exists( 'woo_invoice_free_restore_language_callback' ) ) {
	/**
	 * Undo the last language switch (v1 callback).
	 *
	 * @return void
	 */
	function woo_invoice_free_restore_language_callback() {
		\Challan\Documents\Language::restore();
	}
}
if ( ! function_exists( 'woo_invoice_reload_text_domain' ) ) {
	/**
	 * Reload Challan's translations (v1 callback).
	 *
	 * @return void
	 */
	function woo_invoice_reload_text_domain() {
		\Challan\Documents\Language::reload_textdomain();
	}
}
if ( ! function_exists( 'woo_invoice_free_reload_text_domain' ) ) {
	/**
	 * Reload Challan's translations (v1 callback).
	 *
	 * @return void
	 */
	function woo_invoice_free_reload_text_domain() {
		\Challan\Documents\Language::reload_textdomain();
	}
}

if ( ! function_exists( 'woo_invoice_get_default_languages' ) ) {
	/**
	 * Document-language choices: locale => native name.
	 *
	 * @return array<string,string>
	 */
	function woo_invoice_get_default_languages() {
		return \Challan\Documents\Locales::all();
	}
}
if ( ! function_exists( 'woo_invoice_pro_get_default_languages' ) ) {
	/**
	 * Document-language choices: locale => native name.
	 *
	 * @return array<string,string>
	 */
	function woo_invoice_pro_get_default_languages() {
		return \Challan\Documents\Locales::all();
	}
}

if ( ! function_exists( 'woo_invoice_is_rtl' ) ) {
	/**
	 * Whether the site language is right-to-left (v1 Free's check).
	 *
	 * @return bool
	 */
	function woo_invoice_is_rtl() {
		$locale = get_locale();
		foreach ( array( 'ar', 'he', 'ur' ) as $rtl ) {
			if ( false !== strpos( $locale, $rtl ) ) {
				return true;
			}
		}
		return false;
	}
}

// ── Document hooks (v1 wrappers returning the hook's output) ─────────────

if ( ! function_exists( 'woo_invoice_before_document' ) ) {
	/**
	 * Run the `woo_invoice_before_document` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_before_document( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_before_document', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_after_document' ) ) {
	/**
	 * Run the `woo_invoice_after_document` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_after_document( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_after_document', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_before_seller_info' ) ) {
	/**
	 * Run the `woo_invoice_before_seller_info` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_before_seller_info( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_before_seller_info', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_after_seller_info' ) ) {
	/**
	 * Run the `woo_invoice_after_seller_info` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_after_seller_info( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_after_seller_info', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_before_billing_address' ) ) {
	/**
	 * Run the `woo_invoice_before_billing_address` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_before_billing_address( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_before_billing_address', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_after_billing_address' ) ) {
	/**
	 * Run the `woo_invoice_after_billing_address` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_after_billing_address( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_after_billing_address', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_before_shipping_address' ) ) {
	/**
	 * Run the `woo_invoice_before_shipping_address` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_before_shipping_address( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_before_shipping_address', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_after_shipping_address' ) ) {
	/**
	 * Run the `woo_invoice_after_shipping_address` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_after_shipping_address( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_after_shipping_address', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_before_order_data' ) ) {
	/**
	 * Run the `woo_invoice_before_order_data` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_before_order_data( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_before_order_data', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_after_order_data' ) ) {
	/**
	 * Run the `woo_invoice_after_order_data` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_after_order_data( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_after_order_data', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_before_product_list' ) ) {
	/**
	 * Run the `woo_invoice_before_product_list` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_before_product_list( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_before_product_list', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_after_product_list' ) ) {
	/**
	 * Run the `woo_invoice_after_product_list` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_after_product_list( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_after_product_list', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_before_item_meta' ) ) {
	/**
	 * Run the `woo_invoice_before_item_meta` action and return its output (v1 wrapper).
	 *
	 * @param mixed $product v1 argument.
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_before_item_meta( $product, $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_before_item_meta', $product, $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_after_item_meta' ) ) {
	/**
	 * Run the `woo_invoice_after_item_meta` action and return its output (v1 wrapper).
	 *
	 * @param mixed $product v1 argument.
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_after_item_meta( $product, $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_after_item_meta', $product, $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_before_customer_notes' ) ) {
	/**
	 * Run the `woo_invoice_before_customer_notes` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_before_customer_notes( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_before_customer_notes', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_after_customer_notes' ) ) {
	/**
	 * Run the `woo_invoice_after_customer_notes` action and return its output (v1 wrapper).
	 *
	 * @param mixed $order v1 argument.
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_after_customer_notes( $order, $template_type ) {
		ob_start();
		do_action( 'woo_invoice_after_customer_notes', $order, $template_type );
		return (string) ob_get_clean();
	}
}
if ( ! function_exists( 'woo_invoice_custom_style' ) ) {
	/**
	 * Run the `woo_invoice_custom_style` action and return its output (v1 wrapper).
	 *
	 * @param mixed $template_type v1 argument.
	 * @return string
	 */
	function woo_invoice_custom_style( $template_type ) {
		ob_start();
		do_action( 'woo_invoice_custom_style', $template_type );
		return (string) ob_get_clean();
	}
}

// ── Document objects and mPDF ────────────────────────────────────────────

if ( ! function_exists( 'woo_invoice_template' ) ) {
	/**
	 * A v1-style template object for the orders (getters return v2 HTML).
	 *
	 * @param int[]|int $order_ids Order ids.
	 * @param string    $template  Document type.
	 * @return \Challan\Compat\V1Template
	 */
	function woo_invoice_template( $order_ids, $template ) {
		return new \Challan\Compat\V1Template( $order_ids, $template );
	}
}

if ( ! function_exists( 'woo_invoice_pro_template' ) ) {
	/**
	 * See woo_invoice_template().
	 *
	 * @param int[]|int $order_ids Order ids.
	 * @param string    $template  Document type.
	 * @param int|null  $vendor    Unused (v1 signature).
	 * @return \Challan\Compat\V1Template
	 */
	function woo_invoice_pro_template( $order_ids, $template, $vendor = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $vendor kept for the v1 public signature.
		return new \Challan\Compat\V1Template( $order_ids, $template );
	}
}

if ( ! function_exists( 'woo_invoice_helper' ) ) {
	/**
	 * A v1-style helper object (methods answered by v2).
	 *
	 * @return \Challan\Compat\V1Helper
	 */
	function woo_invoice_helper() {
		return new \Challan\Compat\V1Helper();
	}
}
if ( ! function_exists( 'woo_invoice_pro_helper' ) ) {
	/**
	 * A v1-style helper object (methods answered by v2).
	 *
	 * @return \Challan\Compat\V1Helper
	 */
	function woo_invoice_pro_helper() {
		return new \Challan\Compat\V1Helper();
	}
}

if ( ! function_exists( 'woo_invoice_mpdf' ) ) {
	/**
	 * An mPDF instance (the bundled library).
	 *
	 * @param array $config mPDF configuration.
	 * @return \Mpdf\Mpdf
	 */
	function woo_invoice_mpdf( $config = array() ) {
		if ( ! class_exists( '\Mpdf\Mpdf' ) && file_exists( CHALLAN_PATH . 'vendor/autoload.php' ) ) {
			require_once CHALLAN_PATH . 'vendor/autoload.php';
		}
		return new \Mpdf\Mpdf( (array) $config );
	}
}
if ( ! function_exists( 'woo_invoice_pro_mpdf' ) ) {
	/**
	 * An mPDF instance (the bundled library).
	 *
	 * @param array $config mPDF configuration.
	 * @return \Mpdf\Mpdf
	 */
	function woo_invoice_pro_mpdf( $config = array() ) {
		if ( ! class_exists( '\Mpdf\Mpdf' ) && file_exists( CHALLAN_PATH . 'vendor/autoload.php' ) ) {
			require_once CHALLAN_PATH . 'vendor/autoload.php';
		}
		return new \Mpdf\Mpdf( (array) $config );
	}
}
