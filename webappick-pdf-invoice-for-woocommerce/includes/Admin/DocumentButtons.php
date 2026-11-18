<?php
/**
 * Order document buttons shared by the orders list and the order metabox.
 *
 * @package Challan
 */

namespace Challan\Admin;

use Challan\Documents\DocumentTypes;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The v1 look and behaviour of the order document buttons: the v1 SVG icons
 * (invoice, packing list, shipping label, credit note), a print-options
 * menu that prints through a hidden frame, and — with Challan Pro — the
 * green "already opened" check v1 Pro showed on used buttons.
 */
final class DocumentButtons {

	/**
	 * Order meta recording that a document was opened from the admin.
	 */
	const OPENED_META_PREFIX = '_challan_printed_';

	/**
	 * Per-type presentation (v1 texts and icons).
	 *
	 * @param string $type Document type id.
	 * @return array{text:string,label:string,image:string,action:string,legacy_option:string}
	 */
	private static function presentation( $type ) {
		$pro = challan_is_pro_active();

		switch ( $type ) {
			case 'invoice':
				return array(
					'text'          => __( 'PDF Invoice', 'webappick-pdf-invoice-for-woocommerce' ),
					'label'         => __( 'Invoice', 'webappick-pdf-invoice-for-woocommerce' ),
					'image'         => 'invoice.svg',
					'action'        => 'wpifw_generate_invoice',
					'legacy_option' => 'wpifw_invoice_action_button_%1$d_%1$d',
				);
			case 'packing_slip':
				return array(
					'text'          => __( 'Packing Slip', 'webappick-pdf-invoice-for-woocommerce' ),
					'label'         => __( 'Packing Slip', 'webappick-pdf-invoice-for-woocommerce' ),
					'image'         => 'shipping_list.svg',
					'action'        => 'wpifw_generate_invoice_packing_slip',
					'legacy_option' => 'wpifw_button_invoice_packing_slip_%1$d_%1$d',
				);
			case 'delivery_address':
				// v1 Free called this document "Delivery Address"; v1 Pro "Shipping Label".
				$text = $pro ? __( 'Shipping Label', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Delivery Address', 'webappick-pdf-invoice-for-woocommerce' );
				return array(
					'text'          => $text,
					'label'         => $text,
					'image'         => 'shipping-label.svg',
					'action'        => 'wpifw_generate_delivery_address',
					'legacy_option' => 'wpifw_invoice_shipping_label_%1$d_%1$d',
				);
			case 'credit_note':
				return array(
					'text'          => __( 'Credit Note', 'webappick-pdf-invoice-for-woocommerce' ),
					'label'         => __( 'Credit Note', 'webappick-pdf-invoice-for-woocommerce' ),
					'image'         => 'credit-note.svg',
					'action'        => 'wpifw_generate_credit_note',
					'legacy_option' => 'wpifw_invoice_credit_note_%1$d_%1$d',
				);
		}

		$types = DocumentTypes::all();
		$label = isset( $types[ $type ]['label'] ) ? (string) $types[ $type ]['label'] : $type;

		return array(
			'text'          => $label,
			'label'         => $label,
			'image'         => 'invoice.svg',
			'action'        => '',
			'legacy_option' => '',
		);
	}

	/**
	 * The documents offered for an order, in v1 order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,string>> Each: type, text, label, image (URL), url, print_url, opened.
	 */
	public static function for_order( \WC_Order $order ) {
		$buttons  = array();
		$refunded = (float) $order->get_total_refunded() > 0 || 'refunded' === $order->get_status();

		foreach ( array_keys( DocumentTypes::all() ) as $type ) {
			if ( ! DocumentTypes::is_available( $type ) ) {
				continue;
			}
			// v1: credit notes exist only for refunded orders.
			if ( 'credit_note' === $type && ! $refunded ) {
				continue;
			}

			$presentation = self::presentation( $type );
			$buttons[]    = array(
				'type'      => $type,
				'text'      => $presentation['text'],
				'label'     => $presentation['label'],
				'image'     => self::icon_url( $presentation['image'] ),
				'action'    => $presentation['action'],
				'url'       => self::document_url( $order->get_id(), $type, 'open' ),
				'print_url' => self::document_url( $order->get_id(), $type, 'print' ),
				'opened'    => self::is_opened( $order, $type, $presentation['legacy_option'] ),
			);
		}

		return $buttons;
	}

	/**
	 * URL of a bundled document icon (or a full URL passed through).
	 *
	 * @param string $image File name in templates/images, or a URL.
	 * @return string
	 */
	public static function icon_url( $image ) {
		if ( preg_match( '#^(https?:)?//#', (string) $image ) ) {
			return (string) $image;
		}

		return CHALLAN_URL . 'templates/images/' . sanitize_file_name( (string) $image );
	}

	/**
	 * Authenticated REST URL for a document.
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Document type id.
	 * @param string $purpose  'open' (button behaviour + HTML option) or 'print' (inline).
	 * @return string
	 */
	public static function document_url( $order_id, $type, $purpose = 'open' ) {
		$args = array(
			'type'     => rawurlencode( $type ),
			'_wpnonce' => wp_create_nonce( 'wp_rest' ),
		);

		if ( get_option( 'wpifw_output_type_html' ) ) {
			// v1: "Output as HTML" applied to the admin buttons too.
			$args['output'] = 'html';
		} elseif ( 'open' === $purpose && 'download' === get_option( 'wpifw_pdf_invoice_button_behaviour', 'new_tab' ) ) {
			$args['output'] = 'download';
		}

		return add_query_arg( $args, rest_url( sprintf( 'challan/v1/orders/%d/document', absint( $order_id ) ) ) );
	}

	/**
	 * Whether the document was already opened from the admin (Challan Pro
	 * shows a check on it). Reads the v1 Pro option rows too, so marks made
	 * before the upgrade stay visible.
	 *
	 * @param \WC_Order $order         Order.
	 * @param string    $type          Document type id.
	 * @param string    $legacy_option v1 Pro option name pattern.
	 * @return bool
	 */
	private static function is_opened( \WC_Order $order, $type, $legacy_option ) {
		if ( ! challan_is_pro_active() ) {
			return false;
		}
		if ( '' !== (string) $order->get_meta( self::OPENED_META_PREFIX . $type, true ) ) {
			return true;
		}

		return '' !== $legacy_option && (bool) get_option( sprintf( $legacy_option, $order->get_id() ) );
	}

	/**
	 * Record that a document was opened from the admin.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $type  Document type id.
	 * @return void
	 */
	public static function mark_opened( \WC_Order $order, $type ) {
		$order->update_meta_data( self::OPENED_META_PREFIX . $type, time() );
		$order->save_meta_data();
	}

	/**
	 * Inline CSS + JS for the buttons (v1 styles), printed on the order list
	 * and order edit screens.
	 *
	 * @return void
	 */
	public static function print_assets() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;

		$opened_endpoint = esc_url_raw( rest_url( 'challan/v1/orders/' ) );
		$rest_nonce      = wp_create_nonce( 'wp_rest' );
		?>
		<style id="challan-document-buttons">
			/* Orders list — v1 icon buttons. */
			.wp-list-table a.button.challan-doc-button {
				position: relative;
				display: inline-block;
				width: 2em;
				height: 2em !important;
				padding: 0 !important;
				margin: 0 4px 2px 0;
				text-align: center;
				vertical-align: middle;
				box-sizing: border-box;
			}
			.wp-list-table a.button.challan-doc-button img {
				width: 19px;
				margin-top: 4px;
				margin-left: 0;
			}
			/* v1 Pro: green check on documents already opened. */
			.wp-list-table a.button.challan-doc-button.exists::after {
				content: "\f147";
				font-family: dashicons;
				position: absolute;
				font-size: 20px;
				line-height: 1;
				left: 4px;
				top: 5px;
				color: #2aad2a;
			}
			/* Print options (v1): printer button + black menu. */
			.wpifw-tooltip-container { position: relative; display: inline-block; vertical-align: middle; }
			a.button.wpifw-print-dropdown-button { text-indent: unset !important; width: 2em; height: 2em !important; padding: 0 !important; box-sizing: border-box; text-align: center; margin: 0 0 2px 0; cursor: pointer; }
			a.button.wpifw-print-dropdown-button span.dashicons.dashicons-printer { margin: 4px 2px; }
			.wpifw-print-option { position: absolute; text-align: left; padding: 0 10px; right: 5px; z-index: 999; }
			ul.wpifw-print-options { position: absolute; background: #000; padding: 5px; right: 30px; width: 150px; top: -65px; border-radius: 5%; margin: 0; }
			ul.wpifw-print-options::after { content: ""; position: absolute; top: 50%; left: 100%; margin-top: -5px; border-width: 5px; border-style: solid; border-color: transparent transparent transparent #000; }
			ul.wpifw-print-options li { margin: 0; padding: 2px 4px; }
			a.wpifw_invoice_print { cursor: pointer; color: #fff; }
			a.wpifw_invoice_print:hover { color: #fff; text-decoration: underline; }
			/* Order edit — "Challan Invoice" metabox (v1). */
			.wpifw_order_invoice_table { width: 100%; }
			td.wpifw_button-text { width: 70%; position: relative; }
			td.wpifw_button-text.challan-has-icon { padding-left: 22px; }
			td.wpifw_button-text img.challan-doc-icon { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; }
			.wpifw_order_buttons { width: 15%; }
			.wpifw_order_invoice_table .button { padding: 0 5px !important; }
			.wpifw_order_invoice_table span.dashicons { margin: 4px 2px; }
		</style>
		<script id="challan-document-buttons-js">
			( function () {
				/** Print a document through a hidden frame (v1 behaviour). */
				window.challanPrintDocument = window.printButton = function ( url ) {
					var frame = document.createElement( 'iframe' );
					frame.style.display = 'none';
					document.body.appendChild( frame );
					frame.src = url;
					frame.onload = function () {
						try {
							frame.contentWindow.focus();
							frame.contentWindow.print();
						} catch ( e ) {
							window.open( url, '_blank' );
						}
					};
				};
				window.closePrintOptionsDropdowns = function () {
					document.querySelectorAll( '.wpifw-print-option' ).forEach( function ( el ) {
						el.style.display = 'none';
					} );
				};
				window.togglePrintOptionsDropdown = function ( button ) {
					var dropdown = button.nextElementSibling;
					var opening = dropdown.style.display === 'none';
					window.closePrintOptionsDropdowns();
					dropdown.style.display = opening ? 'block' : 'none';
				};
				document.addEventListener( 'click', function ( e ) {
					if ( ! e.target.closest( '.wpifw-tooltip-container' ) ) {
						window.closePrintOptionsDropdowns();
					}
				} );
				/** Challan Pro: remember that a document was opened (green check). */
				window.challanMarkOpened = function ( link ) {
					if ( ! link.dataset.order || ! link.dataset.type ) {
						return;
					}
					link.classList.add( 'exists' );
					try {
						fetch( <?php echo wp_json_encode( $opened_endpoint ); ?> + link.dataset.order + '/opened', {
							method: 'POST',
							credentials: 'same-origin',
							keepalive: true,
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( $rest_nonce ); ?> },
							body: JSON.stringify( { type: link.dataset.type } )
						} );
					} catch ( e ) {}
				};
			} )();
		</script>
		<?php
	}
}
