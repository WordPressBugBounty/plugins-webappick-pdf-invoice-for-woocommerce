<?php
/**
 * Order-details "Challan Invoice" metabox.
 *
 * @package Challan
 */

namespace Challan\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Side metabox on the order edit screen (HPOS and legacy) with
 * download + print buttons per document type — v1 surface preserved,
 * including the metabox id, title, and the `wpifw_order_buttons_array`
 * filter third parties used to add rows.
 */
class OrderMetabox {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ), 10, 2 );
	}

	/**
	 * Draft statuses: orders being created (admin "Add order", block
	 * checkout) — not orders yet, so no documents.
	 */
	const DRAFT_STATUSES = array( 'auto-draft', 'checkout-draft', 'draft' );

	/**
	 * Register the metabox on both order screens — while invoicing is on
	 * (v1), and only for orders that exist: the "Add order" screen edits
	 * a draft, and a document made from it would store a number the
	 * order keeps once created.
	 *
	 * @param string $post_type     Post type or screen id.
	 * @param mixed  $post_or_order WP_Post (legacy) or WC_Order (HPOS).
	 * @return void
	 */
	public function add_metabox( $post_type = '', $post_or_order = null ) {
		if ( ! challan_invoicing_enabled() ) {
			return;
		}
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( $order instanceof \WC_Order && in_array( $order->get_status(), self::DRAFT_STATUSES, true ) ) {
			return;
		}

		$screen = 'shop_order';
		if ( class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			&& function_exists( 'wc_get_container' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
			$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : $screen;
		}

		add_meta_box(
			'wpifw-meta-box',
			__( 'Challan Invoice', 'webappick-pdf-invoice-for-woocommerce' ),
			array( $this, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Metabox markup: one row per document, download + print actions.
	 *
	 * @param mixed $post_or_order WP_Post (legacy) or WC_Order (HPOS).
	 * @return void
	 */
	public function render( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$documents = DocumentButtons::for_order( $order );
		if ( challan_is_pro_active() ) {
			// v1 Pro metabox order: Invoice, Packing Slip, Credit Note, Shipping Label.
			$rank = array_flip( array( 'invoice', 'packing_slip', 'credit_note', 'delivery_address' ) );
			usort(
				$documents,
				static function ( $a, $b ) use ( $rank ) {
					$ra = isset( $rank[ $a['type'] ] ) ? $rank[ $a['type'] ] : PHP_INT_MAX;
					$rb = isset( $rank[ $b['type'] ] ) ? $rank[ $b['type'] ] : PHP_INT_MAX;
					return $ra - $rb;
				}
			);
		}

		$buttons = array();
		foreach ( $documents as $document ) {
			$buttons[ $document['label'] ] = $document['url'];
		}
		$documents = array_column( $documents, null, 'label' );

		/** Preserved v1 filter: label => url. */
		$buttons = apply_filters( 'wpifw_order_buttons_array', $buttons );

		// v1 Pro marked each document row with its icon.
		$icons = challan_is_pro_active();

		DocumentButtons::print_assets();
		?>
		<div class="wpifw_invoice_info">
			<table class="wpifw_order_invoice_table">
				<?php
				foreach ( (array) $buttons as $label => $url ) :
					$label     = (string) $label;
					$document  = isset( $documents[ $label ] ) && $documents[ $label ]['url'] === $url ? $documents[ $label ] : null;
					$print_url = $document ? $document['print_url'] : (string) $url;
					$slug      = $document ? $document['type'] : sanitize_html_class( sanitize_title( $label ) );
					?>
					<tr>
						<td class="wpifw_button-text _winvoice-info-<?php echo esc_attr( $slug ); ?><?php echo $icons && $document ? ' challan-has-icon' : ''; ?>">
							<?php if ( $icons && $document ) : ?>
								<img class="challan-doc-icon" src="<?php echo esc_url( $document['image'] ); ?>" alt="" />
							<?php endif; ?>
							<?php echo esc_html( $label ); ?>
						</td>
						<td class="wpifw_order_buttons">
							<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"
								<?php if ( $document && challan_is_pro_active() ) : ?>
									data-order="<?php echo absint( $order->get_id() ); ?>" data-type="<?php echo esc_attr( $document['type'] ); ?>" onclick="challanMarkOpened(this)"
								<?php endif; ?>
								class="wpifw_button_invoice button button-default _winvoice-info-<?php echo esc_attr( $slug ); ?>"
								title="<?php echo esc_attr( sprintf( /* translators: %s: document label. */ __( 'Download %s', 'webappick-pdf-invoice-for-woocommerce' ), $label ) ); ?>">
								<span class="dashicons dashicons-download"></span>
							</a>
						</td>
						<td class="wpifw_order_buttons">
							<button type="button" class="wpifw_button_invoice_print button button-default"
								onclick="printButton(<?php echo esc_attr( wp_json_encode( esc_url_raw( $print_url ) ) ); ?>)"
								title="<?php echo esc_attr( sprintf( /* translators: %s: document label. */ __( 'Print %s', 'webappick-pdf-invoice-for-woocommerce' ), $label ) ); ?>">
								<span class="dashicons dashicons-printer"></span>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}
}
