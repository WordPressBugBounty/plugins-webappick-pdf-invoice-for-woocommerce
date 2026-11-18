<?php
/**
 * Order-list document action buttons.
 *
 * @package Challan
 */

namespace Challan\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Document buttons in the WooCommerce orders list (HPOS and legacy),
 * presented like v1: one SVG icon button per document (PDF Invoice,
 * Packing Slip, Delivery Address / Shipping Label, Credit Note on
 * refunded orders) followed by a "Print Options" menu. Links go to the
 * authenticated challan/v1 document endpoint.
 */
class OrderActions {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_admin_order_actions_end', array( $this, 'render_buttons' ) );
		add_action( 'admin_footer', array( $this, 'maybe_print_assets' ) );
	}

	/**
	 * Buttons for one order, after both v1 filters ran.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function buttons( \WC_Order $order ) {
		$buttons = array();
		foreach ( DocumentButtons::for_order( $order ) as $button ) {
			$buttons[] = array_merge(
				$button,
				array(
					// v1 keys, so filter callbacks written for v1 keep working.
					'class' => 'wpifw_' . $button['type'],
					'src'   => $button['image'],
				)
			);
		}

		/** Preserved v1 (free) filter. */
		$buttons = apply_filters( 'wpifw_admin_order_action_buttons', $buttons );

		/** Preserved v1 (pro) filter. */
		$buttons = apply_filters( 'wpifw_admin_order_list_buttons', $buttons, $order->get_id() );

		$normalized = array();
		foreach ( (array) $buttons as $button ) {
			if ( ! is_array( $button ) || ( isset( $button['condition'] ) && ! $button['condition'] ) ) {
				continue;
			}

			$action = isset( $button['action'] ) ? sanitize_key( $button['action'] ) : '';
			$url    = isset( $button['url'] ) ? (string) $button['url'] : '';
			if ( '' === $url && '' !== $action ) {
				// Entry added by a v1-era callback: link its admin-ajax action like v1 did.
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'action'   => $action,
							'order_id' => $order->get_id(),
						),
						admin_url( 'admin-ajax.php' )
					),
					'woo_invoice_ajax_nonce'
				);
			}
			if ( '' === $url ) {
				continue;
			}

			$image = '';
			if ( ! empty( $button['src'] ) ) {
				$image = (string) $button['src'];
			} elseif ( ! empty( $button['image'] ) ) {
				$image = (string) $button['image'];
			}

			$normalized[] = array(
				'type'      => isset( $button['type'] ) ? sanitize_key( $button['type'] ) : $action,
				'text'      => isset( $button['text'] ) ? (string) $button['text'] : '',
				'class'     => isset( $button['class'] ) ? (string) $button['class'] : '',
				'image'     => DocumentButtons::icon_url( '' !== $image ? $image : 'invoice.svg' ),
				'url'       => $url,
				'print_url' => isset( $button['print_url'] ) ? (string) $button['print_url'] : $url,
				'opened'    => ! empty( $button['opened'] ),
				'native'    => isset( $button['print_url'] ),
			);
		}

		return $normalized;
	}

	/**
	 * Print the icon buttons and the print menu at the end of the row actions.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function render_buttons( $order ) {
		// v1: no document buttons while invoicing is off.
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) || ! challan_invoicing_enabled() ) {
			return;
		}

		$buttons = $this->buttons( $order );
		if ( ! $buttons ) {
			return;
		}

		$pro = challan_is_pro_active();

		foreach ( $buttons as $button ) {
			$classes = array( 'button', 'tips', 'parcial', 'challan-doc-button' );
			foreach ( preg_split( '/\s+/', $button['class'] ) as $class ) {
				if ( '' !== $class ) {
					$classes[] = sanitize_html_class( $class );
				}
			}
			if ( $button['opened'] ) {
				$classes[] = 'exists';
			}

			printf(
				'<a href="%1$s" target="_blank" rel="noopener" class="%2$s" data-tip="%3$s" aria-label="%3$s"%4$s><img src="%5$s" alt="" /></a>',
				esc_url( $button['url'] ),
				esc_attr( implode( ' ', array_unique( $classes ) ) ),
				esc_attr( $button['text'] ),
				$pro && $button['native']
					? sprintf( ' data-order="%d" data-type="%s" onclick="challanMarkOpened(this)"', absint( $order->get_id() ), esc_attr( $button['type'] ) )
					: '',
				esc_url( $button['image'] )
			);
		}

		?>
		<div class="wpifw-tooltip-container">
			<span class="wpifw-tooltip" data-tip="<?php esc_attr_e( 'Print Options', 'webappick-pdf-invoice-for-woocommerce' ); ?>">
				<a class="button wpifw-print-dropdown-button" role="button" tabindex="0" title="<?php esc_attr_e( 'Print Options', 'webappick-pdf-invoice-for-woocommerce' ); ?>" onclick="togglePrintOptionsDropdown(this)"><span class="dashicons dashicons-printer"></span></a>
				<div class="wpifw-print-option" style="display: none;">
					<ul class="wpifw-print-options">
						<?php foreach ( $buttons as $button ) : ?>
							<li>
								<a class="wpifw_invoice_print" role="button" title="<?php echo esc_attr( $button['text'] ); ?>" onclick="printButton(<?php echo esc_attr( wp_json_encode( esc_url_raw( $button['print_url'] ) ) ); ?>); closePrintOptionsDropdowns();">
									<?php
									/* translators: %s: document name, e.g. "PDF Invoice". */
									echo esc_html( sprintf( __( 'Print %s', 'webappick-pdf-invoice-for-woocommerce' ), $button['text'] ) );
									?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</span>
		</div>
		<?php
	}

	/**
	 * Button styles + print helpers — only on order-list screens.
	 *
	 * @return void
	 */
	public function maybe_print_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) || ! challan_invoicing_enabled() ) {
			return;
		}

		DocumentButtons::print_assets();
	}
}
