<?php
/**
 * Document generation endpoints.
 *
 * @package Challan
 */

namespace Challan\Rest;

use Challan\Admin\DocumentButtons;
use Challan\Documents\AbstractRenderer;
use Challan\Documents\BrowserView;
use Challan\Documents\CustomerAccess;
use Challan\Documents\PdfEngine;
use Challan\Documents\Templates;
use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * GET  /challan/v1/orders/{id}/document — one order's document.
 * GET  /challan/v1/documents/bulk — filtered orders as one PDF (or a ZIP).
 * GET  /challan/v1/documents/bulk-count — how many orders a bulk run matches.
 * POST /challan/v1/orders/{id}/opened — record that a document was opened.
 * GET  /challan/v1/templates — template list + current selection.
 *
 * PDFs are generated on demand and streamed (no shared temp files —
 * v1's global temp-wipe race is not reproduced).
 *
 * @since 4.0.0
 */
class DocumentsController extends AbstractController {

	/**
	 * Default number of orders per bulk batch (unbounded generation would
	 * exhaust memory/time on shared hosting). Filterable through
	 * `challan_bulk_limit`; `page` selects the batch.
	 */
	const BULK_LIMIT = 200;

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
	 * Settings repository.
	 *
	 * @var Repository
	 */
	private $settings;

	/**
	 * Create the controller.
	 *
	 * @param array<string,AbstractRenderer> $renderers Renderers by type.
	 * @param PdfEngine                      $engine    PDF engine.
	 * @param Repository                     $settings  Settings repository.
	 */
	public function __construct( array $renderers, PdfEngine $engine, Repository $settings ) {
		$this->renderers = $renderers;
		$this->engine    = $engine;
		$this->settings  = $settings;
	}

	/**
	 * Permission for the single-document route (v1 rules preserved):
	 * managers always; the order's own customer for the invoice (download
	 * toggle and status allowlist) or, with Pro, the credit note of a
	 * refunded order; add-ons through `challan_document_access`; a request
	 * carrying the order key for the email download button (invoices) or,
	 * with Pro, guest links.
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error True when allowed, otherwise
	 *                        challan_rest_forbidden (401 logged out,
	 *                        403 logged in).
	 */
	public function document_permission_check( \WP_REST_Request $request ) {
		if ( current_user_can( $this->capability ) ) {
			return true;
		}

		$order = wc_get_order( (int) $request['id'] );
		if (
			$order instanceof \WC_Order
			/** Add-ons may widen access (see LegacyAjax). */
			&& apply_filters( 'challan_document_access', CustomerAccess::document_allowed( $order, (string) $request['type'], $this->settings ), $order, (string) $request['type'] )
		) {
			return true;
		}

		// Email download button: the link carries the ORDER KEY as the
		// ownership proof (WooCommerce's order-received convention), so
		// it works without a login session. Only while the feature is
		// on, invoices only, still gated by the status allowlist.
		$key = (string) $request['key'];
		if (
			'' !== $key
			&& $order instanceof \WC_Order
			&& 'invoice' === (string) $request['type']
			&& hash_equals( $order->get_order_key(), $key )
			&& $this->settings->get( 'challan_email_download_button' )
			&& challan_invoicing_enabled()
			&& CustomerAccess::status_allowed( $order, $this->settings )
		) {
			return true;
		}

		// Challan Pro, v1 `wpifw_allow_non_logged_in_users` filter: guests
		// get the invoice/credit-note buttons on the order-received page,
		// again with the order key as the ownership proof.
		if (
			'' !== $key
			&& $order instanceof \WC_Order
			&& hash_equals( $order->get_order_key(), $key )
			&& CustomerAccess::guest_allowed( $order, (string) $request['type'], $this->settings )
		) {
			return true;
		}

		return new \WP_Error(
			'challan_rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'webappick-pdf-invoice-for-woocommerce' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Register this controller's REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		/**
		 * Generates one order's document and streams it.
		 *
		 * Used by the order screens' document buttons, the My Account
		 * download links and the email download button.
		 *
		 * Route:       GET /challan/v1/orders/{id}/document
		 * Permission:  Own rule (document_permission_check()): managers; the
		 *              order's customer when the download settings allow it;
		 *              a request with the order `key` (email download button,
		 *              invoices only; with Pro, guest links); or access
		 *              granted through the `challan_document_access` filter.
		 * Licence:     None (Pro document types need Challan Pro active)
		 * Request:     Path `id`; query `type`, `key`, `output` (see args)
		 * Response:    File, then exits: application/pdf (inline for
		 *              `output=pdf`, attachment for `output=download`) or
		 *              text/html (`output=html`). See get_document_schema().
		 * Errors:      challan_rest_forbidden (401/403) no access
		 *              challan_rest_order_not_found (404) no such order
		 *              challan_rest_type_unavailable (501) no renderer for
		 *              the type
		 *              challan_generate_ignored (403) the v1
		 *              `wpifw_invoice_generate_ignore` filter refused it
		 *              challan_rest_pdf_failed (500) PDF generation failed
		 *
		 * @since 4.0.0
		 * @see   DocumentsController::get_document()
		 */
		$this->register_schema_route(
			'/orders/(?P<id>\d+)/document',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_document' ),
				'permission_callback' => array( $this, 'document_permission_check' ),
				'args'                => array(
					'id'     => Schema::order_id(),
					'type'   => Schema::document_type(),
					'key'    => array(
						'description' => __( 'WooCommerce order key, the ownership proof for downloads without a login (email download button; with Pro, guest links).', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'string',
						'default'     => '',
					),
					'output' => array(
						'description' => __( '"pdf" shows the PDF in the browser, "download" downloads it, "html" returns the document as a web page. Any other value is treated as "pdf".', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'string',
						'default'     => 'pdf',
					),
				),
			),
			'get_document_schema'
		);

		$bulk_args = array_merge(
			array( 'type' => Schema::document_type() ),
			Schema::bulk_filters(),
			array(
				'output' => array(
					'description' => __( '"zip" sends one PDF per order in a ZIP archive (needs an active Challan Pro licence); any other value sends one merged PDF.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'string',
					'default'     => 'merged',
				),
				'page'   => Schema::page(),
			)
		);

		/**
		 * Generates the documents of the filtered orders as one download.
		 *
		 * Orders are matched newest first and sent in batches of 200 (the
		 * `challan_bulk_limit` filter changes the size); `page` picks the
		 * batch. Credit notes always use refunded orders, whatever
		 * `statuses` says.
		 *
		 * Route:       GET /challan/v1/documents/bulk
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     Customer and payment filters and ZIP output need an
		 *              active Challan Pro licence
		 * Request:     Query — see the route's args
		 * Response:    File, then exits: application/pdf attachment (merged)
		 *              or application/zip. See get_bulk_schema().
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *              challan_rest_type_unavailable (501) no renderer for
		 *              the type
		 *              challan_pro_output (403) ZIP without a Pro licence
		 *              challan_zip_unavailable (500) PHP ZipArchive missing
		 *              challan_pro_filter (403) customer or payment filter
		 *              without a Pro licence
		 *              challan_rest_no_orders (404) no order matches
		 *              challan_zip_failed (500) the ZIP could not be created
		 *              challan_rest_pdf_failed (500) PDF generation failed
		 *
		 * @since 4.0.0
		 * @see   DocumentsController::get_bulk()
		 */
		$this->register_schema_route(
			'/documents/bulk',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bulk' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $bulk_args,
			),
			'get_bulk_schema'
		);

		/**
		 * Counts the orders a bulk run would include.
		 *
		 * Lets the Bulk screen report "nothing found" or offer batches
		 * before opening a download. Takes the same arguments as
		 * /documents/bulk; `output` and `page` are ignored.
		 *
		 * Route:       GET /challan/v1/documents/bulk-count
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     Customer and payment filters need an active Challan
		 *              Pro licence
		 * Request:     Query — see the route's args
		 * Response:    200 JSON — see get_bulk_count_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *              challan_rest_type_unavailable (501) no renderer for
		 *              the type
		 *              challan_pro_filter (403) customer or payment filter
		 *              without a Pro licence
		 *
		 * @since 4.0.0
		 * @see   DocumentsController::get_bulk_count()
		 */
		$this->register_schema_route(
			'/documents/bulk-count',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bulk_count' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $bulk_args,
			),
			'get_bulk_count_schema'
		);

		/**
		 * Records that a document was opened from the orders list or the
		 * order screen (v1 Pro's green "done" check).
		 *
		 * Saves the time in order meta only while Challan Pro is active;
		 * without Pro it returns `opened: false` and saves nothing.
		 *
		 * Route:       POST /challan/v1/orders/{id}/opened
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None (Challan Pro active)
		 * Request:     Path `id`; `type` (see args)
		 * Response:    200 JSON — see get_opened_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *              challan_order_not_found (404) no such order
		 *
		 * @since 4.0.0
		 * @see   DocumentsController::mark_opened()
		 */
		$this->register_schema_route(
			'/orders/(?P<id>\d+)/opened',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_opened' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					// No minimum: /orders/0/opened reaches the callback and
					// gets challan_order_not_found (404), as before.
					'id'   => array(
						'description' => __( 'WooCommerce order ID.', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'integer',
					),
					'type' => Schema::document_type(),
				),
			),
			'get_opened_schema'
		);

		/**
		 * Lists the document templates and the selected one.
		 *
		 * Route:       GET /challan/v1/templates
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_templates_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0
		 * @see   DocumentsController::get_templates()
		 */
		$this->register_schema_route(
			'/templates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_templates' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_templates_schema'
		);
	}

	/**
	 * Record that a document was opened from the admin (Challan Pro).
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Response matching
	 *                                     get_opened_schema(), or
	 *                                     challan_order_not_found (404).
	 */
	public function mark_opened( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'challan_order_not_found', __( 'Order not found.', 'webappick-pdf-invoice-for-woocommerce' ), array( 'status' => 404 ) );
		}
		if ( ! challan_is_pro_active() ) {
			return rest_ensure_response( array( 'opened' => false ) );
		}

		DocumentButtons::mark_opened( $order, (string) $request['type'] );

		return rest_ensure_response( array( 'opened' => true ) );
	}

	/**
	 * Generate and stream one order document.
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Error Only on failure. On success the PDF or HTML is
	 *                   streamed and the request exits.
	 */
	public function get_document( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error(
				'challan_rest_order_not_found',
				__( 'Order not found.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		$renderer = $this->renderer( (string) $request['type'] );
		if ( null === $renderer ) {
			return $this->unavailable_type_error( (string) $request['type'] );
		}

		/** Preserved v1 filter: refuse an order's invoice / credit note. */
		if ( in_array( $renderer->type(), array( 'invoice', 'credit_note' ), true ) && ! apply_filters( 'wpifw_invoice_generate_ignore', true, $order ) ) {
			return new \WP_Error( 'challan_generate_ignored', __( 'Invoice can not generated', 'webappick-pdf-invoice-for-woocommerce' ), array( 'status' => 403 ) );
		}

		$html     = $renderer->render( $order );
		$filename = $renderer->type() . '-' . $order->get_order_number();

		return $this->stream( $html, $renderer->type(), (string) $request['output'], $filename, $order );
	}

	/**
	 * Generate a merged document (or a ZIP) for filtered orders.
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Error Only on failure. On success the PDF or ZIP is
	 *                   streamed and the request exits.
	 */
	public function get_bulk( \WP_REST_Request $request ) {
		$renderer = $this->renderer( (string) $request['type'] );
		if ( null === $renderer ) {
			return $this->unavailable_type_error( (string) $request['type'] );
		}

		$output = 'zip' === (string) $request['output'] ? 'zip' : 'merged';
		if ( 'zip' === $output ) {
			if ( ! apply_filters( 'challan_pro_licensed', false ) ) {
				return new \WP_Error(
					'challan_pro_output',
					__( 'Individual PDFs (ZIP) need an active Challan Pro license.', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'status' => 403 )
				);
			}
			if ( ! class_exists( \ZipArchive::class ) ) {
				return new \WP_Error(
					'challan_zip_unavailable',
					__( 'The ZipArchive PHP extension is not available on this server.', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'status' => 500 )
				);
			}
		}

		$orders = $this->collect_bulk_orders( $request, $renderer );
		if ( is_wp_error( $orders ) ) {
			return $orders;
		}

		$filename = $renderer->type() . '-bulk-' . gmdate( 'Y-m-d' );
		if ( (int) $request['page'] > 1 ) {
			// Later batches get their number so downloads do not collide.
			$filename .= '-' . (int) $request['page'];
		}

		if ( 'zip' === $output ) {
			return $this->stream_zip( $orders, $renderer, $filename );
		}

		$html = $renderer->render_many( array_values( $orders ) );
		return $this->stream( $html, $renderer->type(), 'download', $filename, null );
	}

	/**
	 * Number of orders a bulk run matches, and how many fit in one batch.
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Response matching
	 *                                     get_bulk_count_schema(), or an
	 *                                     error listed on the route.
	 */
	public function get_bulk_count( \WP_REST_Request $request ) {
		$renderer = $this->renderer( (string) $request['type'] );
		if ( null === $renderer ) {
			return $this->unavailable_type_error( (string) $request['type'] );
		}

		$ids = $this->collect_bulk_order_ids( $request, $renderer );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		return rest_ensure_response(
			array(
				'total' => count( $ids ),
				'limit' => $this->bulk_limit(),
			)
		);
	}

	/**
	 * Resolve the filtered order list for a bulk run.
	 *
	 * Public so the query semantics (credit-note refunded override,
	 * pro-gated filters, multi-customer merge) are testable without
	 * streaming a response.
	 *
	 * @param \WP_REST_Request                    $request  Request.
	 * @param \Challan\Documents\AbstractRenderer $renderer Resolved renderer.
	 * @return \WC_Order[]|\WP_Error
	 */
	public function collect_bulk_orders( \WP_REST_Request $request, $renderer ) {
		$ids = $this->collect_bulk_order_ids( $request, $renderer );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$page  = max( 1, (int) $request['page'] );
		$limit = $this->bulk_limit();
		$ids   = array_slice( $ids, ( $page - 1 ) * $limit, $limit );

		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$orders[] = $order;
			}
		}

		if ( array() === $orders ) {
			return new \WP_Error(
				'challan_rest_no_orders',
				__( 'No orders match the selected filters.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		return $orders;
	}

	/**
	 * Orders per bulk batch. Filterable so slow hosts can lower it.
	 *
	 * @return int
	 */
	private function bulk_limit() {
		/**
		 * Filter the number of orders rendered per bulk PDF batch.
		 *
		 * @param int $limit Default 200.
		 */
		return max( 1, (int) apply_filters( 'challan_bulk_limit', self::BULK_LIMIT ) );
	}

	/**
	 * Every order id a bulk run matches, newest first (v1 order: free and
	 * pro passed wc_get_orders() results straight through, date DESC).
	 *
	 * @param \WP_REST_Request                    $request  Request.
	 * @param \Challan\Documents\AbstractRenderer $renderer Resolved renderer.
	 * @return int[]|\WP_Error
	 */
	private function collect_bulk_order_ids( \WP_REST_Request $request, $renderer ) {
		$args = array(
			'limit'   => -1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
			'return'  => 'ids',
		);

		// Explicit id selection (order-list bulk actions) wins over filters.
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) );
		if ( array() !== $ids ) {
			$args['post__in'] = $ids;
		}

		$statuses = array_filter( array_map( 'sanitize_key', explode( ',', (string) $request['statuses'] ) ) );
		if ( array() !== $statuses ) {
			$args['status'] = $statuses;
		}

		// v1 pro only ever bulk-generated credit notes for refunded orders.
		if ( 'credit_note' === $renderer->type() ) {
			$args['status'] = array( 'refunded' );
		}

		// Customer/payment filters are a pro feature (v1 free rendered the
		// inputs but never applied them; v2 refuses instead of ignoring).
		$email       = sanitize_email( (string) $request['email'] );
		$customer_id = (int) $request['customer_id'];
		$payment     = sanitize_text_field( (string) $request['payment'] );
		$customers   = array_filter( array_map( 'sanitize_text_field', explode( ',', (string) $request['customers'] ) ) );
		if ( '' !== $email || $customer_id > 0 || array() !== $customers || ( '' !== $payment && 'all' !== $payment ) ) {
			if ( ! apply_filters( 'challan_pro_licensed', false ) ) {
				return new \WP_Error(
					'challan_pro_filter',
					__( 'Filtering by customer or payment method needs an active Challan Pro license.', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'status' => 403 )
				);
			}
			if ( '' !== $email ) {
				$args['customer'] = $email;
			}
			if ( $customer_id > 0 ) {
				$args['customer_id'] = $customer_id;
			}
			if ( '' !== $payment && 'all' !== $payment ) {
				$args['payment_method'] = $payment;
			}
		}

		$after  = sanitize_text_field( (string) $request['after'] );
		$before = sanitize_text_field( (string) $request['before'] );
		if ( '' !== $after && '' !== $before ) {
			$args['date_created'] = $after . '...' . $before;
		} elseif ( '' !== $after ) {
			$args['date_created'] = '>=' . $after;
		} elseif ( '' !== $before ) {
			$args['date_created'] = '<=' . $before;
		}

		// Multiple customers: one query per customer (the `customer` arg
		// accepts a user id or billing email but only a single value),
		// merged and re-sorted newest first.
		if ( array() !== $customers ) {
			$args['return'] = 'objects';
			$merged         = array();
			foreach ( $customers as $customer ) {
				$args['customer'] = is_numeric( $customer ) ? (int) $customer : $customer;
				foreach ( wc_get_orders( $args ) as $order ) {
					if ( $order instanceof \WC_Order ) {
						$merged[ $order->get_id() ] = $order;
					}
				}
			}
			uasort(
				$merged,
				static function ( $a, $b ) {
					return $b->get_date_created() <=> $a->get_date_created();
				}
			);
			return array_keys( $merged );
		}

		return array_map( 'absint', wc_get_orders( $args ) );
	}

	/**
	 * One PDF per order, zipped and streamed.
	 *
	 * @param \WC_Order[]                         $orders   Orders.
	 * @param \Challan\Documents\AbstractRenderer $renderer Renderer.
	 * @param string                              $filename Archive name (no extension).
	 * @return \WP_Error Only on failure — success streams and exits.
	 */
	private function stream_zip( $orders, $renderer, $filename ) {
		// wp_tempnam() lives in an admin include that REST requests don't load.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( $filename . '.zip' );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error(
				'challan_zip_failed',
				__( 'Could not create the ZIP archive.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}

		try {
			foreach ( $orders as $order ) {
				$html = $renderer->render( $order );
				$pdf  = $this->engine->render( $html, $renderer->type() );
				$name = sanitize_file_name( $renderer->type() . '-' . $order->get_order_number() . '.pdf' );

				/** This filter is documented in self::stream(). */
				$name = apply_filters( 'challan_document_filename', $name, $order, $renderer->type() );
				$zip->addFromString( $name, $pdf );
			}
		} catch ( \Throwable $e ) {
			$zip->close();
			wp_delete_file( $tmp );
			return new \WP_Error(
				'challan_rest_pdf_failed',
				__( 'PDF generation failed.', 'webappick-pdf-invoice-for-woocommerce' ),
				array(
					'status' => 500,
					'detail' => $e->getMessage(),
				)
			);
		}

		$zip->close();

		PdfEngine::clean_output();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename . '.zip' ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a locally generated temp archive.
		wp_delete_file( $tmp );
		exit;
	}

	/**
	 * Template list with pro flags and current selection.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_templates_schema().
	 */
	public function get_templates() {
		$templates = array();
		foreach ( Templates::all() as $id => $template ) {
			$templates[] = array(
				'id'    => $id,
				'label' => $template['label'],
				'pro'   => ! empty( $template['pro'] ),
			);
		}

		return rest_ensure_response(
			array(
				'templates' => $templates,
				'selected'  => get_option( 'wpifw_templateid', \Challan\Documents\Templates::DEFAULT_TEMPLATE ),
			)
		);
	}

	/**
	 * Resolve a renderer for a type, or null if none registered.
	 *
	 * The injected map already went through the
	 * `challan_document_renderers` filter in Plugin::renderers() — do
	 * not re-apply it here (pro's 3-arg callback would fatal).
	 *
	 * @param string $type Type id.
	 * @return AbstractRenderer|null
	 */
	private function renderer( $type ) {
		return isset( $this->renderers[ $type ] ) && $this->renderers[ $type ] instanceof AbstractRenderer
			? $this->renderers[ $type ]
			: null;
	}

	/**
	 * Error for a registered-but-unrenderable type.
	 *
	 * @param string $type Type id.
	 * @return \WP_Error
	 */
	private function unavailable_type_error( $type ) {
		return new \WP_Error(
			'challan_rest_type_unavailable',
			/* translators: %s: document type id. */
			sprintf( __( 'No renderer is available for "%s".', 'webappick-pdf-invoice-for-woocommerce' ), $type ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Stream HTML or the rendered PDF and exit.
	 *
	 * @param string         $html     Document HTML.
	 * @param string         $type     Document type id.
	 * @param string         $output   pdf|download|html.
	 * @param string         $filename Base filename without extension.
	 * @param \WC_Order|null $order    Order for the filename filter.
	 * @return \WP_Error Only on failure.
	 */
	private function stream( $html, $type, $output, $filename, $order ) {
		if ( 'html' === $output ) {
			PdfEngine::clean_output();
			header( 'Content-Type: text/html; charset=utf-8' );
			$html = BrowserView::standalone( $html, $type, $this->settings, $this->engine );
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped field-by-field during rendering.
			exit;
		}

		try {
			$pdf = $this->engine->render( $html, $type );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'challan_rest_pdf_failed',
				__( 'PDF generation failed.', 'webappick-pdf-invoice-for-woocommerce' ),
				array(
					'status' => 500,
					'detail' => $e->getMessage(),
				)
			);
		}

		$filename = sanitize_file_name( $filename . '.pdf' );

		/** Preserved v1 filter (name, document type, order id). */
		$filename = apply_filters( 'woo_invoice_file_name', $filename, $type, $order instanceof \WC_Order ? $order->get_id() : 0 );

		/**
		 * Filter the download filename.
		 *
		 * @param string         $filename File name.
		 * @param \WC_Order|null $order    Order (null for bulk).
		 * @param string         $type     Document type id.
		 */
		$filename = apply_filters( 'challan_document_filename', $filename, $order, $type );

		$disposition = 'download' === $output ? 'attachment' : 'inline';
		PdfEngine::clean_output();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF stream.
		exit;
	}

	/**
	 * Schema of GET /orders/{id}/document (a file on success).
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_document_schema() {
		return Schema::file(
			'document',
			__( 'On success the document is sent as a file, not JSON: application/pdf (inline for output=pdf, attachment for output=download) or text/html (output=html). A JSON body is only sent for errors.', 'webappick-pdf-invoice-for-woocommerce' )
		);
	}

	/**
	 * Schema of GET /documents/bulk (a file on success).
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_bulk_schema() {
		return Schema::file(
			'documents-bulk',
			__( 'On success the documents are sent as a file, not JSON: one merged application/pdf attachment, or an application/zip with one PDF per order (output=zip). A JSON body is only sent for errors.', 'webappick-pdf-invoice-for-woocommerce' )
		);
	}

	/**
	 * Response schema of GET /documents/bulk-count.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_bulk_count_schema() {
		return Schema::object(
			'documents-bulk-count',
			array(
				'total' => array(
					'description' => __( 'Number of orders the filters match.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				'limit' => array(
					'description' => __( 'Orders per batch (default 200, filterable through challan_bulk_limit). The number of batches is total / limit, rounded up.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Response schema of POST /orders/{id}/opened.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_opened_schema() {
		return Schema::object(
			'order-opened',
			array(
				'opened' => array(
					'description' => __( 'True when the time was saved; false without Challan Pro (nothing is saved).', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'boolean',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Response schema of GET /templates.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_templates_schema() {
		return Schema::object(
			'templates',
			array(
				'templates' => array(
					'description' => __( 'Every registered document template.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array( 'type' => 'string' ),
							'label' => array( 'type' => 'string' ),
							'pro'   => array(
								'description' => __( 'Whether the template needs Challan Pro.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'boolean',
							),
						),
					),
				),
				'selected'  => array(
					'description' => __( 'The stored wpifw_templateid option (the template in use).', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}
}
