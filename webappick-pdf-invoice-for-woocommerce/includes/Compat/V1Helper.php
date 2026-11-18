<?php
/**
 * Stand-in for v1 Pro's helper object.
 *
 * @package Challan
 */

namespace Challan\Compat;

use Challan\Documents\AddressTemplate;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Stand-in for the v1 helper object.
 *
 * The v1 plugin handed its internal helper object (`Woo_Invoice_Helper` /
 * `Woo_Invoice_Pro_Helper`) to custom code: through the
 * `woo_invoice_custom_shipping_label` filter and the `woo_invoice_helper()`
 * / `woo_invoice_pro_helper()` functions. Such code gets this object
 * instead: the same public methods, answered by v2 code. A method v2 has
 * no equivalent for returns '' rather than failing.
 */
class V1Helper {

	/**
	 * WooCommerce countries (v1 exposed it as a property).
	 *
	 * @var \WC_Countries|null
	 */
	public $countries;

	/**
	 * Expose WooCommerce's countries object, as v1 did.
	 */
	public function __construct() {
		$this->countries = function_exists( 'WC' ) ? WC()->countries : null;
	}

	/**
	 * The order date in the document date format.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public function get_formatted_date( $order ) {
		$created = $order instanceof \WC_Order ? $order->get_date_created() : null;
		return $created ? $created->date_i18n( self::date_format() ) : '';
	}

	/**
	 * The stored invoice date (else the order date) in the document format.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public function get_formatted_invoice_date( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$stored = (string) $order->get_meta( 'wpifw_invoice_date', true );
		$date   = '' !== $stored ? strtotime( $stored ) : false;
		return $date ? date_i18n( self::date_format(), $date ) : $this->get_formatted_date( $order );
	}

	/**
	 * The order number as documents print it.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public function get_order_number( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		/** Preserved v1 filter. */
		return (string) apply_filters( 'woo_invoice_order_number', $order->get_order_number(), 'shipping_label', $order );
	}

	/**
	 * Billing / shipping / label address (v1 signature).
	 *
	 * @param \WC_Order   $order    Order.
	 * @param string      $type     'billing', 'shipping' or 'label'.
	 * @param string      $template Document type.
	 * @param string|null $column   Unused (v1 signature).
	 * @return string
	 */
	public function get_address( $order, $type, $template = '', $column = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $column kept for the v1 public signature.
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		if ( 'label' === $template || 'label' === $type ) {
			$label = trim( (string) get_option( 'wpifw_delivery_address_buyer', '' ) );
			return '' !== $label ? AddressTemplate::render( $label, $order ) : (string) $order->get_formatted_shipping_address();
		}
		if ( 'billing' === $type ) {
			return (string) $order->get_formatted_billing_address();
		}

		return (string) $order->get_formatted_shipping_address();
	}

	/**
	 * A price in the order currency.
	 *
	 * @param \WC_Order $order Order.
	 * @param float     $price Amount.
	 * @return string
	 */
	public function format_price( $order, $price ) {
		/** Preserved v1 Pro filter. */
		$price = apply_filters( 'wpifw_incl_or_exl_tax__price', $price, $order );
		return wc_price( (float) $price, array( 'currency' => $order instanceof \WC_Order ? $order->get_currency() : '' ) );
	}

	/**
	 * Company name and details.
	 *
	 * @return string
	 */
	public function get_seller_info() {
		$name    = (string) get_option( 'wpifw_cname', '' );
		$name    = '' !== $name ? $name : get_bloginfo( 'name' );
		$details = (string) get_option( 'wpifw_cdetails', '' );
		return '<b>' . esc_html( stripslashes( $name ) ) . '</b><br>' . nl2br( esc_html( stripslashes( $details ) ) );
	}

	/**
	 * The shipping-label meta lines.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public function get_shipping_label_metas( $order_id ) {
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order && class_exists( '\Challan\Pro\Documents\LabelRenderer' ) ? \Challan\Pro\Documents\LabelRenderer::meta_lines( $order ) : '';
	}

	/**
	 * The order QR payload (ZATCA TLV or the order link).
	 *
	 * @param \WC_Order|null $order Order.
	 * @return string
	 */
	public function get_qr_code( $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		return class_exists( '\Challan\Pro\Documents\HeaderCodes' ) ? (string) \Challan\Pro\Documents\HeaderCodes::qr_content( $order ) : $order->get_checkout_order_received_url();
	}

	/**
	 * Any other v1 helper method: nothing, instead of a fatal error.
	 *
	 * @param string $name      Method.
	 * @param array  $arguments Arguments.
	 * @return string
	 */
	public function __call( $name, $arguments ) {
		return '';
	}

	/**
	 * The document date format.
	 *
	 * @return string
	 */
	private static function date_format() {
		$format = (string) get_option( 'wpifw_date_format', '' );
		return '' !== $format ? $format : 'd/m/Y';
	}
}
