<?php
/**
 * Customer download access rules.
 *
 * @package Challan
 */

namespace Challan\Documents;

use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Decides whether a customer may download the invoice for an order —
 * v1 semantics preserved: the `wpifw_download` master toggle plus the
 * `wpifw_invoice_download_check_list` status allowlist.
 */
final class CustomerAccess {

	/**
	 * Whether the CURRENT user may download this order's invoice.
	 *
	 * @param \WC_Order  $order    Order.
	 * @param Repository $settings Settings.
	 * @return bool
	 */
	public static function allowed( \WC_Order $order, Repository $settings ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( ! challan_invoicing_enabled() || ! $settings->get( 'wpifw_download' ) ) {
			return false;
		}
		if ( (int) $order->get_customer_id() !== get_current_user_id() ) {
			return false;
		}

		return self::status_allowed( $order, $settings );
	}

	/**
	 * Whether a document is held back from the customer: not attached to
	 * emails, no download button, no customer or guest download. The shop
	 * manager can still open it. Challan Pro holds a ZATCA standard (B2B)
	 * invoice until ZATCA has cleared it.
	 *
	 * @since 4.0.0
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $type  Document type id.
	 * @return bool
	 */
	public static function held( \WC_Order $order, $type ) {
		/**
		 * Filter whether a document is held back from the customer.
		 *
		 * @since 4.0.0
		 *
		 * @param bool      $held  Default false.
		 * @param \WC_Order $order Order.
		 * @param string    $type  Document type id (invoice, credit_note, …).
		 */
		return (bool) apply_filters( 'challan_document_held', false, $order, (string) $type );
	}

	/**
	 * Whether the CURRENT user may download this document of the order.
	 * Customers get the invoice (allowlist rules above) and, with Challan
	 * Pro, the credit note of a refunded order (v1 Pro rule).
	 *
	 * @param \WC_Order  $order    Order.
	 * @param string     $type     Document type id.
	 * @param Repository $settings Settings.
	 * @return bool
	 */
	public static function document_allowed( \WC_Order $order, $type, Repository $settings ) {
		if ( self::held( $order, $type ) ) {
			return false;
		}
		if ( 'invoice' === $type ) {
			return self::allowed( $order, $settings );
		}
		if ( 'credit_note' !== $type || ! self::is_refunded( $order ) || ! DocumentTypes::is_available( 'credit_note' ) ) {
			return false;
		}
		if ( ! is_user_logged_in() || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return false;
		}

		return challan_invoicing_enabled() && $settings->get( 'wpifw_download' );
	}

	/**
	 * Challan Pro: whether a guest holding the order key may download the
	 * document — only when a site opts in through v1 Pro's
	 * `wpifw_allow_non_logged_in_users` filter (off by default).
	 *
	 * @param \WC_Order  $order    Order.
	 * @param string     $type     Document type id.
	 * @param Repository $settings Settings.
	 * @return bool
	 */
	public static function guest_allowed( \WC_Order $order, $type, Repository $settings ) {
		if ( self::held( $order, $type ) ) {
			return false;
		}
		if ( ! challan_is_pro_active() || ! apply_filters( 'wpifw_allow_non_logged_in_users', false ) ) {
			return false;
		}
		if ( ! challan_invoicing_enabled() || ! $settings->get( 'wpifw_download' ) ) {
			return false;
		}
		if ( 'invoice' === $type ) {
			return self::status_allowed( $order, $settings );
		}

		return 'credit_note' === $type && self::is_refunded( $order ) && DocumentTypes::is_available( 'credit_note' );
	}

	/**
	 * Whether the order was (partly) refunded — when a credit note exists.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_refunded( \WC_Order $order ) {
		return (float) $order->get_total_refunded() > 0 || 'refunded' === $order->get_status();
	}

	/**
	 * Whether the order's state passes the download allowlist.
	 *
	 * @param \WC_Order  $order    Order.
	 * @param Repository $settings Settings.
	 * @return bool
	 */
	public static function status_allowed( \WC_Order $order, Repository $settings ) {
		$list = $settings->get( 'wpifw_invoice_download_check_list' );
		$list = is_array( $list ) ? $list : array();

		if ( in_array( 'always_allow', $list, true ) ) {
			return true;
		}
		if ( in_array( $order->get_status(), $list, true ) ) {
			return true;
		}
		if ( in_array( 'payment_complete', $list, true ) && $order->is_paid() ) {
			return true;
		}

		return false;
	}
}
