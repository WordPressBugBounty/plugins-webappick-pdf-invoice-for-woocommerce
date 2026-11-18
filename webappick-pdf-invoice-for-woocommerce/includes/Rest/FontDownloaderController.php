<?php
/**
 * Legacy font-downloader REST routes (compat, secured).
 *
 * @package Challan
 */

namespace Challan\Rest;

use Challan\Documents\FontDownloader;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * COMPATIBILITY CONTRACT: v1 exposed
 * `challan/v1/font-downloader/download-font` and
 * `challan/v1/font-downloader/maybe-download-font`. The routes stay,
 * but with real permission callbacks — v1 shipped them with
 * `return true` and wildcard CORS; neither is reproduced.
 *
 * Both routes are GET although they can queue a background job: the v1
 * paths and method are kept for compatibility (team decision 2026-11-03).
 *
 * @since 4.0.0
 */
class FontDownloaderController extends AbstractController {

	/**
	 * Register this controller's REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		/**
		 * Queues the background download of the multilingual font pack and
		 * reports progress.
		 *
		 * Queues the job unless the pack is already marked complete (the
		 * challan_mpdf_all_font_downloading_done option), through Action
		 * Scheduler when available, otherwise WP-Cron. A GET with a side
		 * effect, kept from v1 for compatibility. Reading the status also
		 * creates uploads/challan/fonts if it is missing.
		 *
		 * Route:       GET /challan/v1/font-downloader/download-font
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_status_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0 Replaces the v1 route of the same path, which had no
		 *              permission check.
		 * @see   FontDownloaderController::download()
		 */
		$this->register_schema_route(
			'/font-downloader/download-font',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_status_schema'
		);

		/**
		 * Reports font pack progress and queues the download when the pack
		 * is not complete.
		 *
		 * Same effect as download-font: both queue only while the pack is
		 * incomplete. A GET with a side effect, kept from v1 for
		 * compatibility.
		 *
		 * Route:       GET /challan/v1/font-downloader/maybe-download-font
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_status_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0 Replaces the v1 route of the same path, which had no
		 *              permission check.
		 * @see   FontDownloaderController::maybe_download()
		 */
		$this->register_schema_route(
			'/font-downloader/maybe-download-font',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'maybe_download' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_status_schema'
		);
	}

	/**
	 * Kick the background download and report status.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_status_schema().
	 */
	public function download() {
		FontDownloader::schedule();
		return rest_ensure_response( FontDownloader::status() );
	}

	/**
	 * Schedule only when incomplete; always report status.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_status_schema().
	 */
	public function maybe_download() {
		$status = FontDownloader::status();
		if ( ! $status['complete'] ) {
			FontDownloader::schedule();
		}
		return rest_ensure_response( $status );
	}

	/**
	 * Response schema of both font-downloader routes.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_status_schema() {
		return Schema::object(
			'font-downloader',
			array(
				'total'    => array(
					'description' => __( 'Number of files in the font pack.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				'done'     => array(
					'description' => __( 'Number of those files already in uploads/challan/fonts.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				'complete' => array(
					'description' => __( 'Whether the pack is marked complete (the challan_mpdf_all_font_downloading_done option). Sites upgraded from v1 can be complete with done below total, because v1 kept its fonts in another folder.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'boolean',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}
}
