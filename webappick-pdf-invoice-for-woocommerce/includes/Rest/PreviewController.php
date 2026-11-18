<?php
/**
 * Live document preview endpoints.
 *
 * @package Challan
 */

namespace Challan\Rest;

use Challan\Documents\BrowserView;
use Challan\Documents\DeliveryAddressRenderer;
use Challan\Documents\InvoiceNumber;
use Challan\Documents\InvoiceRenderer;
use Challan\Documents\PackingSlipRenderer;
use Challan\Settings\OverlayRepository;
use Challan\Settings\Registry;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * POST /challan/v1/preview — renders a document as HTML using the UI's
 * UNSAVED settings (typed overrides), without persisting anything.
 * GET /challan/v1/preview/orders — recent orders for the preview picker.
 *
 * The returned HTML is browser-adapted: mPDF-only tags (htmlpagefooter)
 * are converted to visible equivalents, so the preview is close but the
 * "Open as PDF" action remains the exact-output check.
 *
 * @since 4.0.0
 */
class PreviewController extends AbstractController {

	/**
	 * Settings registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Create the controller.
	 *
	 * @param Registry $registry Settings registry (the overlay allowlist).
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register this controller's REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		/**
		 * Renders a document with the settings screen's unsaved values.
		 *
		 * Nothing is saved, and no invoice number is stamped on the order.
		 * Without `status`, one order is rendered (`order_id`, or the newest
		 * order). With `status` (the Labels screen), a sheet of the newest
		 * orders with that status is rendered. HTML output is adapted for the
		 * browser (mPDF-only tags made visible); "pdf" returns the exact
		 * engine output.
		 *
		 * Route:       POST /challan/v1/preview
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     JSON body — `type`, `order_id`, `status`, `format`,
		 *              `overrides` (see the route's args)
		 * Response:    200 JSON — see get_preview_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *              challan_rest_no_preview_order (404) no order to show
		 *              challan_rest_preview_type (400) no preview renderer
		 *              for `type`
		 *
		 * @since 4.0.0
		 * @see   PreviewController::render_preview()
		 */
		$this->register_schema_route(
			'/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'render_preview' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'type'      => array(
						'description' => __( 'Document type to render. A type without a preview renderer returns challan_rest_preview_type.', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'string',
						'default'     => 'invoice',
					),
					'order_id'  => array(
						'description' => __( 'Order to render. 0 or omitted uses the newest order. Ignored when status is set.', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'integer',
						'default'     => 0,
					),
					'status'    => array(
						'description' => __( 'Order status, for example "completed". When set, renders a sheet of the newest orders with this status (up to 60) instead of one order. WooCommerce ignores a status it does not know, so an unknown value matches orders of every status.', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'string',
						'default'     => '',
					),
					'format'    => array(
						'description' => __( '"pdf" returns the PDF (base64); any other value returns HTML.', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'string',
						'default'     => 'html',
					),
					'overrides' => array(
						'description' => __( 'Unsaved settings: setting key => value. Keys that are not registered settings are ignored; values are converted to the setting\'s type.', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'object',
						'default'     => array(),
					),
				),
			),
			'get_preview_schema'
		);

		/**
		 * Lists the 10 newest orders for the preview order picker.
		 *
		 * Route:       GET /challan/v1/preview/orders
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_recent_orders_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0
		 * @see   PreviewController::recent_orders()
		 */
		$this->register_schema_route(
			'/preview/orders',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'recent_orders' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_recent_orders_schema'
		);
	}

	/**
	 * Render one document with overrides applied.
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Response matching
	 *                                     get_preview_schema(), or an error
	 *                                     listed on the route.
	 */
	public function render_preview( \WP_REST_Request $request ) {
		$params    = $request->get_json_params();
		$type      = isset( $params['type'] ) ? sanitize_key( (string) $params['type'] ) : 'invoice';
		$order_id  = isset( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$status    = isset( $params['status'] ) ? sanitize_key( (string) $params['status'] ) : '';
		$format    = isset( $params['format'] ) && 'pdf' === $params['format'] ? 'pdf' : 'html';
		$overrides = isset( $params['overrides'] ) && is_array( $params['overrides'] ) ? $params['overrides'] : array();

		$order = null;
		if ( '' === $status ) {
			$order = $order_id > 0 ? wc_get_order( $order_id ) : $this->latest_order();
			if ( ! $order instanceof \WC_Order ) {
				return new \WP_Error(
					'challan_rest_no_preview_order',
					__( 'No order available to preview. Create a test order first.', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'status' => 404 )
				);
			}
		}

		$repository = new OverlayRepository( $this->registry, $overrides );
		// Read-only: a preview never stamps a number on the order (it would
		// even use the unsaved prefix / suffix being edited).
		$numbers = new InvoiceNumber( $repository, false );

		$renderers = array(
			'invoice'          => new InvoiceRenderer( $repository, $numbers ),
			'packing_slip'     => new PackingSlipRenderer( $repository, $numbers ),
			'delivery_address' => new DeliveryAddressRenderer( $repository ),
		);

		/** This filter is documented in includes/Plugin.php */
		$renderers = apply_filters( 'challan_document_renderers', $renderers, $repository, $numbers );

		if ( ! isset( $renderers[ $type ] ) || ! $renderers[ $type ] instanceof \Challan\Documents\AbstractRenderer ) {
			return new \WP_Error(
				'challan_rest_preview_type',
				__( 'This document type has no preview renderer.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}
		$renderer = $renderers[ $type ];

		if ( '' !== $status ) {
			// Status mode (Labels): fill the sheet with the most recent
			// orders of that status — column count comes from the OVERLAID
			// settings so unsaved edits preview live. Rows flow naturally
			// (auto rows per page), so fetch roughly a page's worth.
			$columns = max( 1, (int) $repository->get( 'wpifw_delivery_address_num_col' ) );
			$orders  = wc_get_orders(
				array(
					'status'  => $status,
					'type'    => 'shop_order',
					'limit'   => min( 60, $columns * 10 ),
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);
			$orders  = array_values( array_filter( $orders, static fn( $o ) => $o instanceof \WC_Order ) );
			if ( array() === $orders ) {
				return new \WP_Error(
					'challan_rest_no_preview_order',
					__( 'No orders with this status yet — pick another status.', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'status' => 404 )
				);
			}
			$html  = $renderer->render_many( $orders );
			$order = $orders[0];
		} else {
			$html = $renderer->render( $order );
		}

		// PDF mode: the exact engine output with the same overlaid
		// settings, base64-wrapped for the JSON transport (the SPA turns
		// it into a blob URL for the preview iframe).
		if ( 'pdf' === $format ) {
			$engine = new \Challan\Documents\PdfEngine( $repository );
			return rest_ensure_response(
				array(
					'pdf'      => base64_encode( $engine->render( $html, $type ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe JSON transport.
					'order_id' => $order->get_id(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'html'     => BrowserView::adapt( $html, $repository, $type ),
				'order_id' => $order->get_id(),
			)
		);
	}

	/**
	 * Recent orders for the preview picker.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_recent_orders_schema().
	 */
	public function recent_orders() {
		$orders = wc_get_orders(
			array(
				'limit'   => 10,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
			)
		);

		$list = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$list[] = array(
				'id'     => $order->get_id(),
				'number' => $order->get_order_number(),
				'name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'total'  => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ) ),
				'status' => wc_get_order_status_name( $order->get_status() ),
			);
		}

		return rest_ensure_response( array( 'orders' => $list ) );
	}

	/**
	 * Newest order on the store (v1's sidebar preview used the same idea).
	 *
	 * @return \WC_Order|null
	 */
	private function latest_order() {
		$orders = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
			)
		);

		return isset( $orders[0] ) && $orders[0] instanceof \WC_Order ? $orders[0] : null;
	}

	/**
	 * Response schema of POST /preview.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_preview_schema() {
		return Schema::object(
			'preview',
			array(
				'html'     => array(
					'description' => __( 'The rendered document, adapted for the browser. Sent when format is not "pdf".', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				'pdf'      => array(
					'description' => __( 'The rendered PDF, base64-encoded. Sent instead of "html" when format is "pdf".', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				'order_id' => array(
					'description' => __( 'The order that was rendered (in status mode, the newest one).', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Response schema of GET /preview/orders.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_recent_orders_schema() {
		return Schema::object(
			'preview-orders',
			array(
				'orders' => array(
					'description' => __( 'The 10 newest orders, newest first.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array(
								'description' => __( 'Order ID.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'integer',
							),
							'number' => array(
								'description' => __( 'Order number as shown to customers (may differ from the ID).', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
							'name'   => array(
								'description' => __( 'Billing first and last name.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
							'total'  => array(
								'description' => __( 'Formatted order total as plain text, with the currency symbol.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
							'status' => array(
								'description' => __( 'Translated order status name.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
						),
					),
				),
			)
		);
	}
}
