<?php
/**
 * Invoice document HTML renderer.
 *
 * @package Challan
 */

namespace Challan\Documents;

use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builds the invoice HTML. Fresh implementation (no v1 code) verified
 * against v1 3.7.81 golden output: same visible content, structure
 * classes, label scheme (translatable strings through the preserved
 * `woo_invoice_filter_template_label` filter), default product columns
 * (Item/Cost/Qty/Total with per-column selection via
 * wpifw_select_product_column) and totals rows.
 */
class InvoiceRenderer extends AbstractRenderer {

	/**
	 * Invoice number allocator.
	 *
	 * @var InvoiceNumber
	 */
	protected $number;

	/**
	 * Create the renderer.
	 *
	 * @param Repository    $settings Settings repository.
	 * @param InvoiceNumber $number   Invoice number allocator.
	 */
	public function __construct( Repository $settings, InvoiceNumber $number ) {
		parent::__construct( $settings );
		$this->number = $number;
	}

	/**
	 * Document type id.
	 *
	 * @return string
	 */
	public function type() {
		return 'invoice';
	}

	/**
	 * Apply the v1 label scheme: translate, then apply the preserved filter.
	 *
	 * @param string    $label Label text.
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function label( $label, $order ) {
		/** Preserved v1 filter. */
		return apply_filters(
			'woo_invoice_filter_template_label',
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- v1 label scheme preserved.
			__( $label, 'webappick-pdf-invoice-for-woocommerce' ),
			$order,
			$this->type()
		);
	}

	/**
	 * Wrap body markup into a full HTML document with the invoice CSS and
	 * the configured base font size.
	 *
	 * @param string $inner Body markup.
	 * @return string
	 */
	protected function wrap( $inner ) {
		$title = $this->document_title();
		$size  = (string) $this->settings->get( 'wpifw_invoice_font_size' );

		// Item meta lines (SKU, category, description…) print 30%
		// smaller than the base size — 10px base gives 7px meta
		// (user decision 2026-08-20; overrides default.css's `small`).
		$base = (float) $size > 0 ? (float) $size : 11;
		$meta = max( 1, (int) round( $base * 0.7 ) );

		return '<!DOCTYPE html><html><head>'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<style>' . $this->css()
			. "\n.product-meta { font-size: " . esc_attr( (string) $meta ) . 'px; }'
			. '</style></head>'
			. '<body style="font-size:' . esc_attr( $size ) . 'px">'
			. $inner
			. '</body></html>';
	}

	/**
	 * Number of items printed on earlier pages of the current document.
	 *
	 * @var int
	 */
	protected $serial_offset = 0;

	/**
	 * Per-order document body HTML, paginated by the products-per-page
	 * setting.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function body( \WC_Order $order ) {
		$rtl = $this->is_rtl() ? 'rtl' : '';

		$per_page = (int) $this->settings->get( $this->per_page_option() );
		$per_page = $per_page > 0 ? $per_page : 6;
		/** Preserved v1 filter. */
		$per_page = (int) apply_filters( 'wpifw_invoice_product_per_page', $per_page );

		$items  = array_values(
			array_filter(
				$order->get_items(),
				static function ( $item ) {
					return $item instanceof \WC_Order_Item_Product;
				}
			)
		);
		$chunks = array() === $items ? array( array() ) : array_chunk( $items, $per_page );
		$last   = count( $chunks ) - 1;

		// v1 pro "Stop repeating header and footer": header and order data
		// on the first page only, the footer from the last page only.
		$once = (bool) $this->settings->get( 'wpifw_stop_repeating_header_footer' );

		$html = '';
		foreach ( $chunks as $index => $chunk ) {
			$is_last = $index === $last;
			$html   .= '<div dir="' . esc_attr( $rtl ) . '" class="invoice-box' . ( $is_last ? '' : ' pageBreak' ) . '">';

			if ( ! $once || 0 === $index ) {
				ob_start();
				do_action( 'woo_invoice_before_document', $order, $this->type(), 'before_document' );
				$html .= ob_get_clean();

				$html .= $this->header_section( $order );
				$html .= $this->order_section( $order );
			}
			// Items before this page — serial numbers continue across pages (v1).
			$this->serial_offset = $index * $per_page;
			$html               .= $this->products_section( $order, $chunk );

			if ( $is_last ) {
				$html .= $this->totals_section( $order );
				$html .= $this->note_section( $order );
			}

			if ( ! $once || $is_last ) {
				$html .= $this->footer_section( $order );
			} elseif ( 0 === $index && '' !== $this->page_number_block() ) {
				// v1 pro (get_page_number): without the repeating footer the
				// page numbers still print on every page through their own
				// footer, until the last page's full footer takes over.
				$html .= '<htmlpagefooter name="invoiceFooter1">' . $this->page_number_block() . '</htmlpagefooter>'
					. '<sethtmlpagefooter name="invoiceFooter1" value="1"/>';
			}
			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Per-document option keys — overridden by the packing slip.
	 *
	 * @return string
	 */
	protected function per_page_option() {
		return 'wpifw_invoice_product_per_page';
	}

	/**
	 * Option choosing the SKU/ID product-meta line.
	 *
	 * @return string
	 */
	protected function disid_option() {
		return 'wpifw_disid';
	}

	/**
	 * The product's GTIN / UPC / EAN / ISBN — WooCommerce's
	 * _global_unique_id field (get_global_unique_id() on WC 9.1+, raw
	 * meta on older stores).
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	protected function product_gtin( $product ) {
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			return (string) $product->get_global_unique_id();
		}
		return (string) $product->get_meta( '_global_unique_id' );
	}

	/**
	 * Option holding the product title length limit.
	 *
	 * @return string
	 */
	protected function title_length_option() {
		return 'wpifw_invoice_product_title_length';
	}

	/**
	 * Logo + seller identity (v1 header-table structure).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function header_section( $order ) {
		$company = (string) $this->settings->get( 'wpifw_cname' );
		if ( '' === $company ) {
			$company = get_bloginfo( 'name' );
		}
		$details = (string) $this->settings->get( 'wpifw_cdetails' );

		// v1-exact seller block: company + details only. Seller title,
		// SSN and VAT are dead settings in v1 free (pro renders them) —
		// printing them here would change upgraded sites' documents.
		$seller = '<b>' . esc_html( $company ) . '</b><br>' . nl2br( esc_html( $details ) );

		/** Preserved v1 filter. */
		$seller = apply_filters( 'woo_invoice_seller_info', $seller, $this->type() );

		/**
		 * Filter the seller block of a document, knowing the order (Challan
		 * Pro prints the ZATCA seller profile on documents with a ZATCA
		 * e-invoice, so the PDF matches the XML).
		 *
		 * @since 4.0.0
		 *
		 * @param string    $seller Seller block HTML (escaped).
		 * @param \WC_Order $order  Order.
		 * @param string    $type   Document type id.
		 */
		$seller = (string) apply_filters( 'challan_seller_block', $seller, $order, $this->type() );

		ob_start();
		do_action( 'woo_invoice_before_seller_info', $order, $this->type(), 'before_seller_info' );
		$before = ob_get_clean();
		ob_start();
		do_action( 'woo_invoice_after_seller_info', $order, $this->type(), 'after_seller_info' );
		$after = ob_get_clean();

		// Segment order from the Seller Info card; unknown values drop,
		// missing ones append in the default order so every segment
		// always renders somewhere.
		$default  = array( 'logo', 'bar_qr_code', 'seller' );
		$segments = $this->settings->get( 'challan_header_sort' );
		$segments = is_array( $segments ) ? array_values( array_intersect( $segments, $default ) ) : array();
		foreach ( $default as $segment ) {
			if ( ! in_array( $segment, $segments, true ) ) {
				$segments[] = $segment;
			}
		}
		/** Preserved v1-pro filter (same segment ids). */
		$segments = apply_filters( 'woo_invoice_header_position_customization', $segments, $order, $this->type() );

		$html = '';
		if ( 'invoice' === $this->type() ) {
			ob_start();
			/** Preserved v1 Pro action (invoice only). */
			do_action( 'wpifw_before_header_table_body' );
			$html = (string) ob_get_clean();
		}

		$html .= '<table class="header-table"><tbody><tr>';
		foreach ( array_values( (array) $segments ) as $index => $segment ) {
			// One-column templates (POS receipt) give each segment a row.
			if ( $index > 0 && $this->stacked() ) {
				$html .= '</tr><tr>';
			}
			if ( 'logo' === $segment ) {
				$html .= '<td class="site-logo header-cell">' . $this->logo_html() . '</td>';
			} elseif ( 'bar_qr_code' === $segment ) {
				/**
				 * Header QR/barcode markup — Challan Pro renders the codes
				 * here; the cell always renders (possibly empty) so the
				 * three header columns stay equal thirds.
				 *
				 * @param string                       $codes    Markup.
				 * @param \WC_Order                    $order    Order.
				 * @param string                       $type     Document type.
				 * @param \Challan\Settings\Repository $settings THIS render's settings (overlay-aware).
				 */
				$codes = apply_filters( 'challan_header_codes_html', '', $order, $this->type(), $this->settings );
				$html .= '<td class="header-codes header-cell">' . $codes . '</td>';
			} elseif ( 'seller' === $segment ) {
				$html .= '<td class="seller header-cell">'
					. "<span class='seller-info-before'>" . $before . '</span>'
					. "<span class='seller-info'>" . $seller . '</span>'
					. "<span class='seller-info-after'>" . $after . '</span>'
					. '</td>';
			}
		}

		return $html . '</tr></tbody></table><br/><br/>';
	}

	/**
	 * Whether the selected template stacks header/address segments in one
	 * column (see Templates::stacked()).
	 *
	 * @return bool
	 */
	protected function stacked() {
		return Templates::stacked( (string) $this->settings->get( 'wpifw_templateid' ) );
	}

	/**
	 * Logo <img> from the configured attachment.
	 *
	 * @return string
	 */
	protected function logo_html() {
		$attachment_id = (int) $this->settings->get( 'wpifw_logo_attachment_image_id' );

		// v1 fallback: the theme's custom logo only while the logo was never
		// configured. Once wpifw_logo_attachment_id exists (even '' after
		// "Remove logo"), an empty attachment means no logo.
		if ( $attachment_id < 1 && false === get_option( 'wpifw_logo_attachment_id' ) && has_custom_logo() ) {
			$attachment_id = (int) get_theme_mod( 'custom_logo' );
		}
		if ( $attachment_id < 1 ) {
			return '';
		}
		$src = wp_get_attachment_url( $attachment_id );
		if ( ! $src ) {
			return '';
		}
		$width = (string) $this->settings->get( 'wpifw_logo_width' );
		$logo  = '<img src="' . esc_url( $src ) . '" style="width:' . esc_attr( $width ) . '" alt="" />';

		/** Preserved v1 filter. */
		return apply_filters( 'woo_invoice_store_logo', $logo );
	}

	/**
	 * Billing / shipping / order-data row (v1 order-table structure).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function order_section( $order ) {
		$billing = $this->billing_block( $order );
		/** Preserved v1 filter. */
		$billing = apply_filters( 'woo_invoice_billing_info', $billing, $order, $this->type() );

		$shipping = $this->shipping_block( $order );
		/** Preserved v1 filter. */
		$shipping = apply_filters( 'woo_invoice_shipping_info', $shipping, $order, $this->type() );

		$hooks = array();
		foreach ( array( 'before_billing_address', 'after_billing_address', 'before_shipping_address', 'after_shipping_address', 'before_order_data', 'after_order_data' ) as $hook ) {
			ob_start();
			// The 4th arg carries THIS render's settings so hook
			// consumers (MetaFields) see preview overlays; v1 callbacks
			// with 3 params are unaffected.
			do_action( 'woo_invoice_' . $hook, $order, $this->type(), $hook, $this->settings );
			$hooks[ $hook ] = ob_get_clean();
		}

		if ( '' !== $billing ) {
			$billing = '<b>' . esc_html( $this->label( 'Billing', $order ) ) . '</b><br/>' . $hooks['before_billing_address'] . $billing;
		}
		if ( '' !== $shipping ) {
			$shipping = '<b>' . esc_html( $this->label( 'Shipping', $order ) ) . '</b><br/>' . $hooks['before_shipping_address'] . $shipping;
		}

		$cells = array(
			'billing'    => '<td class="billing-td" style="vertical-align:top">'
				. "<span class='billing-address'>" . $billing . '</span>'
				. "<span class='billing-address-after'>" . $hooks['after_billing_address'] . '</span>'
				. '</td>',
			'shipping'   => '' !== $shipping
				? '<td class="shipping-td" style="vertical-align:top">'
					. "<span class='shipping-address'>" . $shipping . '</span>'
					. "<span class='shipping-address-after'>" . $hooks['after_shipping_address'] . '</span>'
					. '</td>'
				: '',
			'order_data' => '<td class="order-data-td" style="vertical-align:top">' . $this->order_data_table( $order, $hooks['before_order_data'], $hooks['after_order_data'] ) . '</td>',
		);

		// Cell order from the Order Info card (challan_address_sort);
		// unknown values drop, missing ones append in the v1 order so
		// every cell always renders.
		$order_keys = $this->settings->get( 'challan_address_sort' );
		$order_keys = is_array( $order_keys ) ? array_values( array_intersect( $order_keys, array_keys( $cells ) ) ) : array();
		foreach ( array_keys( $cells ) as $cell_key ) {
			if ( ! in_array( $cell_key, $order_keys, true ) ) {
				$order_keys[] = $cell_key;
			}
		}
		/** Preserved v1 Pro filter (same cell ids). */
		$order_keys = array_values( array_intersect( (array) apply_filters( 'woo_invoice_address_position_customization', $order_keys, $order, $this->type() ), array_keys( $cells ) ) );

		/** Preserved v1 Pro filter: one row per cell. */
		$stacked = $this->stacked() || (bool) apply_filters( 'woo_invoice_separate_rows_order_info', false, $order, $this->type() );

		$html = '<table class="order-table"><tbody><tr>';
		foreach ( $order_keys as $index => $cell_key ) {
			if ( '' === $cells[ $cell_key ] ) {
				continue;
			}
			if ( $index > 0 && $stacked && '<tr>' !== substr( $html, -4 ) ) {
				$html .= '</tr><tr>';
			}
			$html .= $cells[ $cell_key ];
		}
		$html .= '</tr></tbody></table>';

		return $html;
	}

	/**
	 * The v1-format billing block: line-per-field with state CODE and the
	 * inverted phone/email toggles (fields show while the option is
	 * falsy — v1 3.7.81 behavior, preserved for identical output).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function billing_block( $order ) {
		/**
		 * Billing block markup from an add-on (Challan Pro: v1 Pro's
		 * WooCommerce-formatted address); null keeps this format.
		 *
		 * @param string|null                  $html     Markup or null.
		 * @param \WC_Order                    $order    Order.
		 * @param string                       $type     Document type.
		 * @param \Challan\Settings\Repository $settings THIS render's settings.
		 */
		$custom = apply_filters( 'challan_billing_block', null, $order, $this->type(), $this->settings );
		if ( is_string( $custom ) ) {
			return $custom;
		}

		// NOTE: v1 3.7.81 ignores the wpifw_buyer template for invoices
		// (its custom-address branch is commented out) — the option only
		// drives delivery-address labels. Preserved for identical output.
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$company = $order->get_billing_company();
		$city    = $order->get_billing_city();
		$post    = $order->get_billing_postcode();

		// Full state name (user decision 2026-08-20): "Oregon", not "OR".
		$country_code = $order->get_billing_country();
		$state_code   = $order->get_billing_state();
		$states       = WC()->countries->get_states( $country_code );
		$state_full   = isset( $states[ $state_code ] ) ? $states[ $state_code ] : $state_code;

		/** Preserved v1 filter — now defaulting to the full state name. */
		$state = apply_filters( 'woo_invoice_state_name', $state_full, $country_code, $order, WC()->countries );

		$country = isset( WC()->countries->countries[ $order->get_billing_country() ] )
			? WC()->countries->countries[ $order->get_billing_country() ]
			: $order->get_billing_country();

		$lines = array( esc_html( $name ) );
		foreach ( array( $company, $order->get_billing_address_1(), $order->get_billing_address_2() ) as $line ) {
			if ( '' !== (string) $line ) {
				$lines[] = esc_html( $line );
			}
		}
		if ( '' !== $city ) {
			$lines[] = esc_html( $city ) . ( '' !== $post ? ', ' . esc_html( $post ) : '' );
		} elseif ( '' !== $post ) {
			$lines[] = esc_html( $post );
		}
		if ( '' !== (string) $state ) {
			$lines[] = esc_html( $state );
		}
		if ( '' !== (string) $country ) {
			$lines[] = esc_html( $country );
		}
		if ( ! $this->settings->get( 'wpifw_display_phone' ) && '' !== $order->get_billing_phone() ) {
			$lines[] = esc_html( $this->label( 'Phone', $order ) ) . ' : ' . esc_html( $order->get_billing_phone() );
		}
		if ( ! $this->settings->get( 'wpifw_display_email' ) && '' !== $order->get_billing_email() ) {
			$lines[] = esc_html( $this->label( 'Email', $order ) ) . ' : ' . esc_html( $order->get_billing_email() );
		}

		// <br>-joined so line spacing is tight and identical in mPDF and
		// the browser preview (paragraph margins rendered inconsistently).
		return implode( '<br/>', array_filter( $lines, 'strlen' ) );
	}

	/**
	 * Shipping block — WC-formatted; the custom-template path honors the
	 * display/hide-same options (v1 split behavior).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function shipping_block( $order ) {
		/** Shipping block markup from an add-on (see challan_billing_block). */
		$custom = apply_filters( 'challan_shipping_block', null, $order, $this->type(), $this->settings );
		if ( is_string( $custom ) ) {
			return $custom;
		}

		// v1 3.7.81 always prints the WC-formatted shipping address when
		// one exists; the shipping template option is invoice-inert.
		return wp_kses_post( (string) $order->get_formatted_shipping_address() );
	}

	/**
	 * The order-data table (invoice/order numbers, dates, payment method)
	 * in v1's "label : value" format. No invoice-date row — v1 has none.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $before before_order_data hook output.
	 * @param string    $after  after_order_data hook output.
	 * @return string
	 */
	protected function order_data_table( $order, $before, $after ) {
		$date_format = (string) $this->settings->get( 'wpifw_date_format' );
		$created     = $order->get_date_created();

		// v1-shape order_info array through the preserved v1 filters
		// (value filters first, then the collection filter).
		$order_info = array(
			'invoice_number' => apply_filters( 'woo_invoice_invoice_number', $this->number->for_order( $order ), $this->type(), $order ),
			'order_number'   => apply_filters( 'woo_invoice_order_number', $order->get_order_number(), $this->type(), $order ),
			'order_date'     => apply_filters( 'woo_invoice_order_date', $created ? $created->date_i18n( $date_format ) : '', $this->type(), $order ),
		);
		if ( $this->settings->get( 'wpifw_payment_method_show' ) && '' !== $order->get_payment_method_title() ) {
			$order_info['payment_method'] = $order->get_payment_method_title();
		}
		$order_info = (array) apply_filters( 'woo_invoice_order_data', $order_info, $this->type(), $order );

		$number = (string) ( $order_info['invoice_number'] ?? '' );
		if ( 'invoice' === $this->type() ) {
			/** Preserved v1 Pro filter (the printed invoice number). */
			$number = (string) apply_filters( 'wpifw_invoice_number', $number, $order );
		}

		$html  = '<table class="order-data-table"><tbody>';
		$html .= $this->number_row( $order, $number );

		// v1 pro "Display Invoice Date" (registered by Challan Pro).
		if ( $this->settings->get( 'wpifw_display_invoice_date' ) ) {
			$html .= '<tr><td class="order-data-label">' . esc_html( $this->label( 'Invoice Date', $order ) ) . '</td>'
				. '<td class="order-data-value">: ' . esc_html( $this->invoice_date( $order, $date_format ) ) . '</td></tr>';
		}

		if ( '' !== $before ) {
			$html .= "<tr><td class='before-order-data'>" . $before . '</td></tr>';
		}

		// v1 pro "Display Order Number" (registered by Challan Pro; free
		// always shows the row): only a stored '0' hides it.
		if ( false !== $this->settings->get( 'wpifw_display_order_number' ) ) {
			$html .= '<tr><td class="order-data-label">' . esc_html( $this->label( 'Order Number', $order ) ) . '</td>'
				. '<td class="order-data-value">: ' . esc_html( (string) ( $order_info['order_number'] ?? '' ) ) . '</td></tr>';
		}

		if ( '' !== (string) ( $order_info['order_date'] ?? '' ) ) {
			$html .= '<tr><td class="order-data-label">' . esc_html( $this->label( 'Order Date', $order ) ) . '</td>'
				. '<td class="order-data-value">: ' . esc_html( (string) $order_info['order_date'] ) . '</td></tr>';
		}

		// v1 pro "Show Order Status" (registered by Challan Pro).
		if ( $this->settings->get( 'wpifw_show_order_status' ) ) {
			$html .= '<tr><td class="order-status-label">' . esc_html( $this->label( 'Order Status', $order ) ) . '</td>'
				. '<td class="order-status-value">: ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td></tr>';
		}

		if ( '' !== (string) ( $order_info['payment_method'] ?? '' ) ) {
			$html .= '<tr><td class="order-data-label">' . esc_html( $this->label( 'Payment Method', $order ) ) . '</td>'
				. '<td class="order-data-value">: ' . esc_html( (string) $order_info['payment_method'] ) . '</td></tr>';
		}

		// v1 printed any extra keys third parties added as generic rows.
		unset( $order_info['invoice_number'], $order_info['order_number'], $order_info['order_date'], $order_info['payment_method'] );
		foreach ( $order_info as $extra_label => $extra_value ) {
			if ( is_scalar( $extra_value ) && '' !== (string) $extra_value ) {
				$html .= '<tr><td class="order-data-label">' . esc_html( (string) $extra_label ) . '</td>'
					. '<td class="order-data-value">: ' . esc_html( (string) $extra_value ) . '</td></tr>';
			}
		}

		foreach ( MetaFields::custom_order_meta_rows( $order ) as $row ) {
			$html .= '<tr><td class="order-data-label">' . esc_html( $row[0] ) . '</td>'
				. '<td class="order-data-value">: ' . esc_html( $row[1] ) . '</td></tr>';
		}

		if ( '' !== $after ) {
			$html .= "<tr><td class='after-order-data'>" . $after . '</td></tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * The bold document-number row atop the order-data table. Extracted
	 * so document types with their own numbering (Challan Pro's credit
	 * note) can swap it without duplicating the rest of the table.
	 *
	 * @param \WC_Order $order          Order.
	 * @param string    $invoice_number Filtered invoice number.
	 * @return string
	 */
	protected function number_row( $order, $invoice_number ) {
		return '<tr><td style="font-weight: bold;" class="order-data-label">' . esc_html( $this->label( 'Invoice Number', $order ) ) . '</td>'
			. '<td style="font-weight: bold;" class="order-data-value">: '
			. esc_html( $invoice_number ) . '</td></tr>';
	}

	/**
	 * The "Invoice Date" value (v1 pro): with date type 'invoice_date' the
	 * date stored when the invoice number was given (v1 meta
	 * wpifw_invoice_date, Y-m-d), else — and when none is stored — the
	 * order date, as v1 printed it. Preserved v1 filters.
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $date_format PHP date format.
	 * @return string
	 */
	protected function invoice_date( $order, $date_format ) {
		$created = $order->get_date_created();
		$value   = $created ? $created->date_i18n( $date_format ) : '';

		if ( 'invoice_date' === (string) $this->settings->get( 'wpifw_display_date_type' ) ) {
			$stored = (string) $order->get_meta( 'wpifw_invoice_date', true );
			// v1 Pro: an order without an invoice date gets today's on its
			// first document (not in previews).
			if ( '' === $stored && $this->number->persists() ) {
				$stored = current_time( 'Y-m-d' );
				$order->update_meta_data( 'wpifw_invoice_date', $stored );
				$order->save_meta_data();
			}
			$date = '' !== $stored ? \DateTime::createFromFormat( 'Y-m-d', $stored ) : false;
			if ( $date ) {
				/** Preserved v1 filter. */
				return (string) apply_filters( 'woo_invoice_invoice_date', date_i18n( $date_format, $date->getTimestamp() ), $this->type(), $order );
			}
		}

		/** Preserved v1 filter. */
		return (string) apply_filters( 'woo_invoice_order_date', $value, $this->type(), $order );
	}

	/**
	 * Products table — v1 defaults: Item column + price/quantity/total
	 * (Cost/Qty/Total labels), selection via wpifw_select_product_column,
	 * SKU or ID on a product-meta line, "× N" quantities.
	 *
	 * @param \WC_Order                     $order Order.
	 * @param array<\WC_Order_Item_Product> $items Items for this page.
	 * @return string
	 */
	protected function products_section( $order, array $items ) {
		$disid        = (string) $this->settings->get( $this->disid_option() );
		$show_images  = (bool) $this->settings->get( 'wpifw_product_image_show' );
		$title_length = (int) $this->settings->get( $this->title_length_option() );
		$currency     = array( 'currency' => $order->get_currency() );

		$labels = array(
			'price'    => $this->label( 'Cost', $order ),
			'quantity' => $this->label( 'Qty', $order ),
			'total'    => $this->label( 'Total', $order ),
			'discount' => $this->label( 'Discount', $order ),
		);

		$columns = $this->settings->get( 'wpifw_select_product_column' );
		$columns = is_array( $columns ) ? array_values( array_intersect( $columns, array_keys( $labels ) ) ) : array();
		if ( array() === $columns ) {
			$columns = array( 'price', 'quantity', 'total' );
		}

		ob_start();
		do_action( 'woo_invoice_before_product_list', $order, $this->type(), 'before_product_list' );
		$html = ob_get_clean();

		$html .= '<table class="product-table"><thead><tr class="product-list-header">';
		$html .= '<th class="product-column">' . esc_html( $this->label( 'Item', $order ) ) . '</th>';
		foreach ( $columns as $column ) {
			$html .= '<th class="' . esc_attr( $column . '-column' ) . '">' . esc_html( $labels[ $column ] ) . '</th>';
		}
		$html .= '</tr></thead><tbody class="product-list-tbody">';

		$rows_data = array();
		foreach ( $items as $item ) {
			$product  = $item->get_product();
			$quantity = (int) $item->get_quantity();

			$full = $this->item_name( $item );
			$name = $full;
			if ( $title_length > 0 && function_exists( 'mb_substr' ) ) {
				$name = mb_substr( $name, 0, $title_length );
			}

			$img = '';
			if ( $show_images && $product ) {
				$src = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
				if ( $src ) {
					$img = '<img class="product-img" src="' . esc_url( $src ) . '" alt="" />';
				}
			}

			// v1 cell shape: bold title (ellipsis when truncated), then
			// product-meta lines for identifier / item meta / post meta.
			$cell      = '';
			$truncated = $title_length > 0 && function_exists( 'mb_strlen' ) && mb_strlen( $full ) > $title_length;
			$cell     .= '<p><b>' . esc_html( $name ) . '</b>' . ( $truncated ? '...' : '' );
			$cell     .= "<span class='product-meta'>";

			ob_start();
			do_action( 'woo_invoice_before_item_meta', $product, $order, $this->type() );
			$cell .= ob_get_clean();

			if ( $product && 'SKU' === $disid && '' !== $product->get_sku() ) {
				$cell .= "<br/><span class='product-meta'>sku: " . esc_html( $product->get_sku() ) . '</span>';
			} elseif ( $product && 'ID' === $disid ) {
				$cell .= "<br/><span class='product-meta'>Id:" . esc_html( (string) $product->get_id() ) . '</span>';
			} elseif ( $product && 'GTIN' === $disid && '' !== $this->product_gtin( $product ) ) {
				$cell .= "<br/><span class='product-meta'>GTIN: " . esc_html( $this->product_gtin( $product ) ) . '</span>';
			}

			$item_meta_name = (string) get_option( '_winvoice_order_item_meta_name', '' );
			if ( '' !== $item_meta_name ) {
				$item_meta_val = wc_get_order_item_meta( $item->get_id(), $item_meta_name, true );
				if ( '' !== (string) $item_meta_val && ! is_array( $item_meta_val ) ) {
					$cell .= "<br/><span class='product-meta'>" . esc_html( (string) get_option( '_winvoice_order_item_meta_label', '' ) ) . ': ' . esc_html( (string) $item_meta_val ) . '</span>';
				}
			}

			$post_meta_name = (string) get_option( '_winvoice_post_meta_name', '' );
			if ( '' !== $post_meta_name && $product ) {
				// $product is already the variation when one was bought,
				// matching v1's variation-aware lookup. Read the post meta
				// as v1 did: v1's key list offers WooCommerce's internal
				// keys (_weight, _sku…), which WC_Data::get_meta() refuses
				// with a notice (and the notice aborts the PDF in debug mode).
				/** Preserved v1 filter. */
				$post_meta_val = apply_filters( 'woo_invoice_meta_value_filter', get_post_meta( $product->get_id(), $post_meta_name, true ), $post_meta_name );
				if ( '' !== (string) $post_meta_val && ! is_array( $post_meta_val ) ) {
					$cell .= "<br/><span class='product-meta'>" . esc_html( (string) get_option( '_winvoice_post_meta_label', '' ) ) . ': ' . esc_html( (string) $post_meta_val ) . '</span>';
				}
			}

			ob_start();
			do_action( 'woo_invoice_after_item_meta', $product, $order, $this->type() );
			$cell .= ob_get_clean();

			$cell .= '</span></p>';

			// Thumbnail on the LEFT of the item info (nested table — the
			// dependable side-by-side primitive in mPDF).
			if ( '' !== $img ) {
				$cell = '<table class="product-cell"><tr>'
					. '<td class="product-cell-img">' . $img . '</td>'
					. '<td class="product-cell-info">' . $cell . '</td>'
					. '</tr></table>';
			}

			$unit_price = $quantity > 0 ? (float) $item->get_subtotal() / $quantity : (float) $item->get_subtotal();

			// v1 refund annotation: negative refunded qty under the quantity.
			$qty_cell     = (string) $quantity;
			$qty_refunded = $order->get_qty_refunded_for_item( $item->get_id() );
			if ( $qty_refunded < 0 ) {
				$qty_cell .= "<br/><span class='refund'>" . esc_html( (string) $qty_refunded ) . '</span>';
			}

			$values = array(
				'price'    => wp_kses_post( $this->format_price( $order, $unit_price ) ),
				'quantity' => "<small class='times'>&times;</small> " . $qty_cell,
				'total'    => wp_kses_post( $this->format_price( $order, (float) $item->get_total() ) ),
				'discount' => wp_kses_post( $this->format_price( $order, (float) $item->get_subtotal() - (float) $item->get_total() ) ),
			);

			$rows_data[] = array_merge( array( 'product' => $cell ), $values );
		}

		/** Preserved v1 filter (per-order product data collection). */
		$rows_data = (array) apply_filters( 'woo_invoice_product_data', $rows_data, $this->type(), $order );

		foreach ( $rows_data as $row_data ) {
			$html .= '<tr class="product-list"><td class="product">' . ( $row_data['product'] ?? '' ) . '</td>';
			foreach ( $columns as $column ) {
				$html .= '<td class="' . esc_attr( $column ) . '">' . ( $row_data[ $column ] ?? '' ) . '</td>';
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
	 * Totals table — v1 rows and "label :" format, with the paid-stamp
	 * spacer cell and preserved wpifw_before_last_tr_free filter.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function totals_section( $order ) {
		$currency = array( 'currency' => $order->get_currency() );

		$rows = array();

		$rows[] = array( 'subtotal-label', $this->label( 'Items Subtotal', $order ), $this->format_price( $order, (float) $order->get_subtotal() ) );

		if ( (float) $order->get_total_fees() > 0 ) {
			$rows[] = array( 'fees-label', $this->label( 'Fees', $order ), $this->format_price( $order, (float) $order->get_total_fees() ) );
		}
		if ( (float) $order->get_shipping_total() > 0 ) {
			$shipping = (float) $order->get_shipping_total();
			if ( (float) $order->get_shipping_tax() > 0
				&& 'wpifw_invoice_display_shipping_total_with_tax' === (string) $this->settings->get( 'wpifw_invoice_display_shipping_total' ) ) {
				$shipping += (float) $order->get_shipping_tax();
			}
			$rows[] = array( 'shipping-label', $this->label( 'Shipping', $order ), $this->format_price( $order, $shipping ) );
		}
		if ( (float) $order->get_total_tax() > 0 ) {
			// Split by rate (challan_split_tax_totals): one row per
			// WooCommerce tax line, labeled with the rate's percentage —
			// the per-rate breakdown VAT/GST regimes ask for. Off = the
			// single Tax row, byte-identical to before.
			$tax_totals = $this->settings->get( 'challan_split_tax_totals' ) ? $order->get_tax_totals() : array();
			if ( array() !== $tax_totals ) {
				foreach ( $tax_totals as $code => $tax ) {
					// Unique row key per rate — the v1 woo_invoice_order_total
					// filter contract keys rows by class, so identical keys
					// would collapse the split rows into one.
					$key    = 'tax-label tax-label-' . sanitize_html_class( strtolower( (string) $code ) );
					$rows[] = array( $key, $this->split_tax_label( $tax ), (string) $tax->formatted_amount );
				}
			} else {
				$rows[] = array( 'tax-label', $this->label( 'Tax', $order ), $this->format_price( $order, (float) $order->get_total_tax() ) );
			}
		}
		if ( (float) $order->get_discount_total() > 0 ) {
			$rows[] = array( 'discount-label', $this->label( 'Discount', $order ), '-' . $this->format_price( $order, (float) $order->get_discount_total() ) );
		}

		$rows[] = array( 'grand-total-label', $this->label( 'Order Total', $order ), $this->format_price( $order, (float) $order->get_total() ) );

		$refunded = (float) $order->get_total_refunded();
		if ( $refunded > 0 ) {
			$rows[] = array( 'refund-label', $this->label( 'Refund', $order ), '-' . $this->format_price( $order, $refunded ) );
			$rows[] = array( 'net-payment-label', $this->label( 'Net Payment', $order ), $this->format_price( $order, (float) $order->get_total() - $refunded ) );
		}

		/**
		 * Preserved v1 filter — v1 shape: label-keyed formatted values.
		 * Extra keys third parties add render as generic rows.
		 */
		$totals_map = array();
		foreach ( $rows as $row ) {
			$totals_map[ $row[0] ] = array( $row[1], $row[2] );
		}
		$filtered = apply_filters( 'woo_invoice_order_total', $totals_map, $this->type(), $order );
		if ( is_array( $filtered ) ) {
			$rows = array();
			foreach ( $filtered as $css_key => $pair ) {
				if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) ) {
					$rows[] = array( (string) $css_key, (string) $pair[0], (string) $pair[1] );
				} elseif ( is_scalar( $pair ) ) {
					$rows[] = array( sanitize_html_class( (string) $css_key ), (string) $css_key, (string) $pair );
				}
			}
		}

		$row_span = count( $rows ) + 1;
		$html     = '<table class="order-total-table"><tbody>';
		$first    = true;
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			if ( $first ) {
				$html .= "<td rowspan='" . esc_attr( (string) $row_span ) . "' class='paid-stamp'></td>";
				$first = false;
			}
			$html .= "<td class='order-total-label " . esc_attr( $row[0] ) . "'>" . esc_html( $row[1] ) . ' :</td>'
				. "<td class='order-total-value " . esc_attr( $row[0] ) . "-value'>" . wp_kses_post( $row[2] ) . '</td>';
			$html .= '</tr>';
		}

		/** Preserved v1 filters. */
		$html .= apply_filters( 'wpifw_before_last_tr_free', '', $order, $this->type() );
		$html .= "<tr class='total-last-tr'><td class='order-total-label total-last-td-label'></td><td class='order-total-value total-last-td-value'></td></tr>";
		$html .= apply_filters( 'wpifw_after_last_tr_free', '', $order, $this->type() );
		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Customer order note.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function note_section( $order ) {
		return $this->note_table( $order, self::show_order_note() );
	}

	/**
	 * The v1 "Show order note" reading: v1 Free printed the note while the
	 * option was set and not '0' — a stored '' counted as on, a missing
	 * option as off; v1 Pro needed a truthy value.
	 *
	 * @return bool
	 */
	public static function show_order_note() {
		$raw = get_option( 'wpifw_show_order_note' );
		if ( challan_is_pro_active() ) {
			return ! empty( $raw ) && '0' !== $raw;
		}

		return false !== $raw && '0' !== (string) $raw;
	}

	/**
	 * The v1 note table: the before / after customer-notes hook output (fired
	 * for every order, note or not — payment-slip plugins print there)
	 * around the "Customer Note:" row, each row only when non-empty.
	 *
	 * @param \WC_Order $order     Order.
	 * @param bool      $show_note Whether the note itself prints.
	 * @return string
	 */
	protected function note_table( $order, $show_note ) {
		ob_start();
		do_action( 'woo_invoice_before_customer_notes', $order, $this->type(), 'before_customer_notes' );
		$before = (string) ob_get_clean();

		$note = '';
		if ( $show_note && '' !== $order->get_customer_note() ) {
			// v1 heading: "Customer Note:".
			$note = '<b>' . esc_html( $this->label( 'Customer Note', $order ) ) . ':</b> ' . esc_html( $order->get_customer_note() );
			/** Preserved v1 filter (v1 shape: the labeled note). */
			$note = (string) apply_filters( 'woo_invoice_customer_notes', $note, $this->type(), $order );
		}

		ob_start();
		do_action( 'woo_invoice_after_customer_notes', $order, $this->type(), 'after_customer_notes' );
		$after = (string) ob_get_clean();

		if ( '' === trim( $before ) && '' === trim( $note ) && '' === trim( $after ) ) {
			return '';
		}

		$rtl  = $this->is_rtl() ? 'rtl' : '';
		$html = '<table class="order-note-table" dir="' . esc_attr( $rtl ) . '"><tbody>';
		if ( '' !== trim( $before ) ) {
			$html .= "<tr><td class='order-note-before'>" . $before . '</td></tr>';
		}
		if ( '' !== trim( $note ) ) {
			$html .= "<tr><td class='order-note'>" . wp_kses_post( $note ) . '</td></tr>';
		}
		if ( '' !== trim( $after ) ) {
			$html .= "<tr><td class='order-note-after'>" . $after . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * Terms & conditions / other information as a repeating mPDF page
	 * footer (v1 htmlpagefooter structure), with the after_document hook
	 * content inside the footer as in v1.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function footer_section( $order ) {
		/** Preserved v1 filters. */
		$terms = (string) apply_filters( 'woo_invoice_footer_1', (string) $this->settings->get( 'wpifw_terms_and_condition' ), $this->type(), $order );
		$other = (string) apply_filters( 'woo_invoice_footer_2', (string) $this->settings->get( 'wpifw_other_information' ), $this->type(), $order );

		// v1 (Free and Pro) printed terms / other information on invoices
		// and credit notes only; packing slips kept just the page number
		// unless the v1 filter opts in.
		/** Preserved v1 filter. */
		if ( 'packing_slip' === $this->type() && ! apply_filters( 'wpifw_enable_packing_slip_footer', false, $order ) ) {
			$terms = '';
			$other = '';
		}

		ob_start();
		do_action( 'woo_invoice_after_document', $order, $this->type(), 'after_document' );
		$hook_content = ob_get_clean();

		$page_number = $this->page_number_block();

		if ( '' === $terms && '' === $other && '' === $hook_content && '' === $page_number ) {
			return '';
		}

		$size = (string) $this->settings->get( 'wpifw_invoice_footer_font_size' );
		$size = (float) $size > 0 ? $size : '9'; // v1: blank or 0 = 9.

		$html = '<htmlpagefooter name="invoiceFooter">';
		if ( '' !== $terms || '' !== $other ) {
			$html .= "<hr class='invoice-footer-hr'>";
		}
		$html .= '<div class="invoice-footer"><table border="0" class="invoice-footer-table"><tbody><tr><td class="order-term-condition">';
		if ( '' !== $terms ) {
			$html .= '<p style="font-size:' . esc_attr( $size ) . 'px">' . esc_html( $terms ) . '</p><br>';
		}
		if ( '' !== $other ) {
			$html .= '<p style="font-size:' . esc_attr( $size ) . 'px">' . esc_html( $other ) . '</p>';
		}
		$html .= '</td></tr></tbody></table>' . $page_number . $hook_content . '</div></htmlpagefooter>';
		$html .= '<sethtmlpagefooter name="invoiceFooter" value="1"/>';

		return $html;
	}

	/**
	 * The item title through the preserved v1 woo_invoice_item_title filter.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line item.
	 * @return string
	 */
	protected function item_title( $order, $item ) {
		/** Preserved v1 filter. */
		return (string) apply_filters( 'woo_invoice_item_title', $this->item_name( $item ), $order, $item->get_product_id(), $item->get_id() );
	}

	/**
	 * Extra product-cell lines add-ons print (Challan Pro: bookings after
	 * the title, product/item meta maps and the vendor in the meta block).
	 *
	 * @param string                 $where 'title' (after the bold title) or 'meta' (in the meta lines).
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line item.
	 * @return string HTML.
	 */
	protected function item_lines( $where, $order, $item ) {
		return (string) apply_filters( 'challan_item_lines', '', $where, $item, $order, $this->type() );
	}

	/**
	 * The v1 Pro formatted order-item meta lines (variation attributes,
	 * add-ons): WooCommerce's hidden keys skipped, "_" → " " in the key,
	 * stops at YITH PDF invoice meta, through the preserved
	 * woo_invoice_display_formatted_meta_data filter.
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return string HTML.
	 */
	protected function formatted_item_meta( $item ) {
		$defaults = array( '_qty', '_tax_class', '_product_id', '_variation_id', '_line_subtotal', '_line_subtotal_tax', '_line_total', '_line_tax', 'method_id', 'cost', '_reduced_stock' );
		// WooCommerce's filter, as v1 applied it.
		$hidden = (array) apply_filters( 'woocommerce_hidden_order_itemmeta', $defaults ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$html = '';
		foreach ( $item->get_formatted_meta_data( '' ) as $meta ) {
			if ( in_array( $meta->key, $hidden, true ) ) {
				continue;
			}
			$key = str_replace( '_', ' ', (string) $meta->display_key );
			// v1: YITH PDF Invoice meta ends the list.
			if ( preg_match( '/ywpi/i', $key ) ) {
				break;
			}
			$value = wp_strip_all_tags( (string) $meta->display_value );
			$html .= "<br/><span class='product-meta product-meta-" . esc_attr( sanitize_html_class( (string) $meta->key ) ) . "'>"
				. esc_html( $key ) . ' : ' . esc_html( trim( $value ) ) . '</span>';
		}

		/** Preserved v1 filter. */
		return (string) apply_filters( 'woo_invoice_display_formatted_meta_data', $html, $this->type() );
	}

	/**
	 * The product thumbnail <img> (v1 sizing and thumbnail filters).
	 *
	 * @param \WC_Product|null       $product Product.
	 * @param \WC_Order_Item_Product $item    Line item.
	 * @return string HTML, '' when none.
	 */
	protected function item_image( $product, $item ) {
		if ( ! $product ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
		if ( ! $src ) {
			return '';
		}

		$style = '';
		if ( has_filter( 'woo_invoice_order_item_image_filter' ) ) {
			/** Preserved v1 filter (image size). */
			$size = (array) apply_filters(
				'woo_invoice_order_item_image_filter',
				array(
					'width'  => '50',
					'height' => '50',
				),
				$product,
				$item
			);
			if ( isset( $size['width'], $size['height'] ) ) {
				$style = ' style="width:' . (int) $size['width'] . 'px;height:' . (int) $size['height'] . 'px"';
			}
		}

		$img = '<img class="product-img" src="' . esc_url( $src ) . '" alt=""' . $style . ' />';

		/** WooCommerce's admin thumbnail filter, as v1 applied it. */
		return (string) apply_filters( 'woocommerce_admin_order_item_thumbnail', $img, $item->get_id(), $item ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
	}

	/**
	 * Label for a split tax-totals row: the WooCommerce rate label plus
	 * its percentage — "VAT (19%)". Trailing zeros trimmed (7.5, 19).
	 *
	 * @param object $tax One entry from WC_Order::get_tax_totals().
	 * @return string
	 */
	protected function split_tax_label( $tax ) {
		$label = (string) $tax->label;
		if ( isset( $tax->rate_id ) ) {
			$rate = \WC_Tax::_get_tax_rate( (int) $tax->rate_id );
			if ( isset( $rate['tax_rate'] ) ) {
				$percent = rtrim( rtrim( number_format( (float) $rate['tax_rate'], 4, '.', '' ), '0' ), '.' );
				$label  .= ' (' . $percent . '%)';
			}
		}
		return $label;
	}

	/**
	 * The v1 price formatting: currency-code mode (number_format + WC
	 * position) or wc_price with the `currencies` webfont injection for
	 * symbol-less currencies.
	 *
	 * @param \WC_Order $order Order.
	 * @param float     $price Amount.
	 * @return string HTML.
	 */
	protected function format_price( \WC_Order $order, $price ) {
		/** Preserved v1 Pro filter (incl./excl. tax price display). */
		$price = apply_filters( 'wpifw_incl_or_exl_tax__price', $price, $order );

		if ( $this->settings->get( 'wpifw_currency_code' ) ) {
			$formatted = number_format(
				(float) $price,
				wc_get_price_decimals(),
				wc_get_price_decimal_separator(),
				wc_get_price_thousand_separator()
			);
			$position  = (string) get_option( 'woocommerce_currency_pos', 'left' );
			if ( in_array( $position, array( 'left', 'left_space' ), true ) ) {
				return $order->get_currency() . ' ' . $formatted;
			}
			return $formatted . ' ' . $order->get_currency();
		}

		$formatted = wc_price( (float) $price, array( 'currency' => $order->get_currency() ) );

		// v1: currencies without a native glyph in most PDF fonts get the
		// bundled `currencies` webfont on the symbol span.
		$symbol_less = array( 'BDT', 'BTC', 'CRC', 'GEL', 'ILS', 'KPW', 'KRW', 'LAK', 'MNT', 'MUR', 'MVR', 'NPR', 'PKR', 'PYG', 'RUB', 'SCR', 'THB', 'TRY', 'VND' );
		if ( in_array( $order->get_currency(), $symbol_less, true ) ) {
			$formatted = str_replace(
				'woocommerce-Price-currencySymbol"',
				'woocommerce-Price-currencySymbol" style="font-family: currencies;font-size:15px"',
				$formatted
			);
		}

		return $formatted;
	}
}
