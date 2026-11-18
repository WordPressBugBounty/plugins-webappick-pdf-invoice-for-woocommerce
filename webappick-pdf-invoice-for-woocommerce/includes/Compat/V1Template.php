<?php
/**
 * Stand-in for v1's document template objects.
 *
 * @package Challan
 */

namespace Challan\Compat;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Stand-in for the v1 template object.
 *
 * The v1 `woo_invoice_template()` / `woo_invoice_pro_template()` returned a
 * template object (`Woo_Invoice_Template` / `Woo_Invoice_Pro_Template`)
 * whose getters gave a document's HTML. Custom code that built documents
 * that way gets this object: the getters return the v2 document HTML for
 * the same orders. Other v1 methods return '' rather than failing.
 */
class V1Template {

	/**
	 * Order ids.
	 *
	 * @var int[]
	 */
	private $order_ids;

	/**
	 * The v1 document type ('invoice', 'credit_note', 'packing_slip', …).
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Create a template object for the given orders.
	 *
	 * @param int[]|int $order_ids Order ids.
	 * @param string    $type      v1 document type.
	 */
	public function __construct( $order_ids, $type = 'invoice' ) {
		$this->order_ids = array_map( 'absint', (array) $order_ids );
		$this->type      = (string) $type;
	}

	/**
	 * Invoice HTML (or the credit note, when built for one).
	 *
	 * @return string
	 */
	public function get_invoice_template() {
		return $this->render( 'credit_note' === $this->type ? 'credit_note' : 'invoice' );
	}

	/**
	 * Packing slip HTML.
	 *
	 * @return string
	 */
	public function get_packing_template() {
		return $this->render( 'packing_slip' );
	}

	/**
	 * Address labels HTML (v1 Free's delivery address).
	 *
	 * @return string
	 */
	public function get_delivery_address_template() {
		return $this->render( 'delivery_address' );
	}

	/**
	 * Address labels HTML (v1 Pro's shipping label; Labels in v2).
	 *
	 * @return string
	 */
	public function get_shipping_label_template() {
		return $this->render( 'delivery_address' );
	}

	/**
	 * Any other v1 template method: nothing, instead of a fatal error.
	 *
	 * @param string $name      Method.
	 * @param array  $arguments Arguments.
	 * @return string
	 */
	public function __call( $name, $arguments ) {
		return '';
	}

	/**
	 * Render the orders as one document of a type.
	 *
	 * @param string $type v2 document type.
	 * @return string
	 */
	private function render( $type ) {
		$renderers = \Challan\Plugin::instance()->renderers();
		if ( ! isset( $renderers[ $type ] ) ) {
			return '';
		}

		$orders = array();
		foreach ( $this->order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$orders[] = $order;
			}
		}
		if ( array() === $orders ) {
			return '';
		}

		return 1 === count( $orders ) ? $renderers[ $type ]->render( $orders[0] ) : $renderers[ $type ]->render_many( $orders );
	}
}
