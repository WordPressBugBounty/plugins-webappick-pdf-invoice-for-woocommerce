<?php
/**
 * Legacy admin-ajax compatibility actions.
 *
 * @package Challan
 */

namespace Challan\Compat;

use Challan\Documents\AbstractRenderer;
use Challan\Documents\BrowserView;
use Challan\Documents\CustomerAccess;
use Challan\Documents\FontDownloader;
use Challan\Documents\PdfEngine;
use Challan\Documents\Templates;
use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * COMPATIBILITY CONTRACT: every v1 admin-ajax action name stays callable
 * — bookmarked URLs and third-party integrations keep working. Handlers
 * are fresh implementations delegating to the v2 pipeline, with v1's
 * auth semantics (nonce `woo_invoice_ajax_nonce`, `order_id` /
 * `order_ids` params, manager-or-owner rule) preserved and hardened.
 */
class LegacyAjax {

	/**
	 * Settings repository.
	 *
	 * @var Repository
	 */
	private $settings;

	/**
	 * Renderers keyed by document type id.
	 *
	 * @var array<string,AbstractRenderer>
	 */
	private $renderers;

	/**
	 * PDF engine.
	 *
	 * @var PdfEngine
	 */
	private $engine;

	/**
	 * Wire up the legacy AJAX handlers.
	 *
	 * @param Repository                     $settings  Settings.
	 * @param array<string,AbstractRenderer> $renderers Renderers by type.
	 * @param PdfEngine                      $engine    PDF engine.
	 */
	public function __construct( Repository $settings, array $renderers, PdfEngine $engine ) {
		$this->settings  = $settings;
		$this->renderers = $renderers;
		$this->engine    = $engine;
	}

	/**
	 * Hook in. Action names are frozen v1 identifiers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_wpifw_generate_invoice', array( $this, 'generate_invoice' ) );
		add_action( 'wp_ajax_wpifw_generate_invoice_packing_slip', array( $this, 'generate_packing_slip' ) );
		add_action( 'wp_ajax_wpifw_generate_delivery_address', array( $this, 'generate_delivery_address' ) );
		// v1 pro document actions (same request shape): bulk variants, the
		// credit note, and the shipping label (merged into Labels in v2).
		add_action( 'wp_ajax_wpifw_generate_invoice_bulk', array( $this, 'generate_invoice' ) );
		add_action( 'wp_ajax_wpifw_generate_invoice_packing_slip_bulk', array( $this, 'generate_packing_slip' ) );
		add_action( 'wp_ajax_wpifw_generate_credit_note', array( $this, 'generate_credit_note' ) );
		add_action( 'wp_ajax_wpifw_generate_credit_note_bulk', array( $this, 'generate_credit_note' ) );
		add_action( 'wp_ajax_wpifw_generate_shipping_label', array( $this, 'generate_delivery_address' ) );
		add_action( 'wp_ajax_wpifw_save_pdf_template', array( $this, 'save_pdf_template' ) );
		add_action( 'wp_ajax_wpifw_select_product_column', array( $this, 'select_product_column' ) );
		add_action( 'wp_ajax_wpifw_get_product_column_show', array( $this, 'get_product_column' ) );
		add_action( 'wp_ajax_prepare_fonts', array( $this, 'prepare_fonts' ) );
		// v1 notice actions: the notices no longer exist; acknowledge
		// so stale callers do not error.
		add_action( 'wp_ajax_woo_invoice_save_review_notice', array( $this, 'ack' ) );
		add_action( 'wp_ajax_woo_invoice_hide_notice', array( $this, 'ack' ) );
	}

	/**
	 * Handle the wpifw_generate_invoice action: stream the invoice PDF.
	 *
	 * @return void
	 */
	public function generate_invoice() {
		$this->stream_document( 'invoice' );
	}

	/**
	 * Handle the wpifw_generate_invoice_packing_slip action: stream the packing slip PDF.
	 *
	 * @return void
	 */
	public function generate_packing_slip() {
		$this->stream_document( 'packing_slip' );
	}

	/**
	 * Handle the wpifw_generate_delivery_address action: stream the address sheet PDF.
	 *
	 * @return void
	 */
	public function generate_delivery_address() {
		$this->stream_document( 'delivery_address' );
	}

	/**
	 * Handle the wpifw_generate_credit_note(_bulk) action: v1 pro; needs the pro renderer.
	 *
	 * @return void
	 */
	public function generate_credit_note() {
		$this->stream_document( 'credit_note' );
	}

	/**
	 * Shared generate handler with v1 request shape.
	 *
	 * @param string $type Document type id.
	 * @return void
	 */
	private function stream_document( $type ) {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$this->verify_nonce();

		// v1 registered these actions only while invoicing was on.
		if ( ! challan_invoicing_enabled() ) {
			wp_die( esc_html__( 'Invoicing is turned off in Challan.', 'webappick-pdf-invoice-for-woocommerce' ), '', array( 'response' => 400 ) );
		}

		$orders     = array();
		$is_manager = current_user_can( 'manage_woocommerce' );

		// v1 pro: extra roles allowed to generate documents (the
		// wpifw_invoice_can_generate list, behind the Extend access
		// toggle). Admins and shop managers always can; this only ever
		// widens access.
		if ( ! $is_manager
			&& apply_filters( 'challan_pro_active', false )
			&& $this->settings->get( 'challan_extend_access' ) ) {
			$allowed_roles = (array) $this->settings->get( 'wpifw_invoice_can_generate' );
			$is_manager    = array() !== array_intersect( $allowed_roles, (array) wp_get_current_user()->roles );
		}

		if ( isset( $_REQUEST['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
			$order = wc_get_order( absint( wp_unslash( $_REQUEST['order_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! $order instanceof \WC_Order ) {
				wp_die( esc_html__( 'Order not found.', 'webappick-pdf-invoice-for-woocommerce' ), '', array( 'response' => 404 ) );
			}
			$customer_ok = CustomerAccess::document_allowed( $order, $type, $this->settings );
			/**
			 * Whether a non-manager may download this document — add-ons
			 * widen it (Challan Pro: a WCFM vendor for orders with their
			 * products).
			 */
			$customer_ok = (bool) apply_filters( 'challan_document_access', $customer_ok, $order, $type );
			if ( ! $is_manager && ! $customer_ok ) {
				wp_die( esc_html__( 'You are not allowed to download this document.', 'webappick-pdf-invoice-for-woocommerce' ), '', array( 'response' => 403 ) );
			}
			$orders[] = $order;
		} elseif ( isset( $_REQUEST['order_ids'] ) && $is_manager ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['order_ids'] ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ids = array_slice( $ids, 0, 200 );
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( $order instanceof \WC_Order ) {
					$orders[] = $order;
				}
			}
		}

		if ( array() === $orders || ! isset( $this->renderers[ $type ] ) ) {
			wp_die( esc_html__( 'Nothing to generate.', 'webappick-pdf-invoice-for-woocommerce' ), '', array( 'response' => 400 ) );
		}

		// v1 Pro: a filter can refuse an order's invoice / credit note.
		if ( in_array( $type, array( 'invoice', 'credit_note' ), true ) ) {
			foreach ( $orders as $order ) {
				/** Preserved v1 filter. */
				if ( ! apply_filters( 'wpifw_invoice_generate_ignore', true, $order ) ) {
					wp_die( esc_html__( 'Invoice can not generated', 'webappick-pdf-invoice-for-woocommerce' ) );
				}
			}
		}

		$renderer = $this->renderers[ $type ];
		$html     = count( $orders ) === 1 ? $renderer->render( $orders[0] ) : $renderer->render_many( $orders );

		// v1 `&output=html`: the browser view of the document.
		if ( isset( $_REQUEST['output'] ) && 'html' === sanitize_key( wp_unslash( $_REQUEST['output'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
			PdfEngine::clean_output();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo BrowserView::standalone( $html, $type, $this->settings, $this->engine ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped field-by-field during rendering.
			exit;
		}

		try {
			$pdf = $this->engine->render( $html, $type );
		} catch ( \Throwable $e ) {
			wp_die( esc_html__( 'PDF generation failed.', 'webappick-pdf-invoice-for-woocommerce' ), '', array( 'response' => 500 ) );
		}

		$filename = $type . '-' . ( count( $orders ) === 1 ? $orders[0]->get_order_number() : gmdate( 'Y-m-d' ) ) . '.pdf';

		/** Legacy filter preserved. */
		$filename = apply_filters( 'woo_invoice_file_name', sanitize_file_name( $filename ), $orders[0] );

		PdfEngine::clean_output();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF stream.
		exit;
	}

	/**
	 * Handle the wpifw_save_pdf_template action: persist a validated template id.
	 *
	 * @return void
	 */
	public function save_pdf_template() {
		$this->verify_nonce();
		$this->require_manager();

		$template = isset( $_REQUEST['templateid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['templateid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $template && isset( $_REQUEST['template'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$template = sanitize_text_field( wp_unslash( $_REQUEST['template'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! array_key_exists( $template, Templates::all() ) ) {
			wp_send_json_error( array( 'message' => 'invalid_template' ), 400 );
		}

		$this->save_setting( 'wpifw_templateid', $template );
		wp_send_json_success( array( 'templateid' => $template ) );
	}

	/**
	 * Handle the wpifw_select_product_column action: persist the product column list.
	 *
	 * @return void
	 */
	public function select_product_column() {
		$this->verify_nonce();
		$this->require_manager();

		$columns = array();
		if ( isset( $_REQUEST['columns'] ) && is_array( $_REQUEST['columns'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$columns = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['columns'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$this->save_setting( 'wpifw_select_product_column', $columns );
		wp_send_json_success( array( 'columns' => $columns ) );
	}

	/**
	 * Handle the wpifw_get_product_column_show action: return the saved column list.
	 *
	 * @return void
	 */
	public function get_product_column() {
		$this->verify_nonce();
		$this->require_manager();

		$columns = get_option( 'wpifw_select_product_column', array() );
		wp_send_json_success( array( 'columns' => is_array( $columns ) ? $columns : array() ) );
	}

	/**
	 * Handle the prepare_fonts action: kick the background font download (v1 action name).
	 *
	 * @return void
	 */
	public function prepare_fonts() {
		$this->verify_nonce();
		$this->require_manager();

		FontDownloader::schedule();
		wp_send_json_success( FontDownloader::status() );
	}

	/**
	 * Acknowledge-only handler for retired notice actions.
	 *
	 * @return void
	 */
	public function ack() {
		wp_send_json_success();
	}

	/**
	 * Persist one setting through the v2 settings repository, the same path
	 * the REST settings endpoint uses (validation, sanitizing, storage format).
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return void
	 */
	private function save_setting( $key, $value ) {
		$result = $this->settings->save( array( $key => $value ), challan_is_pro_active() );
		if ( isset( $result['errors'][ $key ] ) ) {
			wp_send_json_error( array( 'message' => $result['errors'][ $key ] ), 400 );
		}
	}

	/**
	 * Run the v1 nonce check via `_wpnonce`: free's `woo_invoice_ajax_nonce`, or
	 * pro's `wpifw_pdf_nonce` (v1 pro signed its links with it). Access is
	 * still decided by the capability / order-ownership checks.
	 *
	 * @return void
	 */
	private function verify_nonce() {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woo_invoice_ajax_nonce' )
			&& ! ( challan_is_pro_active() && wp_verify_nonce( $nonce, 'wpifw_pdf_nonce' ) ) ) {
			wp_die( esc_html__( 'Failed security check', 'webappick-pdf-invoice-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Manager capability gate (v1 checked roles; capability is the
	 * hardened equivalent).
	 *
	 * @return void
	 */
	private function require_manager() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'webappick-pdf-invoice-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}
}
