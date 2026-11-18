<?php
/**
 * Delivery address sheet renderer.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builds printable address-label sheets: a columns × rows grid, one cell
 * per order, using the configured {{placeholder}} address template.
 */
class DeliveryAddressRenderer extends AbstractRenderer {

	/**
	 * Document type id.
	 *
	 * @return string
	 */
	public function type() {
		return 'delivery_address';
	}

	/**
	 * One order's address sheet: a single-cell label grid.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function body( \WC_Order $order ) {
		return $this->grid( array( $order ) );
	}

	/**
	 * Render several orders into one address-label document.
	 *
	 * Address sheets pack many orders into one grid rather than one page
	 * per order.
	 *
	 * @param \WC_Order[] $orders Orders.
	 * @return string Full HTML.
	 */
	public function render_many( array $orders ) {
		return $this->wrap( $this->grid( $orders ) );
	}

	/**
	 * The label grid.
	 *
	 * @param \WC_Order[] $orders Orders.
	 * @return string
	 */
	private function grid( array $orders ) {
		$columns   = max( 1, (int) $this->settings->get( 'wpifw_delivery_address_num_col' ) );
		$font_size = (string) $this->settings->get( 'wpifw_delivery_address_font_size' );
		$font_size = (float) $font_size > 0 ? $font_size : '11'; // Blank would print font-size:pt.
		$title     = (string) $this->settings->get( 'wpifw_delivery_address_block_title_to' );

		$cells = array();
		foreach ( $orders as $order ) {
			/**
			 * One label's content — change or replace it.
			 *
			 * @param string                       $html     Cell HTML.
			 * @param \WC_Order                    $order    Order.
			 * @param \Challan\Settings\Repository $settings THIS render's settings.
			 */
			$cells[] = array(
				'order' => $order,
				'html'  => (string) apply_filters( 'challan_label_cell_html', $this->cell( $order, $title ), $order, $this->settings ),
			);
		}

		// Rows per page are NOT configured (user decision 2026-08-19):
		// one continuous table, and the PDF engine breaks pages between
		// rows automatically — as many rows as the paper height fits.
		// The wpifw_delivery_address_num_row option stays stored but unused.
		$width = floor( 100 / $columns );
		$html  = '<table class="order-table" width="100%" style="border-collapse:collapse;font-size:' . esc_attr( $font_size ) . 'pt">';

		foreach ( array_chunk( $cells, $columns ) as $row_cells ) {
			// Pad the final row to the full column count so the label
			// grid keeps its geometry (empty slots still show their
			// dashed outline — matching physical label sheets).
			$html     .= $this->row_prefix( $row_cells );
			$row_cells = array_pad(
				$row_cells,
				$columns,
				array(
					'order' => null,
					'html'  => '',
				)
			);
			$html     .= '<tr>';
			foreach ( $row_cells as $cell ) {
				$html .= '<td width="' . esc_attr( $width . '%' ) . '" style="' . esc_attr( $this->cell_style() ) . '">' . $cell['html'] . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</table>';
	}

	/**
	 * Markup before a row of labels (Challan Pro: v1's custom shipping
	 * label filter).
	 *
	 * @param array<int,array{order:\WC_Order,html:string}> $row_cells The row's labels.
	 * @return string
	 */
	protected function row_prefix( array $row_cells ) {
		return '';
	}

	/**
	 * Inline style of a label cell.
	 *
	 * @return string
	 */
	protected function cell_style() {
		return 'border:1px dashed #ccc;padding:8px;vertical-align:top';
	}

	/**
	 * One address label cell.
	 *
	 * Deliberate v2 change (labels merge, user decision 2026-08-19):
	 * v1 stored wpifw_delivery_address_buyer but never rendered it (dead
	 * branch) — the layout-builder template now DRIVES the cell. When
	 * the template is empty the cell falls back to v1 3.7.81 semantics:
	 * the WC-formatted shipping address plus billing phone/email (shown
	 * while the display_phone/display_email options are FALSY — v1's
	 * inverted toggles).
	 *
	 * Protected so Challan Pro can extend the cell (QR / barcode).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $title Block title, e.g. "To".
	 * @return string HTML (escaped).
	 */
	protected function cell( \WC_Order $order, $title ) {
		$html = '';
		if ( '' !== $title ) {
			$html .= '<strong>' . esc_html( $title ) . '</strong><br>';
		}

		$template = trim( (string) $this->settings->get( 'wpifw_delivery_address_buyer' ) );
		if ( '' !== $template ) {
			$rendered = AddressTemplate::render( $template, $order );
			return '' === $rendered ? '' : $html . $rendered;
		}

		// v1 fallback: the WC-formatted address block.
		$address = (string) $order->get_formatted_shipping_address();
		if ( '' === $address ) {
			return '';
		}
		$html .= wp_kses_post( $address );

		if ( ! $this->settings->get( 'wpifw_display_phone' ) && '' !== $order->get_billing_phone() ) {
			$html .= '<br>' . esc_html( $order->get_billing_phone() );
		}
		if ( ! $this->settings->get( 'wpifw_display_email' ) && '' !== $order->get_billing_email() ) {
			$html .= '<br>' . esc_html( $order->get_billing_email() );
		}

		return $html;
	}
}
