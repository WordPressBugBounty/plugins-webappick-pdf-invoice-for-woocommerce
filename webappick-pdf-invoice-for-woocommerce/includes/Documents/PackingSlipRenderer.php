<?php
/**
 * Packing slip document HTML renderer.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The packing slip: v1's packing slip is the invoice document with packing columns: same
 * header/billing/shipping/order-data sections (including the Invoice
 * Number row), an Item/Weight/Quantity products table (wc_format_weight
 * with its N/A fallback) and Total Quantity / Total Weight rows instead
 * of money totals. Verified against v1 3.7.81 golden output.
 */
class PackingSlipRenderer extends InvoiceRenderer {

	/**
	 * Document type id.
	 *
	 * @return string
	 */
	public function type() {
		return 'packing_slip';
	}

	/**
	 * Option holding the packing slip's products-per-page count.
	 *
	 * @return string
	 */
	protected function per_page_option() {
		return 'wpifw_packingslip_product_per_page';
	}

	/**
	 * Option choosing the packing slip's SKU/ID product-meta line.
	 *
	 * @return string
	 */
	protected function disid_option() {
		return 'wpifw_packingslip_disid';
	}

	/**
	 * Option holding the packing slip's product title length limit.
	 *
	 * @return string
	 */
	protected function title_length_option() {
		return 'wpifw_packingslip_product_title_length';
	}

	/**
	 * Columns after the item column. v1 free: weight, quantity. v1 pro
	 * (wpifw_packingslip_product_table_header, registered by Challan Pro):
	 * the chosen order of weight / dimension / quantity, default all three.
	 *
	 * @return array<int,string>
	 */
	protected function columns() {
		$chosen = $this->settings->get( 'wpifw_packingslip_product_table_header' );
		if ( null === $chosen ) {
			return array( 'weight', 'quantity' );
		}
		$chosen = is_array( $chosen ) ? array_values( array_intersect( $chosen, array( 'weight', 'dimension', 'quantity' ) ) ) : array();
		return array() !== $chosen ? $chosen : array( 'weight', 'dimension', 'quantity' );
	}

	/**
	 * Pro packing-slip option, or null when Challan Pro does not register it.
	 *
	 * @param string $key Option name.
	 * @return mixed
	 */
	private function pro_option( $key ) {
		return $this->settings->get( $key );
	}

	/**
	 * Packing columns: Item / Weight / Quantity (no money, plain qty).
	 *
	 * @param \WC_Order                     $order Order.
	 * @param array<\WC_Order_Item_Product> $items Items for this page.
	 * @return string
	 */
	protected function products_section( $order, array $items ) {
		$disid        = (string) $this->settings->get( $this->disid_option() );
		$title_length = (int) $this->settings->get( $this->title_length_option() );

		// v1 pro had its own packing-slip image toggle; free follows the invoice's.
		$own_images  = $this->pro_option( 'wpifw_packingslip_product_image_show' );
		$show_images = null === $own_images ? (bool) $this->settings->get( 'wpifw_product_image_show' ) : (bool) $own_images;

		$columns = $this->columns();
		// v1 pro's packing slip shared the invoice's serial "No." column.
		$show_serial = (bool) $this->pro_option( 'wpifw_product_serial_show' );
		$labels      = array(
			'weight'    => $this->label( 'Weight', $order ),
			'dimension' => $this->label( 'Dimension', $order ),
			// v1 Free headed it "Quantity", v1 Pro "Qty".
			'quantity'  => $this->label( challan_is_pro_active() ? 'Qty' : 'Quantity', $order ),
		);

		ob_start();
		do_action( 'woo_invoice_before_product_list', $order, $this->type(), 'before_product_list' );
		$html = ob_get_clean();

		$html .= '<table class="product-table"><thead><tr class="product-list-header">';
		if ( $show_serial ) {
			$html .= '<th class="serial-number-column">' . esc_html( $this->label( 'No.', $order ) ) . '</th>';
		}
		$html .= '<th class="product-column">' . esc_html( $this->label( 'Item', $order ) ) . '</th>';
		foreach ( $columns as $column ) {
			$html .= '<th class="' . esc_attr( $column . '-column' ) . '">' . esc_html( $labels[ $column ] ) . '</th>';
		}
		$html .= '</tr></thead><tbody class="product-list-tbody">';

		$rows_data = array();
		foreach ( $items as $item ) {
			$product  = $item->get_product();
			$quantity = (int) $item->get_quantity();

			list( $name_html, $truncated ) = $this->item_title_html( $this->item_title( $order, $item ), $title_length );

			$cell = '';
			$img  = $show_images ? $this->item_image( $product, $item ) : '';
			if ( '' !== $img ) {
				$cell .= $img . ' ';
			}
			$cell .= '<p><b>' . $name_html . '</b>' . ( $truncated ? '...' : '' );
			$cell .= $this->item_lines( 'title', $order, $item );
			$cell .= "<span class='product-meta'>";

			ob_start();
			do_action( 'woo_invoice_before_item_meta', $product, $order, $this->type() );
			$cell .= ob_get_clean();

			// v1 Free printed "sku: x" / "Id:x"; v1 Pro "SKU: x" / "ID:x" plus
			// the parent SKU under a variation (wpifw_display_parent_sku).
			$pro = challan_is_pro_active();
			if ( $product && 'SKU' === $disid && '' !== $product->get_sku() ) {
				$cell .= "<br/><span class='product-meta'>" . ( $pro ? esc_html( $this->label( 'SKU', $order ) ) . ': ' : 'sku: ' ) . esc_html( $product->get_sku() ) . '</span>';
				if ( $pro && $product->is_type( 'variation' ) && $this->pro_option( 'wpifw_display_parent_sku' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					if ( $parent && '' !== $parent->get_sku() ) {
						$cell .= "<br/><span class='product-meta'>" . esc_html( $this->label( 'Parent SKU', $order ) ) . ': ' . esc_html( $parent->get_sku() ) . '</span>';
					}
				}
			} elseif ( $product && 'ID' === $disid ) {
				$cell .= "<br/><span class='product-meta'>" . ( $pro ? esc_html( $this->label( 'ID', $order ) ) : 'Id' ) . ':' . esc_html( (string) $product->get_id() ) . '</span>';
			}

			$cell .= $this->extra_lines( $order, $product );
			$cell .= $this->item_lines( 'meta', $order, $item );

			// v1 Pro printed the formatted item meta on the packing slip too.
			if ( $pro ) {
				$cell .= $this->formatted_item_meta( $item );
			}

			ob_start();
			do_action( 'woo_invoice_after_item_meta', $product, $order, $this->type() );
			$cell .= ob_get_clean();

			$cell .= '</span></p>';

			$weight = $product && '' !== (string) $product->get_weight()
				? (float) $product->get_weight() * $quantity
				: '';
			// v1 Free printed line weights with two decimals (0.40 kg);
			// v1 Pro printed the plain product (0.4 kg).
			if ( '' !== $weight && ! challan_is_pro_active() ) {
				$weight = wc_format_decimal( $weight, 2 );
			}

			// v1 pro dimension column: width × height × length.
			$dimension = '';
			if ( $product ) {
				$dims = wc_format_dimensions( array( $product->get_width(), $product->get_height(), $product->get_length() ) );
				// "N/A" when unset, as v1 Pro printed it.
				$dimension = html_entity_decode( $dims, ENT_QUOTES, 'UTF-8' );
			}

			$rows_data[] = array(
				'product'   => $cell,
				'weight'    => esc_html( wc_format_weight( $weight ) ),
				'dimension' => esc_html( $dimension ),
				'quantity'  => esc_html( (string) $quantity ),
			);
		}

		/** Preserved v1 filter. */
		$rows_data = (array) apply_filters( 'woo_invoice_product_data', $rows_data, $this->type(), $order );

		$serial = $this->serial_offset;
		foreach ( $rows_data as $row ) {
			$html .= '<tr class="product-list">';
			if ( $show_serial ) {
				$html .= '<td class="serial-number">' . esc_html( ( ++$serial ) . '.' ) . '</td>';
			}
			$html .= '<td class="product">' . ( $row['product'] ?? '' ) . '</td>';
			foreach ( $columns as $column ) {
				$html .= '<td class="' . esc_attr( $column ) . '">' . ( $row[ $column ] ?? '' ) . '</td>';
			}
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		ob_start();
		do_action( 'woo_invoice_after_product_list', $order, $this->type(), 'after_product_list' );
		$html .= ob_get_clean();

		return $html;
	}

	/**
	 * The v1 pro category and description lines under the product name
	 * (wpifw_packingslip_product_category_show, _description_show with
	 * wpifw_packingslip_description_limit words).
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WC_Product|null $product Product.
	 * @return string
	 */
	private function extra_lines( $order, $product ) {
		if ( ! $product ) {
			return '';
		}
		$html   = '';
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;

		if ( $this->pro_option( 'wpifw_packingslip_product_category_show' ) && $parent ) {
			$terms = get_the_terms( $parent->get_id(), 'product_cat' );
			if ( is_array( $terms ) && array() !== $terms ) {
				$html .= "<br/><span class='product-meta'>" . esc_html( $this->label( 'Category', $order ) ) . ': ' . esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) . '</span>';
			}
		}

		$mode = (string) $this->pro_option( 'wpifw_packingslip_product_description_show' );
		if ( in_array( $mode, array( 'short', 'long' ), true ) && $parent ) {
			$text  = 'short' === $mode ? $parent->get_short_description() : $parent->get_description();
			$limit = (int) $this->settings->get( 'wpifw_packingslip_description_limit' );
			if ( '' !== $text ) {
				$text  = wp_strip_all_tags( $text );
				$text  = $limit > 0 ? wp_trim_words( $text, $limit, '…' ) : $text;
				$html .= "<br/><span class='product-meta'>" . esc_html( $this->label( 'Description', $order ) ) . ': ' . esc_html( $text ) . '</span>';
			}
		}

		return $html;
	}

	/**
	 * Total Quantity / Total Weight rows (v1 packing-total-table).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function totals_section( $order ) {
		$total_qty    = 0;
		$total_weight = 0.0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$quantity   = (int) $item->get_quantity();
			$total_qty += $quantity;
			$product    = $item->get_product();
			if ( $product && '' !== (string) $product->get_weight() ) {
				$total_weight += (float) $product->get_weight() * $quantity;
			}
		}

		/** Preserved v1 filters. */
		$total_qty    = apply_filters( 'woo_invoice_packing_total', $total_qty, $this->type(), $order );
		$total_weight = apply_filters( 'woo_invoice_packing_total_weight', $total_weight, $order->get_items(), $order, $this->type() );

		$rtl  = $this->is_rtl() ? 'rtl' : '';
		$html = '<table border="" class="packing-total-table" dir="' . esc_attr( $rtl ) . '"><tbody>';

		$html .= "<tr><td class='order-total-label'>" . esc_html( $this->label( 'Total Quantity', $order ) ) . ' :&nbsp;&nbsp;</td>'
			. "<td class='order-total-value'>" . esc_html( (string) $total_qty ) . '</td></tr>';

		// v1 pro "Show Total Weight" (registered by Challan Pro, off by
		// default); free always shows it.
		$weight_toggle = $this->pro_option( 'wpifw_packingslip_product_total_weight_show' );
		if ( $total_weight > 0 && ( null === $weight_toggle || $weight_toggle ) ) {
			$html .= "<tr><td class='order-total-label'>" . esc_html( $this->label( 'Total Weight', $order ) ) . ' :&nbsp;&nbsp;</td>'
				. "<td class='order-total-value'>" . esc_html( $total_weight . ' ' . get_option( 'woocommerce_weight_unit' ) ) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		/** Content under the packing totals (Challan Pro: the WCFM refund address). */
		return (string) apply_filters( 'challan_packing_slip_after_totals', $html, $order );
	}

	/**
	 * The invoice-number row atop the order-data table.
	 *
	 * With Challan Pro, v1 Pro's row: "Invoice Number  #123", hidden by
	 * wpifw_display_invoice_number '0'.
	 *
	 * @param \WC_Order $order          Order.
	 * @param string    $invoice_number Filtered invoice number.
	 * @return string
	 */
	protected function number_row( $order, $invoice_number ) {
		if ( ! challan_is_pro_active() ) {
			return parent::number_row( $order, $invoice_number );
		}
		if ( '0' === (string) get_option( 'wpifw_display_invoice_number', '' ) || '' === $invoice_number ) {
			return '';
		}

		return '<tr class="order-invoice-number">'
			. '<td class="order-data-label">' . esc_html( $this->label( 'Invoice Number', $order ) ) . '</td>'
			. '<td class="order-data-value">#' . esc_html( $invoice_number ) . '</td></tr>';
	}

	/**
	 * Shipping block, unless the packing slip is set to hide it.
	 *
	 * With Challan Pro, v1 Pro's rule: the shipping block follows
	 * wpifw_display_shipping_address / wpifw_hide_for_same_address.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function shipping_block( $order ) {
		/**
		 * Whether the packing slip prints the shipping block (Challan Pro
		 * applies its shipping-address settings).
		 *
		 * @param bool                         $show     Default true.
		 * @param \WC_Order                    $order    Order.
		 * @param \Challan\Settings\Repository $settings THIS render's settings.
		 */
		if ( ! apply_filters( 'challan_packing_slip_shows_shipping', true, $order, $this->settings ) ) {
			return '';
		}

		return parent::shipping_block( $order );
	}

	/**
	 * The v1 pro "Show Order Note" on the packing slip (wpifw_show_order_note_ps).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function note_section( $order ) {
		// v1 Free's packing slip had no note section at all (verified in
		// 3.7.91); v1 Pro printed it per its own packing-slip option.
		if ( ! challan_is_pro_active() ) {
			return '';
		}

		return $this->note_table( $order, (bool) $this->pro_option( 'wpifw_show_order_note_ps' ) );
	}
}
