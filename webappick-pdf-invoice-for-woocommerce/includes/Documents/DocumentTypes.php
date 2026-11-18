<?php
/**
 * Document type registry.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registry of generatable document types. Challan Pro registers its own
 * types (credit note, shipping label, order list…) via the
 * `challan_document_types` filter.
 */
final class DocumentTypes {

	/**
	 * All registered document types.
	 *
	 * Shape per type: 'label' (string), 'pro' (bool).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		$types = array(
			'invoice'          => array(
				'label' => __( 'Invoice', 'webappick-pdf-invoice-for-woocommerce' ),
				'pro'   => false,
			),
			'packing_slip'     => array(
				'label' => __( 'Packing Slip', 'webappick-pdf-invoice-for-woocommerce' ),
				'pro'   => false,
			),
			'delivery_address' => array(
				'label' => __( 'Delivery Address', 'webappick-pdf-invoice-for-woocommerce' ),
				'pro'   => false,
			),
		);

		/**
		 * Filter the registered document types.
		 *
		 * Extension point for Challan Pro. Keys are type ids; every entry
		 * needs 'label' and 'pro'. Pro types are only generatable while
		 * `challan_is_pro_active()` is true.
		 *
		 * @param array<string,array<string,mixed>> $types Document types.
		 */
		return apply_filters( 'challan_document_types', $types );
	}

	/**
	 * Whether a type id is registered and currently generatable.
	 *
	 * @param string $type Type id.
	 * @return bool
	 */
	public static function is_available( $type ) {
		$types = self::all();
		if ( ! isset( $types[ $type ] ) ) {
			return false;
		}
		if ( ! empty( $types[ $type ]['pro'] ) && ! challan_is_pro_active() ) {
			return false;
		}
		return true;
	}
}
