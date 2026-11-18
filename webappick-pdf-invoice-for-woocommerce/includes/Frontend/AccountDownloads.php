<?php
/**
 * Customer document buttons: My Account orders list, order view and the
 * order-received (thank you) page.
 *
 * @package Challan
 */

namespace Challan\Frontend;

use Challan\Documents\CustomerAccess;
use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Customer-facing buttons, presented like v1:
 *
 * - Orders list: an invoice action (Free: "Download Invoice"; Pro: the
 *   configured label after "View") and, with Pro, a "Credit Note" button
 *   with a download icon on refunded orders. All open in a new tab.
 * - Order view and thank-you page (after the order table): Free shows one
 *   blue "Download Invoice" button with a download icon; Pro shows
 *   Download + Print Invoice and, on refunded orders, Download + Print
 *   Credit Note, styled by the theme unless `wpifw_show_invoice_button_styles`
 *   asks for v1's blue buttons.
 */
class AccountDownloads {

	/**
	 * Settings repository.
	 *
	 * @var Repository
	 */
	private $settings;

	/**
	 * Whether the print helper script was printed.
	 *
	 * @var bool
	 */
	private $script_printed = false;

	/**
	 * Create the My Account downloads integration.
	 *
	 * @param Repository $settings Settings repository.
	 */
	public function __construct( Repository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'add_order_view_button' ) );
		add_action( 'woocommerce_after_account_orders', array( $this, 'print_list_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_icons' ) );
	}

	/**
	 * Load Dashicons for the button icons on the customer pages (they are
	 * not loaded for customers without the admin bar).
	 *
	 * @return void
	 */
	public function enqueue_icons() {
		if ( ! function_exists( 'is_account_page' ) ) {
			return;
		}
		if ( is_account_page() || is_order_received_page() ) {
			wp_enqueue_style( 'dashicons' );
		}
	}

	/**
	 * Document URL for a customer, honoring v1's HTML-output switch and
	 * the button behaviour (print links always render inline).
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $type      Document type id.
	 * @param bool      $for_print Whether the URL is for printing.
	 * @return string
	 */
	private function document_url( \WC_Order $order, $type, $for_print = false ) {
		$args = array(
			'type'     => rawurlencode( $type ),
			'_wpnonce' => wp_create_nonce( 'wp_rest' ),
		);
		if ( ! is_user_logged_in() ) {
			// Guests (Pro filter only): the order key proves ownership.
			$args['key'] = rawurlencode( $order->get_order_key() );
			unset( $args['_wpnonce'] );
		}
		if ( $this->settings->get( 'wpifw_output_type_html' ) ) {
			$args['output'] = 'html';
		} elseif ( ! $for_print && 'download' === (string) $this->settings->get( 'wpifw_pdf_invoice_button_behaviour' ) ) {
			$args['output'] = 'download';
		}

		return add_query_arg( $args, rest_url( sprintf( 'challan/v1/orders/%d/document', $order->get_id() ) ) );
	}

	/**
	 * The configured download label.
	 *
	 * @param string $fallback Label when the setting is empty.
	 * @return string
	 */
	private function download_label( $fallback ) {
		// The stored label only — the setting's default would hide v1 Pro's
		// own fallback ("Invoice") behind Free's "Download Invoice".
		$label = (string) get_option( 'wpifw_DOWNLOAD_INVOICE_TEXT', '' );
		return '' !== $label ? $label : $fallback;
	}

	/**
	 * Whether the current visitor may get this document from the front end.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $type  Document type id.
	 * @return bool
	 */
	private function can_download( \WC_Order $order, $type ) {
		if ( CustomerAccess::document_allowed( $order, $type, $this->settings ) ) {
			return true;
		}

		// Pro + `wpifw_allow_non_logged_in_users`: guests on the thank-you
		// page of their own order (WooCommerce verified the order key).
		return ! is_user_logged_in()
			&& is_order_received_page()
			&& isset( $_GET['key'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only match against the order key.
			&& hash_equals( $order->get_order_key(), sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& CustomerAccess::guest_allowed( $order, $type, $this->settings );
	}

	/**
	 * Add the list actions for eligible orders.
	 *
	 * @param array<string,array<string,string>> $actions Row actions.
	 * @param \WC_Order                          $order   Order.
	 * @return array<string,array<string,string>>
	 */
	public function add_action( $actions, $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}

		// The order view / thank-you page show their own buttons below the
		// order table; keep them out of WooCommerce's "Actions" row there.
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'view-order' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return $actions;
		}

		$pro = challan_is_pro_active();

		if ( $pro && $this->can_download( $order, 'credit_note' ) ) {
			// v1 Pro printed this button first in the actions cell.
			$actions = array_merge(
				array(
					'wpifw-my-account-credit-note' => array(
						'url'  => $this->document_url( $order, 'credit_note' ),
						'name' => __( 'Credit Note', 'webappick-pdf-invoice-for-woocommerce' ),
					),
				),
				$actions
			);
		}

		if ( $this->can_download( $order, 'invoice' ) ) {
			$key             = $pro ? 'download_invoice' : 'wpifw-my-account-invoice';
			$actions[ $key ] = array(
				'url'  => $this->document_url( $order, 'invoice' ),
				'name' => $pro ? $this->download_label( __( 'Invoice', 'webappick-pdf-invoice-for-woocommerce' ) ) : __( 'Download Invoice', 'webappick-pdf-invoice-for-woocommerce' ),
			);
		}

		return $actions;
	}

	/**
	 * New-tab behaviour + credit-note icon for the orders list (v1).
	 *
	 * @return void
	 */
	public function print_list_assets() {
		?>
		<style id="challan-account-orders">
			a.woocommerce-button.wpifw-my-account-credit-note { margin-bottom: 10px; }
			a.woocommerce-button.wpifw-my-account-credit-note::before {
				content: "\f316";
				font-family: dashicons;
				display: inline-block;
				width: 20px;
				height: 20px;
				font-size: 20px;
				line-height: 1;
				vertical-align: middle;
				margin-right: 2px;
			}
		</style>
		<script>
			document.querySelectorAll( 'a.wpifw-my-account-invoice, a.download_invoice, a.wpifw-my-account-credit-note' ).forEach( function ( link ) {
				link.setAttribute( 'target', '_blank' );
				link.setAttribute( 'rel', 'noopener' );
			} );
		</script>
		<?php
	}

	/**
	 * Buttons below the order table (order view + thank-you page).
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function add_order_view_button( $order ) {
		if ( ! $order instanceof \WC_Order || is_admin() ) {
			return;
		}

		if ( ! challan_is_pro_active() ) {
			$this->render_free_button( $order );
			return;
		}

		$print = (bool) $this->settings->get( 'wpifw_enable_print_option' );
		$shown = false;

		if ( $this->can_download( $order, 'invoice' ) ) {
			$print_label = (string) get_option( 'wpifw_PRINT_INVOICE_TEXT', '' );
			// v1 Pro printed the stored label; v1 Free always "Download Invoice".
			$label = challan_is_pro_active() ? $this->download_label( __( 'Download Invoice', 'webappick-pdf-invoice-for-woocommerce' ) ) : __( 'Download Invoice', 'webappick-pdf-invoice-for-woocommerce' );
			$this->render_button( $this->document_url( $order, 'invoice' ), 'wpifw-my-account-invoice', 'download', $label );
			if ( $print ) {
				$this->render_button( $this->document_url( $order, 'invoice', true ), 'wpifw-my-account-invoice', 'printer', '' !== $print_label ? $print_label : __( 'Print Invoice', 'webappick-pdf-invoice-for-woocommerce' ), true );
			}
			$shown = true;
		}

		if ( $this->can_download( $order, 'credit_note' ) ) {
			$this->render_button( $this->document_url( $order, 'credit_note' ), 'wpifw-my-account-credit-note', 'download', __( 'Download Credit Note', 'webappick-pdf-invoice-for-woocommerce' ) );
			if ( $print ) {
				$this->render_button( $this->document_url( $order, 'credit_note', true ), 'wpifw-my-account-credit-note', 'printer', __( 'Print Credit Note', 'webappick-pdf-invoice-for-woocommerce' ), true );
			}
			$shown = true;
		}

		if ( $shown && apply_filters( 'wpifw_show_invoice_button_styles', false ) ) {
			$this->print_button_styles( true );
		}
	}

	/**
	 * Free: v1's single blue "Download Invoice" button.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function render_free_button( \WC_Order $order ) {
		if ( ! $this->can_download( $order, 'invoice' ) ) {
			return;
		}

		$this->render_button( $this->document_url( $order, 'invoice' ), 'wpifw-my-account-invoice', 'download', __( 'Download Invoice', 'webappick-pdf-invoice-for-woocommerce' ) );
		$this->print_button_styles( false );
	}

	/**
	 * One v1 button: dashicon + label. Print buttons load the document
	 * into a hidden frame and print it.
	 *
	 * @param string $url          Document URL.
	 * @param string $button_class The v1 button class.
	 * @param string $icon         Dashicon name (download|printer).
	 * @param string $label        Button text.
	 * @param bool   $is_print     Whether this is a print button.
	 * @return void
	 */
	private function render_button( $url, $button_class, $icon, $label, $is_print = false ) {
		if ( $is_print ) {
			$this->print_script();
			printf(
				'<a class="woocommerce-button button %1$s challan-print-document" href="#" role="button" data-print-url="%2$s" style="margin-bottom: 10px;"><span class="dashicons dashicons-%3$s"></span>%4$s</a> ',
				esc_attr( $button_class ),
				esc_url( $url ),
				esc_attr( $icon ),
				esc_html( $label )
			);
			return;
		}

		printf(
			'<a class="woocommerce-button button %1$s" href="%2$s" target="_blank" rel="noopener" style="margin-bottom: 10px;"><span class="dashicons dashicons-%3$s"></span>%4$s</a> ',
			esc_attr( $button_class ),
			esc_url( $url ),
			esc_attr( $icon ),
			esc_html( $label )
		);
	}

	/**
	 * Hidden-frame printing for the print buttons (v1 printButton).
	 *
	 * @return void
	 */
	private function print_script() {
		if ( $this->script_printed ) {
			return;
		}
		$this->script_printed = true;
		?>
		<script>
			document.addEventListener( 'click', function ( e ) {
				var button = e.target.closest ? e.target.closest( '.challan-print-document' ) : null;
				if ( ! button ) {
					return;
				}
				e.preventDefault();
				var url = button.getAttribute( 'data-print-url' );
				var frame = document.createElement( 'iframe' );
				frame.style.display = 'none';
				document.body.appendChild( frame );
				frame.onload = function () {
					try {
						frame.contentWindow.focus();
						frame.contentWindow.print();
					} catch ( err ) {
						window.open( url, '_blank' );
					}
				};
				frame.src = url;
			} );
		</script>
		<?php
	}

	/**
	 * Print the v1 blue button styles.
	 *
	 * @param bool $pro Pro variant (fixed width, centered, credit notes too).
	 * @return void
	 */
	private function print_button_styles( $pro ) {
		$selector = $pro ? '.wpifw-my-account-invoice, .wpifw-my-account-credit-note' : '.wpifw-my-account-invoice';
		$hover    = $pro ? '.wpifw-my-account-invoice:hover, .wpifw-my-account-credit-note:hover' : '.wpifw-my-account-invoice:hover';
		$icon     = $pro ? '.wpifw-my-account-invoice span.dashicons, .wpifw-my-account-credit-note span.dashicons' : '.wpifw-my-account-invoice span.dashicons.dashicons-download';
		?>
		<style>
			<?php echo esc_html( $selector ); ?> {
				background-color: #2c7be5 !important;
				border: none !important;
				color: white !important;
				padding: 3px 10px !important;
				font-size: inherit !important;
				cursor: pointer !important;
				text-decoration: none !important;
				display: inline-block !important;
				margin: 10px 5px !important;
				border-radius: 0.375em !important;
				line-height: 25px !important;
				<?php if ( $pro ) : ?>
				width: 120px;
				text-align: center;
				<?php endif; ?>
			}
			<?php echo esc_html( $hover ); ?> {
				background-color: #1a68d1 !important;
			}
			<?php echo esc_html( $icon ); ?> {
				margin: 3px 2px !important;
			}
		</style>
		<?php
	}
}
