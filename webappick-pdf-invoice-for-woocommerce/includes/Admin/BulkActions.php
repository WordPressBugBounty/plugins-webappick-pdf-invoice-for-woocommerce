<?php
/**
 * Order-list bulk actions for document generation.
 *
 * @package Challan
 */

namespace Challan\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * "Make PDF Invoice" / "Make Packing Slip" in the order-list bulk
 * dropdown (v1 action keys preserved), on both the HPOS and legacy
 * screens. v1 "handled" these with client-side JS (its PHP handler was
 * dead code); v2 handles them server-side by redirecting to the
 * authenticated bulk endpoint with the selected ids.
 */
class BulkActions {

	private const ACTIONS = array(
		'wpifw_bulk_invoice'              => 'invoice',
		'wpifw_bulk_invoice_packing_slip' => 'packing_slip',
	);

	/**
	 * Hook in (v1 priority 11).
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_actions' ), 11 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_actions' ), 11 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Add the two document actions to the dropdown.
	 *
	 * @param array<string,string> $bulk_actions Registered actions.
	 * @return array<string,string>
	 */
	public function add_actions( $bulk_actions ) {
		// v1: no bulk document actions while invoicing is off.
		if ( ! challan_invoicing_enabled() ) {
			return $bulk_actions;
		}

		$bulk_actions['wpifw_bulk_invoice']              = __( 'Make PDF Invoice', 'webappick-pdf-invoice-for-woocommerce' );
		$bulk_actions['wpifw_bulk_invoice_packing_slip'] = __( 'Make Packing Slip', 'webappick-pdf-invoice-for-woocommerce' );

		return $bulk_actions;
	}

	/**
	 * Redirect the selection to the merged-document endpoint.
	 *
	 * @param string     $redirect_to Fallback redirect URL.
	 * @param string     $doaction    Selected action key.
	 * @param array<int> $ids         Selected order ids.
	 * @return string
	 */
	public function handle( $redirect_to, $doaction, $ids ) {
		if ( ! isset( self::ACTIONS[ $doaction ] ) || ! current_user_can( 'manage_woocommerce' ) || ! challan_invoicing_enabled() ) {
			return $redirect_to;
		}

		$ids = array_slice( array_filter( array_map( 'absint', (array) $ids ) ), 0, 200 );
		if ( array() === $ids ) {
			return $redirect_to;
		}

		return add_query_arg(
			array(
				'type'     => rawurlencode( self::ACTIONS[ $doaction ] ),
				'ids'      => implode( ',', $ids ),
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			),
			rest_url( 'challan/v1/documents/bulk' )
		);
	}
}
