<?php
/**
 * Invoice PDF email attachments.
 *
 * @package Challan
 */

namespace Challan\Documents;

use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Attaches the invoice PDF to the WooCommerce emails selected in
 * `wpifw_email_attach_check_list` (exact v1 email ids).
 *
 * Files are written per-order under uploads/challan/attachments with a
 * non-guessable name and cleaned up by a daily cron — v1's
 * wipe-the-whole-folder-per-request race is not reproduced.
 */
class EmailAttachments {

	const CLEANUP_HOOK = 'challan_cleanup_attachments';

	/**
	 * Settings repository.
	 *
	 * @var Repository
	 */
	private $settings;

	/**
	 * Invoice HTML renderer.
	 *
	 * @var InvoiceRenderer
	 */
	private $renderer;

	/**
	 * PDF engine that turns the rendered HTML into a file.
	 *
	 * @var PdfEngine
	 */
	private $engine;

	/**
	 * Invoice number allocator.
	 *
	 * @var InvoiceNumber
	 */
	private $number;

	/**
	 * Wire up the attachment service.
	 *
	 * @param Repository      $settings Settings repository.
	 * @param InvoiceRenderer $renderer Invoice renderer.
	 * @param PdfEngine       $engine   PDF engine.
	 * @param InvoiceNumber   $number   Invoice number allocator.
	 */
	public function __construct( Repository $settings, InvoiceRenderer $renderer, PdfEngine $engine, InvoiceNumber $number ) {
		$this->settings = $settings;
		$this->renderer = $renderer;
		$this->engine   = $engine;
		$this->number   = $number;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach' ), 10, 3 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_download_button' ), 91, 4 );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
	}

	/**
	 * Download Invoice button in customer order emails (v1 shipped this
	 * commented out; enabled via challan_email_download_button). The
	 * link authorizes by ORDER KEY so it works without a login session,
	 * still gated by the customer download-status allowlist.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public function email_download_button( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( ! $order instanceof \WC_Order
			|| $sent_to_admin
			|| $plain_text
			|| ! challan_invoicing_enabled()
			|| ! $this->settings->get( 'challan_email_download_button' )
			|| ! CustomerAccess::status_allowed( $order, $this->settings )
			|| CustomerAccess::held( $order, 'invoice' ) ) {
			return;
		}

		$args = array(
			'type' => 'invoice',
			'key'  => $order->get_order_key(),
		);
		if ( $this->settings->get( 'wpifw_output_type_html' ) ) {
			$args['output'] = 'html';
		} else {
			$args['output'] = 'download';
		}
		$url = add_query_arg( $args, rest_url( sprintf( 'challan/v1/orders/%d/document', $order->get_id() ) ) );

		$label = (string) $this->settings->get( 'wpifw_DOWNLOAD_INVOICE_TEXT' );
		$label = '' !== $label ? $label : __( 'Download Invoice', 'webappick-pdf-invoice-for-woocommerce' );

		printf(
			'<p style="margin:16px 0"><a href="%s" target="_blank" rel="noopener" style="display:inline-block;padding:10px 18px;background:#1570ef;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">%s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Attach the invoice PDF when this email id is enabled.
	 *
	 * @param array<int,string> $attachments  Existing attachments.
	 * @param string            $email_id     WooCommerce email id.
	 * @param mixed             $email_object Email object context.
	 * @return array<int,string>
	 */
	public function attach( $attachments, $email_id, $email_object ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = array();
		}
		if ( ! $email_object instanceof \WC_Order ) {
			return $attachments;
		}
		if ( ! challan_invoicing_enabled() || ! $this->settings->get( 'wpifw_order_email' ) ) {
			return $attachments;
		}

		// v1 pro rule: skip free (0.00) orders unless the pro option
		// wpifw_free_order_attachment is enabled. Free-only sites keep
		// attaching, exactly as v1 free did.
		if ( apply_filters( 'challan_pro_active', false )
			&& 0.0 === (float) $email_object->get_total()
			&& ! $this->settings->get( 'wpifw_free_order_attachment' ) ) {
			return $attachments;
		}

		$enabled = $this->settings->get( 'wpifw_email_attach_check_list' );
		if ( ! is_array( $enabled ) || array() === $enabled ) {
			$enabled = self::default_email_ids();
		}

		/** Preserved v1 filter. */
		$enabled = apply_filters( 'woo_invoice_email_types', $enabled );

		if ( ! is_array( $enabled ) || ! self::email_enabled( (string) $email_id, $enabled ) ) {
			return $attachments;
		}

		$type = (string) apply_filters( 'challan_email_attachment_type', 'invoice', $email_id, $email_object );
		if ( CustomerAccess::held( $email_object, $type ) ) {
			/**
			 * An email went out without its document because the document is
			 * held (see CustomerAccess::held()); Challan Pro sends it once
			 * released.
			 *
			 * @since 4.0.0
			 *
			 * @param \WC_Order $order    Order.
			 * @param string    $type     Document type id.
			 * @param string    $email_id WooCommerce email id.
			 */
			do_action( 'challan_document_held_email', $email_object, $type, (string) $email_id );
			return $attachments;
		}

		try {
			$path = $this->write_pdf( $email_object, (string) $email_id );
			if ( '' !== $path ) {
				$attachments[] = $path;
			}
		} catch ( \Throwable $e ) {
			// Never break email delivery over a PDF failure — log it.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'Challan: invoice attachment failed for order %d: %s', $email_object->get_id(), $e->getMessage() ),
					array( 'source' => 'challan' )
				);
			}
		}

		return $attachments;
	}

	/**
	 * Emails that get the invoice when the list was never saved: v1 free's
	 * six, or v1 pro's ten (plus the subscription emails when WooCommerce
	 * Subscriptions is active) while Challan Pro is active.
	 *
	 * @return array<int,string>
	 */
	private static function default_email_ids() {
		if ( ! challan_is_pro_active() ) {
			return array( 'new_order', 'customer_invoice', 'customer_completed_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_processing_order' );
		}

		$ids = array( 'new_order', 'customer_processing_order', 'customer_invoice', 'customer_completed_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_note', 'cancelled_order', 'failed_order' );
		if ( class_exists( 'WC_Subscriptions' ) ) {
			array_push( $ids, 'new_renewal_order', 'customer_completed_renewal_order', 'customer_renewal_invoice' );
		}
		return $ids;
	}

	/**
	 * Apply the v1 matching rules: 'always_allow' (v1 pro "Always Allow" = every email), the
	 * exact id, or a stored id that contains it once '_' becomes '-'.
	 *
	 * @param string            $email_id WooCommerce email id.
	 * @param array<int,string> $enabled  Enabled ids.
	 * @return bool
	 */
	private static function email_enabled( $email_id, array $enabled ) {
		if ( '' === $email_id ) {
			return false;
		}
		if ( in_array( 'always_allow', $enabled, true ) || in_array( $email_id, $enabled, true ) ) {
			return true;
		}
		foreach ( $enabled as $value ) {
			if ( false !== strpos( str_replace( '_', '-', (string) $value ), $email_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render and persist the attached document (the invoice, unless a
	 * filter picks another type — Challan Pro sends the credit note with
	 * refund emails).
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $email_id WooCommerce email id.
	 * @return string Absolute file path, or '' on failure.
	 * @throws \RuntimeException From the PDF engine.
	 */
	private function write_pdf( \WC_Order $order, $email_id = '' ) {
		/**
		 * Filter which document an order email carries.
		 *
		 * @param string    $type     Document type id (default 'invoice').
		 * @param string    $email_id WooCommerce email id.
		 * @param \WC_Order $order    Order.
		 */
		$type      = (string) apply_filters( 'challan_email_attachment_type', 'invoice', $email_id, $order );
		$renderers = 'invoice' === $type ? array() : \Challan\Plugin::instance()->renderers();
		if ( 'invoice' !== $type && ! isset( $renderers[ $type ] ) ) {
			$type = 'invoice';
		}

		// v1 filename contract: the recipient sees Invoice-{invoice_no}.pdf.
		// Non-guessability moves into a per-order token SUBDIRECTORY.
		$filename = 'Invoice-' . $this->number->for_order( $order ) . '.pdf';
		if ( 'invoice' !== $type ) {
			/**
			 * Filter the file name of a non-invoice email attachment.
			 *
			 * @param string    $filename Default "{type}-{invoice no}.pdf".
			 * @param string    $type     Document type id.
			 * @param \WC_Order $order    Order.
			 */
			$filename = (string) apply_filters( 'challan_email_attachment_filename', $type . '-' . $this->number->for_order( $order ) . '.pdf', $type, $order );
		}

		/** Preserved v1 filter (name, document type, order id). */
		$filename = sanitize_file_name( (string) apply_filters( 'woo_invoice_file_name', $filename, $type, $order->get_id() ) );

		$renderer = 'invoice' === $type ? $this->renderer : $renderers[ $type ];
		return self::store( $order, $filename, $this->engine->render( $renderer->render( $order ), $type ) );
	}

	/**
	 * Write a PDF for an order email into the protected attachments folder
	 * (per-order token subdirectory, swept by the daily cleanup). Shared
	 * with Challan Pro's packing-slip attachment.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $filename File name the recipient sees.
	 * @param string    $pdf      PDF bytes.
	 * @return string Absolute file path, or '' on failure.
	 */
	public static function store( \WC_Order $order, $filename, $pdf ) {
		$dir = self::attachments_dir();
		if ( '' === $dir ) {
			return '';
		}

		$token     = substr( md5( $order->get_order_key() . wp_salt( 'auth' ) ), 0, 12 );
		$order_dir = $dir . '/' . $order->get_id() . '-' . $token;
		if ( ! is_dir( $order_dir ) && ! wp_mkdir_p( $order_dir ) ) {
			return '';
		}

		$path = $order_dir . '/' . sanitize_file_name( (string) $filename );
		if ( false === file_put_contents( $path, $pdf ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- controlled uploads subdir.
			return '';
		}

		return $path;
	}

	/**
	 * Attachment storage dir under uploads, listing-protected.
	 *
	 * @return string Absolute path, or '' when uploads are unwritable.
	 */
	private static function attachments_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'challan/attachments';

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			file_put_contents( trailingslashit( $dir ) . 'index.html', '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		}

		return $dir;
	}

	/**
	 * Ensure the daily cleanup event exists.
	 *
	 * @return void
	 */
	public function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Delete attachment files older than two days (and their emptied
	 * per-order directories).
	 *
	 * @return void
	 */
	public function cleanup() {
		$dir = self::attachments_dir();
		if ( '' === $dir ) {
			return;
		}

		$files = glob( $dir . '/{*.pdf,*/*.pdf}', GLOB_BRACE );
		if ( ! is_array( $files ) ) {
			return;
		}

		$cutoff = time() - 2 * DAY_IN_SECONDS;
		foreach ( $files as $file ) {
			$mtime = filemtime( $file );
			if ( false !== $mtime && $mtime < $cutoff ) {
				wp_delete_file( $file );
				$parent = dirname( $file );
				if ( $parent !== $dir && is_dir( $parent ) && array() === glob( $parent . '/*' ) ) {
					global $wp_filesystem;
					if ( ! $wp_filesystem ) {
						require_once ABSPATH . 'wp-admin/includes/file.php';
						WP_Filesystem();
					}
					if ( $wp_filesystem ) {
						$wp_filesystem->rmdir( $parent );
					}
				}
			}
		}
	}
}
