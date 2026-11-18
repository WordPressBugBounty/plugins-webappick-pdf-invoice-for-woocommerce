<?php
/**
 * Invoice number assignment and retrieval.
 *
 * @package Challan
 */

namespace Challan\Documents;

use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Sequential invoice numbers — exact v1 3.7.81 semantics:
 *
 * - The order meta `wpifw_invoice_no` stores the FINAL FORMATTED STRING
 *   (prefix + number + suffix), not a raw integer. Upgraded sites carry
 *   values like "INV-42/26" that must be returned untouched.
 * - New orders are numbered on `woocommerce_new_order` (priority 999,
 *   after order-number plugins) from the `wpifw_invoice_no` option
 *   counter (then the counter increments). With Challan Pro, orders
 *   still unnumbered are numbered on their next status change (v1 Pro),
 *   and the `woo_invoice_pro_generate_pdf_for_order_statuses` filter
 *   limits numbering to statuses (default: all).
 * - WooCommerce Subscriptions stores also keep `wpifw_invoice_no_{id}`
 *   (v1 Pro), read first — renewal orders copy the plain meta key.
 * - Numbers other invoice plugins stored are reused (v1 Pro).
 * - `wpifw_invoice_number_type` switches the format (v1-pro hooks
 *   semantics: date macros applied on every path). The default — and
 *   what an empty/unknown value means — is 'order_number' (user
 *   decision 2026-08-20). 'pre_custom_number_suf' is the sequence path
 *   above, and the counter increments for every new order regardless
 *   of type, exactly as v1 did, so switching types later never replays
 *   sequence numbers.
 * - Orders without meta (created before the plugin) fall back lazily to
 *   prefix + ORDER_ID + suffix with `{{day}}/{{month}}/{{year}}` macros,
 *   persisted on first use.
 */
class InvoiceNumber {

	/**
	 * Settings repository.
	 *
	 * @var Repository
	 */
	private $settings;

	/**
	 * Whether a computed fallback number is saved on the order.
	 *
	 * @var bool
	 */
	private $persist;

	/**
	 * Create the numbering service.
	 *
	 * @param Repository $settings Settings source.
	 * @param bool       $persist  False for previews: show the number an
	 *                             unnumbered order would get, save nothing.
	 */
	public function __construct( Repository $settings, $persist = true ) {
		$this->settings = $settings;
		$this->persist  = (bool) $persist;
	}

	/**
	 * Whether this service saves what it computes (false for previews).
	 *
	 * @return bool
	 */
	public function persists() {
		return $this->persist;
	}

	/**
	 * Hook the new-order assignment.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_new_order', array( $this, 'assign_to_new_order' ), 999, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 999, 4 );
	}

	/**
	 * Whether the order gets an invoice number — v1 Pro's
	 * should_generate_invoice(): statuses from the preserved filter,
	 * 'all' (the default) for every order.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function should_number( \WC_Order $order ) {
		/** Preserved v1 Pro filter. */
		$statuses = (array) apply_filters( 'woo_invoice_pro_generate_pdf_for_order_statuses', array( 'all' ) );

		return in_array( 'all', $statuses, true ) || in_array( $order->get_status(), $statuses, true );
	}

	/**
	 * Number an order still without one (v1 Pro) when its status changes.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order.
	 * @return void
	 */
	public function status_changed( $order_id, $from = '', $to = '', $order = null ) {
		if ( ! challan_is_pro_active() ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && '' === $this->stored( $order ) && $this->should_number( $order ) ) {
			$this->assign_to_new_order( $order->get_id(), $order );
		}
	}

	/**
	 * The stored number: the Subscriptions-safe per-order key first.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function stored( \WC_Order $order ) {
		$keyed = (string) $order->get_meta( 'wpifw_invoice_no_' . $order->get_id(), true );

		return '' !== $keyed ? $keyed : (string) $order->get_meta( 'wpifw_invoice_no', true );
	}

	/**
	 * Save a number on the order (both keys under Subscriptions).
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $number Formatted number.
	 * @return void
	 */
	private function store( \WC_Order $order, $number ) {
		$order->update_meta_data( 'wpifw_invoice_no', $number );
		if ( self::subscriptions_active() ) {
			$order->update_meta_data( 'wpifw_invoice_no_' . $order->get_id(), $number );
		}
	}

	/**
	 * Whether WooCommerce Subscriptions is running.
	 *
	 * @return bool
	 */
	private static function subscriptions_active() {
		return class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_order_contains_subscription' );
	}

	/**
	 * Assign the next sequential number to a newly created order.
	 *
	 * @param int            $order_id Order id.
	 * @param \WC_Order|null $order    Order object WooCommerce is working with, if passed.
	 * @return void
	 */
	public function assign_to_new_order( $order_id, $order = null ) {
		// v1: numbering hooks ran only while invoicing was on.
		if ( ! challan_invoicing_enabled() ) {
			return;
		}

		// The object WooCommerce is working with (checkout, payment and its
		// emails use it next) gets the number in memory too; it is saved
		// through a fresh copy, so WooCommerce's own save is not re-entered.
		$working = $order instanceof \WC_Order && (int) $order->get_id() === (int) $order_id ? $order : null;
		$order   = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// Under Subscriptions a brand-new order's plain key can only be a
		// renewal's copy of the subscription's number — only the per-order
		// key means "already numbered" there.
		$numbered = self::subscriptions_active()
			? (string) $order->get_meta( 'wpifw_invoice_no_' . $order->get_id(), true )
			: $this->stored( $order );
		if ( '' !== $numbered || ! $this->should_number( $order ) ) {
			return;
		}

		$counter = (int) get_option( 'wpifw_invoice_no', 1 );
		if ( $counter < 1 ) {
			$counter = 1;
		}

		$this->store( $order, $this->format_new_number( $order, $counter ) );
		// v1 pro "Invoice Date": the day the invoice number was given.
		if ( '' === (string) $order->get_meta( 'wpifw_invoice_date', true ) ) {
			$order->update_meta_data( 'wpifw_invoice_date', current_time( 'Y-m-d' ) );
		}
		$order->save();

		if ( $working ) {
			$this->mirror_saved_meta( $order, $working );
		}

		update_option( 'wpifw_invoice_no', (string) ( $counter + 1 ) );
	}

	/**
	 * Copy the number rows just saved through $saved onto the order object
	 * WooCommerce is still working with, as already-stored rows (with their
	 * meta ids, no pending change) — otherwise WooCommerce's next save of
	 * that object would insert them a second time.
	 *
	 * @param \WC_Order $saved   Fresh copy the rows were saved through.
	 * @param \WC_Order $working WooCommerce's working object.
	 * @return void
	 */
	private function mirror_saved_meta( \WC_Order $saved, \WC_Order $working ) {
		$rows = array();
		foreach ( $saved->get_meta_data() as $meta ) {
			$rows[ $meta->key ] = $meta;
		}

		foreach ( array( 'wpifw_invoice_no', 'wpifw_invoice_no_' . $saved->get_id(), 'wpifw_invoice_date' ) as $key ) {
			if ( ! isset( $rows[ $key ] ) || '' === (string) $rows[ $key ]->value ) {
				continue;
			}
			$working->update_meta_data( $key, $rows[ $key ]->value );
			foreach ( $working->get_meta_data() as $meta ) {
				if ( $key === $meta->key && empty( $meta->id ) ) {
					$meta->id = $rows[ $key ]->id;
					$meta->apply_changes();
				}
			}
		}
	}

	/**
	 * Format a new order's invoice number per the configured type.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $counter Current sequence counter.
	 * @return string
	 */
	private function format_new_number( \WC_Order $order, $counter ) {
		$type = (string) $this->settings->get( 'wpifw_invoice_number_type' );

		if ( 'pre_custom_number_suf' === $type ) {
			$number = $this->prefix() . $counter . $this->suffix();
		} elseif ( 'pre_order_number_suf' === $type ) {
			$number = $this->prefix() . $order->get_order_number() . $this->suffix();
		} else {
			// 'order_number', empty, or unknown — the default.
			$number = (string) $order->get_order_number();
		}

		return $this->apply_date_macros( $order, $number );
	}

	/**
	 * The order's invoice number as printed — stored value verbatim, or
	 * the v1 lazy fallback (order id + macros) persisted on first use.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public function for_order( \WC_Order $order ) {
		// v1 Pro: no number for orders outside the numbered statuses.
		if ( ! $this->should_number( $order ) ) {
			return '';
		}

		$existing = $this->stored( $order );
		if ( '' !== $existing ) {
			return $existing;
		}

		// The object may predate the number (loaded before it was given,
		// e.g. by checkout or an email): read the order as saved.
		$saved = $order->get_id() ? wc_get_order( $order->get_id() ) : null;
		if ( $saved instanceof \WC_Order && $saved !== $order ) {
			$existing = $this->stored( $saved );
			if ( '' !== $existing ) {
				return $existing;
			}
		}

		// v1 Pro: a number another invoice plugin stored.
		$foreign = $this->foreign_number( $order );
		if ( '' !== $foreign ) {
			return $foreign;
		}

		// v1 Pro's fallback is the bare order id unless a prefixed type
		// is chosen; v1 Free always wrapped it in prefix / suffix.
		$type = (string) $this->settings->get( 'wpifw_invoice_number_type' );
		$bare = challan_is_pro_active() && ! in_array( $type, array( 'pre_custom_number_suf', 'pre_order_number_suf' ), true );

		$formatted = $this->apply_date_macros(
			$order,
			$bare ? (string) $order->get_id() : $this->prefix() . $order->get_id() . $this->suffix()
		);

		// Saved only for real orders while invoicing is on: never on a draft
		// being created (it would keep the number) or when Challan is off.
		if ( $this->persist && challan_invoicing_enabled() && ! in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft', 'draft' ), true ) ) {
			$this->store( $order, $formatted );
			$order->save();
		}

		return $formatted;
	}

	/**
	 * Invoice numbers other invoice plugins stored on the order (v1 Pro
	 * reused them, in this order).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function foreign_number( \WC_Order $order ) {
		if ( ! challan_is_pro_active() ) {
			return '';
		}
		foreach ( array( '_wcpdf_invoice_number', '_bewpi_invoice_pdf_path', 'wf_invoice_number', '_wcdn_invoice_number', '_ywpi_invoice_number' ) as $key ) {
			$value = $order->get_meta( $key, true );
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			if ( '_bewpi_invoice_pdf_path' === $key ) {
				// A file path — the number is the file name.
				return (string) pathinfo( (string) $value, PATHINFO_FILENAME );
			}
			return (string) $value;
		}

		return '';
	}

	/**
	 * Replace {{day}} / {{month}} / {{year}} with the order-creation date.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $number Number string.
	 * @return string
	 */
	private function apply_date_macros( \WC_Order $order, $number ) {
		if ( false === strpos( $number, '{{' ) ) {
			return $number;
		}

		$created = $order->get_date_created();
		$created = $created ? $created : new \WC_DateTime();

		return str_replace(
			array( '{{day}}', '{{month}}', '{{year}}' ),
			array( $created->date( 'd' ), $created->date( 'm' ), $created->date( 'Y' ) ),
			$number
		);
	}

	/**
	 * Configured prefix.
	 *
	 * @return string
	 */
	private function prefix() {
		return (string) $this->settings->get( 'wpifw_invoice_no_prefix' );
	}

	/**
	 * Configured suffix.
	 *
	 * @return string
	 */
	private function suffix() {
		return (string) $this->settings->get( 'wpifw_invoice_no_suffix' );
	}
}
