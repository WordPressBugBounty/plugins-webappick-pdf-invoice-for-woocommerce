<?php
/**
 * System status endpoints.
 *
 * @package Challan
 */

namespace Challan\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * GET  /challan/v1/system-status             — full structured report.
 * POST /challan/v1/system-status/clear-cache — empty the temp and code-image dirs.
 * GET  /challan/v1/system-status/logs        — Challan log files from WooCommerce's log dir.
 *
 * Check statuses: 'ok' | 'warning' | 'critical' | 'info'.
 *
 * @since 4.0.0
 */
class SystemStatusController extends AbstractController {

	/**
	 * Register this controller's REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		/**
		 * Returns the full system status report for the Status screen.
		 *
		 * Seven sections of checks (Challan, server & PHP, WordPress &
		 * WooCommerce, active plugins, database, file permissions, theme),
		 * a summary count, the list of issues and a plain-text copy for
		 * support. Builds the report on every request, including one
		 * outbound HTTP request to api.wordpress.org (5-second timeout) to
		 * test remote access.
		 *
		 * Route:       GET /challan/v1/system-status
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_status_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0
		 * @see   SystemStatusController::get_status()
		 */
		$this->register_schema_route(
			'/system-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_status_schema'
		);

		/**
		 * Deletes Challan's temporary files.
		 *
		 * Empties uploads/challan/tmp (mPDF temp files) and
		 * uploads/challan/codes (cached QR and barcode images), keeping
		 * their index.html. Both are recreated when needed.
		 *
		 * Route:       POST /challan/v1/system-status/clear-cache
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_clear_cache_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0
		 * @see   SystemStatusController::clear_cache()
		 */
		$this->register_schema_route(
			'/system-status/clear-cache',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_cache' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_clear_cache_schema'
		);

		/**
		 * Returns Challan's log files from the WooCommerce log directory.
		 *
		 * Lists files named challan*.log in WC_LOG_DIR, with the last 200
		 * lines of each. Logs stored elsewhere (for example WooCommerce's
		 * database log handler) are not included.
		 *
		 * Route:       GET /challan/v1/system-status/logs
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_logs_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0
		 * @see   SystemStatusController::get_logs()
		 */
		$this->register_schema_route(
			'/system-status/logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_logs_schema'
		);
	}

	/**
	 * Full report payload.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_status_schema().
	 */
	public function get_status() {
		return rest_ensure_response( self::report() );
	}

	/**
	 * Empty the mPDF temp dir and the code-image cache (regenerated on demand).
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_clear_cache_schema().
	 */
	public function clear_cache() {
		$uploads = wp_upload_dir();
		$removed = 0;

		// mPDF temp files + the cached QR/barcode preview images.
		foreach ( array( 'challan/tmp', 'challan/codes' ) as $subdir ) {
			$dir = trailingslashit( $uploads['basedir'] ) . $subdir;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
				if ( is_file( $file ) && 'index.html' !== basename( $file ) && wp_delete_file( $file ) !== false ) {
					++$removed;
				}
			}
		}

		return rest_ensure_response( array( 'removed' => $removed ) );
	}

	/**
	 * Challan log files from the WooCommerce log directory.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_logs_schema().
	 */
	public function get_logs() {
		$files = array();

		if ( defined( 'WC_LOG_DIR' ) && is_dir( WC_LOG_DIR ) ) {
			foreach ( (array) glob( WC_LOG_DIR . 'challan*.log' ) as $file ) {
				$files[] = array(
					'name'     => basename( $file ),
					'size'     => size_format( (int) filesize( $file ) ),
					'modified' => gmdate( 'Y-m-d H:i', (int) filemtime( $file ) ),
					// Last ~200 lines is plenty for the panel.
					'tail'     => implode( "\n", array_slice( (array) file( $file, FILE_IGNORE_NEW_LINES ), -200 ) ), // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local log file.
				);
			}
		}

		return rest_ensure_response( array( 'files' => $files ) );
	}

	/**
	 * Compact snapshot embedded in /bootstrap (kept small on purpose).
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot() {
		$uploads = wp_upload_dir();

		return array(
			'plugin_version'     => CHALLAN_VERSION,
			'php_version'        => PHP_VERSION,
			'wp_version'         => get_bloginfo( 'version' ),
			'wc_version'         => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'wc_active'          => class_exists( 'WooCommerce' ),
			'hpos_enabled'       => self::hpos_enabled(),
			'memory_limit'       => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'uploads_writable'   => wp_is_writable( $uploads['basedir'] ),
			'mpdf_installed'     => class_exists( \Mpdf\Mpdf::class ) || file_exists( CHALLAN_PATH . 'vendor/autoload.php' ),
			'fonts_downloaded'   => (bool) get_option( 'challan_mpdf_all_font_downloading_done' ),
			'locale'             => get_locale(),
		);
	}

	/**
	 * One check row.
	 *
	 * @param string $label  Row label.
	 * @param string $value  Displayed value.
	 * @param string $status ok|warning|critical|info.
	 * @param string $note   Sub-note under the value.
	 * @return array<string,string>
	 */
	private static function check( $label, $value, $status = 'ok', $note = '' ) {
		return array(
			'label'  => $label,
			'value'  => (string) $value,
			'status' => $status,
			'note'   => $note,
		);
	}

	/**
	 * The full structured report.
	 *
	 * @return array<string,mixed>
	 */
	public static function report() {
		$sections = array(
			self::section_challan(),
			self::section_server(),
			self::section_wordpress(),
			self::section_plugins(),
			self::section_database(),
			self::section_files(),
			self::section_theme(),
		);

		/**
		 * Filter the System status sections. A section is an array with
		 * id, label and checks (rows of label, value, status — ok, warning,
		 * critical or info — and note); the chip is worked out here.
		 *
		 * @since 4.0.0
		 *
		 * @param array<int,array<string,mixed>> $sections Sections.
		 */
		$sections = (array) apply_filters( 'challan_system_status_sections', $sections );
		foreach ( $sections as $i => $section ) {
			if ( ! isset( $section['chip'] ) ) {
				$sections[ $i ]['chip'] = self::chip( (array) $section['checks'] );
			}
		}

		$critical = 0;
		$warnings = 0;
		$passed   = 0;
		$issues   = array();

		foreach ( $sections as $section ) {
			foreach ( $section['checks'] as $row ) {
				if ( 'critical' === $row['status'] ) {
					++$critical;
					$issues[] = array(
						'severity' => 'critical',
						'title'    => $row['label'] . ': ' . $row['value'],
						'note'     => $row['note'],
					);
				} elseif ( 'warning' === $row['status'] ) {
					++$warnings;
					$issues[] = array(
						'severity' => 'warning',
						/* translators: 1: check name, 2: its value. */
						'title'    => sprintf( __( '%1$s is %2$s', 'webappick-pdf-invoice-for-woocommerce' ), $row['label'], $row['value'] ),
						'note'     => $row['note'],
					);
				} else {
					++$passed;
				}
			}
		}

		return array(
			'summary'     => array(
				'critical' => $critical,
				'warnings' => $warnings,
				'passed'   => $passed,
			),
			'issues'      => $issues,
			'sections'    => $sections,
			'report_text' => self::plain_text( $sections ),
		);
	}

	/**
	 * Plain-text rendering for the Copy report button.
	 *
	 * @param array<int,array<string,mixed>> $sections Report sections.
	 * @return string
	 */
	private static function plain_text( array $sections ) {
		$lines = array( '### Challan system status — ' . gmdate( 'Y-m-d H:i' ) . ' UTC ###', '' );
		$flags = array(
			'critical' => ' [CRITICAL]',
			'warning'  => ' [WARNING]',
		);

		foreach ( $sections as $section ) {
			$lines[] = '== ' . $section['label'] . ' ==';
			foreach ( $section['checks'] as $row ) {
				$suffix  = isset( $flags[ $row['status'] ] ) ? $flags[ $row['status'] ] : '';
				$note    = '' !== $row['note'] ? ' (' . $row['note'] . ')' : '';
				$lines[] = $row['label'] . ': ' . $row['value'] . $suffix . $note;
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Section chip counts.
	 *
	 * @param array<int,array<string,string>> $checks Section rows.
	 * @return array<string,int>
	 */
	private static function chip( array $checks ) {
		$out = array(
			'critical' => 0,
			'warning'  => 0,
			'passed'   => 0,
		);
		foreach ( $checks as $row ) {
			if ( 'critical' === $row['status'] ) {
				++$out['critical'];
			} elseif ( 'warning' === $row['status'] ) {
				++$out['warning'];
			} else {
				++$out['passed'];
			}
		}
		return $out;
	}

	/**
	 * Wrap checks into a section.
	 *
	 * @param string                          $id     Section id.
	 * @param string                          $label  Section label.
	 * @param array<int,array<string,string>> $checks Rows.
	 * @return array<string,mixed>
	 */
	private static function section( $id, $label, array $checks ) {
		return array(
			'id'     => $id,
			'label'  => $label,
			'chip'   => self::chip( $checks ),
			'checks' => $checks,
		);
	}

	// ── Sections ─────────────────────────────────────────────────────

	/**
	 * The Challan section: plugin versions, licence, PDF engine and font checks.
	 *
	 * @return array<string,mixed>
	 */
	private static function section_challan() {
		$checks   = array();
		$checks[] = self::check( __( 'Challan Version', 'webappick-pdf-invoice-for-woocommerce' ), CHALLAN_VERSION );

		if ( challan_is_pro_active() ) {
			$pro_version = apply_filters( 'challan_pro_version', null );
			$licensed    = apply_filters( 'challan_pro_licensed', false );
			$checks[]    = self::check( __( 'Challan Pro Version', 'webappick-pdf-invoice-for-woocommerce' ), $pro_version ? (string) $pro_version : 'active' );
			$checks[]    = self::check(
				__( 'Pro Licence', 'webappick-pdf-invoice-for-woocommerce' ),
				$licensed ? __( 'Active', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Not activated', 'webappick-pdf-invoice-for-woocommerce' ),
				$licensed ? 'ok' : 'warning',
				$licensed ? '' : __( 'activate to receive updates', 'webappick-pdf-invoice-for-woocommerce' )
			);
		}

		$engine   = class_exists( \Mpdf\Mpdf::class ) || file_exists( CHALLAN_PATH . 'vendor/autoload.php' );
		$checks[] = self::check(
			__( 'PDF Engine (mPDF)', 'webappick-pdf-invoice-for-woocommerce' ),
			$engine ? __( 'Bundled', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Missing', 'webappick-pdf-invoice-for-woocommerce' ),
			$engine ? 'ok' : 'critical',
			$engine ? '' : __( 'reinstall the plugin', 'webappick-pdf-invoice-for-woocommerce' )
		);

		$fonts    = (bool) get_option( 'challan_mpdf_all_font_downloading_done' );
		$checks[] = self::check(
			__( 'Multilingual Font Pack', 'webappick-pdf-invoice-for-woocommerce' ),
			$fonts ? __( 'Downloaded', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Not downloaded yet', 'webappick-pdf-invoice-for-woocommerce' ),
			$fonts ? 'ok' : 'warning',
			$fonts ? '' : __( 'downloads in the background; non-Latin scripts need it', 'webappick-pdf-invoice-for-woocommerce' )
		);

		$checks[] = self::check( __( 'Selected Template', 'webappick-pdf-invoice-for-woocommerce' ), (string) get_option( 'wpifw_templateid', \Challan\Documents\Templates::DEFAULT_TEMPLATE ) );
		$checks[] = self::check( __( 'Next Invoice Number', 'webappick-pdf-invoice-for-woocommerce' ), (string) get_option( 'wpifw_invoice_no', '1' ) );
		$checks[] = self::check( __( 'Debug Mode', 'webappick-pdf-invoice-for-woocommerce' ), get_option( 'wpifw_pdf_invoice_debug_mode' ) ? __( 'Enabled', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Disabled', 'webappick-pdf-invoice-for-woocommerce' ), 'info' );
		$checks[] = self::check( __( 'Output as HTML', 'webappick-pdf-invoice-for-woocommerce' ), get_option( 'wpifw_output_type_html' ) ? __( 'Enabled', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Disabled', 'webappick-pdf-invoice-for-woocommerce' ), 'info' );

		return self::section( 'challan', __( 'Challan', 'webappick-pdf-invoice-for-woocommerce' ), $checks );
	}

	/**
	 * The server environment section: PHP version, limits, extensions and outbound HTTP.
	 *
	 * @return array<string,mixed>
	 */
	private static function section_server() {
		$checks = array();

		$server   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Unknown', 'webappick-pdf-invoice-for-woocommerce' );
		$checks[] = self::check( __( 'Server Info', 'webappick-pdf-invoice-for-woocommerce' ), $server, 'info' );

		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$checks[] = self::check( __( 'PHP Version', 'webappick-pdf-invoice-for-woocommerce' ), PHP_VERSION, 'critical', __( '7.4+ required', 'webappick-pdf-invoice-for-woocommerce' ) );
		} elseif ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			$checks[] = self::check( __( 'PHP Version', 'webappick-pdf-invoice-for-woocommerce' ), PHP_VERSION, 'warning', __( '8.0+ recommended', 'webappick-pdf-invoice-for-woocommerce' ) );
		} else {
			$checks[] = self::check( __( 'PHP Version', 'webappick-pdf-invoice-for-woocommerce' ), PHP_VERSION );
		}

		$checks[] = self::check( __( 'PHP Post Max Size', 'webappick-pdf-invoice-for-woocommerce' ), (string) ini_get( 'post_max_size' ) );

		$max_exec = (int) ini_get( 'max_execution_time' );
		$checks[] = self::check(
			__( 'PHP Max Execution Time', 'webappick-pdf-invoice-for-woocommerce' ),
			$max_exec . 's',
			( $max_exec > 0 && $max_exec < 60 ) ? 'warning' : 'ok',
			( $max_exec > 0 && $max_exec < 60 ) ? __( '60s+ recommended for large bulk PDFs', 'webappick-pdf-invoice-for-woocommerce' ) : ''
		);

		$checks[] = self::check( __( 'PHP Max Input Vars', 'webappick-pdf-invoice-for-woocommerce' ), (string) ini_get( 'max_input_vars' ) );

		$memory   = (string) ini_get( 'memory_limit' );
		$mem_mb   = wp_convert_hr_to_bytes( $memory ) / MB_IN_BYTES;
		$checks[] = self::check(
			__( 'PHP Memory Limit', 'webappick-pdf-invoice-for-woocommerce' ),
			$memory,
			( $mem_mb > 0 && $mem_mb < 128 ) ? 'warning' : 'ok',
			( $mem_mb > 0 && $mem_mb < 128 ) ? __( '256M recommended for PDF generation', 'webappick-pdf-invoice-for-woocommerce' ) : ''
		);

		$curl     = function_exists( 'curl_version' ) ? curl_version() : null;
		$checks[] = self::check( __( 'cURL Version', 'webappick-pdf-invoice-for-woocommerce' ), $curl ? $curl['version'] . ', ' . $curl['ssl_version'] : __( 'Missing', 'webappick-pdf-invoice-for-woocommerce' ), $curl ? 'ok' : 'warning' );
		$checks[] = self::check( __( 'Max Upload Size', 'webappick-pdf-invoice-for-woocommerce' ), size_format( wp_max_upload_size() ) );
		$checks[] = self::check( __( 'Default Timezone', 'webappick-pdf-invoice-for-woocommerce' ), (string) date_default_timezone_get(), 'info' );

		$checks[] = self::check( __( 'Mbstring', 'webappick-pdf-invoice-for-woocommerce' ), extension_loaded( 'mbstring' ) ? __( 'Yes', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Missing', 'webappick-pdf-invoice-for-woocommerce' ), extension_loaded( 'mbstring' ) ? 'ok' : 'critical', extension_loaded( 'mbstring' ) ? '' : __( 'required by the PDF engine', 'webappick-pdf-invoice-for-woocommerce' ) );
		$checks[] = self::check( __( 'GD / Imagick', 'webappick-pdf-invoice-for-woocommerce' ), ( extension_loaded( 'gd' ) || extension_loaded( 'imagick' ) ) ? __( 'Yes', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Missing', 'webappick-pdf-invoice-for-woocommerce' ), ( extension_loaded( 'gd' ) || extension_loaded( 'imagick' ) ) ? 'ok' : 'warning', ( extension_loaded( 'gd' ) || extension_loaded( 'imagick' ) ) ? '' : __( 'needed for images in PDFs', 'webappick-pdf-invoice-for-woocommerce' ) );
		$checks[] = self::check( __( 'DOMDocument', 'webappick-pdf-invoice-for-woocommerce' ), class_exists( 'DOMDocument' ) ? __( 'Yes', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Missing', 'webappick-pdf-invoice-for-woocommerce' ), class_exists( 'DOMDocument' ) ? 'ok' : 'warning' );
		$checks[] = self::check( __( 'GZip', 'webappick-pdf-invoice-for-woocommerce' ), function_exists( 'gzopen' ) ? __( 'Yes', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Missing', 'webappick-pdf-invoice-for-woocommerce' ), function_exists( 'gzopen' ) ? 'ok' : 'warning' );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Not a VIP site (vip_safe_wp_remote_get() is unavailable); this admin-only diagnostic tests outbound HTTP, and a too-short timeout would misreport slow hosts as failing.
		$remote   = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/', array( 'timeout' => 5 ) );
		$code     = is_wp_error( $remote ) ? 0 : (int) wp_remote_retrieve_response_code( $remote );
		$checks[] = self::check(
			__( 'Remote GET', 'webappick-pdf-invoice-for-woocommerce' ),
			$code > 0 ? (string) $code : __( 'Failed', 'webappick-pdf-invoice-for-woocommerce' ),
			200 === $code ? 'ok' : 'warning',
			200 === $code ? '' : __( 'outbound requests may be blocked (font pack download needs them)', 'webappick-pdf-invoice-for-woocommerce' )
		);

		return self::section( 'server', __( 'Server & PHP', 'webappick-pdf-invoice-for-woocommerce' ), $checks );
	}

	/**
	 * The WordPress environment section.
	 *
	 * @return array<string,mixed>
	 */
	private static function section_wordpress() {
		$checks   = array();
		$checks[] = self::check( __( 'Home URL', 'webappick-pdf-invoice-for-woocommerce' ), home_url(), 'info' );
		$checks[] = self::check( __( 'Site URL', 'webappick-pdf-invoice-for-woocommerce' ), site_url(), 'info' );
		$checks[] = self::check( __( 'WP Version', 'webappick-pdf-invoice-for-woocommerce' ), get_bloginfo( 'version' ) );
		$checks[] = self::check( __( 'WP Multisite', 'webappick-pdf-invoice-for-woocommerce' ), is_multisite() ? __( 'Yes', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'No', 'webappick-pdf-invoice-for-woocommerce' ), 'info' );

		$wp_mem   = wp_convert_hr_to_bytes( (string) WP_MEMORY_LIMIT ) / MB_IN_BYTES;
		$checks[] = self::check(
			__( 'WP Memory Limit', 'webappick-pdf-invoice-for-woocommerce' ),
			(string) WP_MEMORY_LIMIT,
			( $wp_mem > 0 && $wp_mem < 64 ) ? 'warning' : 'ok',
			( $wp_mem > 0 && $wp_mem < 64 ) ? __( '256M recommended', 'webappick-pdf-invoice-for-woocommerce' ) : ''
		);

		$checks[] = self::check( __( 'WP Debug Mode', 'webappick-pdf-invoice-for-woocommerce' ), ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? __( 'Enabled', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Disabled', 'webappick-pdf-invoice-for-woocommerce' ), 'info' );

		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks[] = self::check(
			__( 'WP Cron', 'webappick-pdf-invoice-for-woocommerce' ),
			$cron_off ? __( 'Disabled', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Enabled', 'webappick-pdf-invoice-for-woocommerce' ),
			$cron_off ? 'warning' : 'ok',
			$cron_off ? __( 'the background font download runs on cron', 'webappick-pdf-invoice-for-woocommerce' ) : ''
		);

		$checks[] = self::check( __( 'Language', 'webappick-pdf-invoice-for-woocommerce' ), get_locale(), 'info' );
		$checks[] = self::check( __( 'Timezone', 'webappick-pdf-invoice-for-woocommerce' ), wp_timezone_string(), 'info' );

		if ( class_exists( 'WooCommerce' ) ) {
			$checks[] = self::check( __( 'WooCommerce Version', 'webappick-pdf-invoice-for-woocommerce' ), defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown' );
			$checks[] = self::check( __( 'HPOS (Custom Order Tables)', 'webappick-pdf-invoice-for-woocommerce' ), self::hpos_enabled() ? __( 'Enabled', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'Disabled', 'webappick-pdf-invoice-for-woocommerce' ), 'info' );
			$checks[] = self::check( __( 'WC Store Currency', 'webappick-pdf-invoice-for-woocommerce' ), get_woocommerce_currency(), 'info' );
		} else {
			$checks[] = self::check( __( 'WooCommerce', 'webappick-pdf-invoice-for-woocommerce' ), __( 'Not active', 'webappick-pdf-invoice-for-woocommerce' ), 'critical', __( 'Challan needs WooCommerce', 'webappick-pdf-invoice-for-woocommerce' ) );
		}

		return self::section( 'wordpress', __( 'WordPress & WooCommerce', 'webappick-pdf-invoice-for-woocommerce' ), $checks ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- 'wordpress' is the section id in the REST payload, not prose.
	}

	/**
	 * The active plugins section.
	 *
	 * @return array<string,mixed>
	 */
	private static function section_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$all = get_plugins();
			if ( isset( $all[ $file ] ) ) {
				$active[] = self::check( $all[ $file ]['Name'], $all[ $file ]['Version'], 'info' );
			}
		}

		return self::section( 'plugins', sprintf( /* translators: %d: number of active plugins. */ __( 'Active plugins (%d)', 'webappick-pdf-invoice-for-woocommerce' ), count( $active ) ), $active );
	}

	/**
	 * The database section.
	 *
	 * @return array<string,mixed>
	 */
	private static function section_database() {
		global $wpdb;

		$checks   = array();
		$checks[] = self::check( __( 'MySQL Version', 'webappick-pdf-invoice-for-woocommerce' ), (string) $wpdb->db_version(), 'info' );
		$checks[] = self::check( __( 'Table Prefix', 'webappick-pdf-invoice-for-woocommerce' ), $wpdb->prefix, 'info' );
		$checks[] = self::check( __( 'DB Charset', 'webappick-pdf-invoice-for-woocommerce' ), (string) $wpdb->charset, 'info' );

		$count = wp_cache_get( 'challan_option_count', 'challan' );
		if ( false === $count ) {
			$count = (int) $wpdb->get_var( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_var_get_var, WordPress.DB.DirectDatabaseQuery.DirectQuery -- one COUNT with no API equivalent, cached below.
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpifw_' ) . '%' )
			);
			wp_cache_set( 'challan_option_count', $count, 'challan', 5 * MINUTE_IN_SECONDS );
		}
		$checks[] = self::check( __( 'Challan Option Rows', 'webappick-pdf-invoice-for-woocommerce' ), (string) $count, 'info' );

		return self::section( 'database', __( 'Database', 'webappick-pdf-invoice-for-woocommerce' ), $checks );
	}

	/**
	 * The file permissions section: upload directories and their writability.
	 *
	 * @return array<string,mixed>
	 */
	private static function section_files() {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );

		$rows = array(
			array( __( 'Uploads Directory', 'webappick-pdf-invoice-for-woocommerce' ), $uploads['basedir'], true ),
			array( __( 'Challan Temp Directory', 'webappick-pdf-invoice-for-woocommerce' ), $base . 'challan/tmp', false ),
			array( __( 'Font Directory', 'webappick-pdf-invoice-for-woocommerce' ), $base . 'challan/fonts', false ),
		);

		$checks = array();
		foreach ( $rows as list( $label, $path, $required ) ) {
			$exists   = is_dir( $path );
			$writable = $exists && wp_is_writable( $path );
			if ( $writable ) {
				$checks[] = self::check( $label, __( 'Writable', 'webappick-pdf-invoice-for-woocommerce' ), 'ok', $path );
			} elseif ( ! $exists ) {
				$checks[] = self::check( $label, __( 'Created on first use', 'webappick-pdf-invoice-for-woocommerce' ), $required ? 'warning' : 'info', $path );
			} else {
				$checks[] = self::check( $label, __( 'Not writable', 'webappick-pdf-invoice-for-woocommerce' ), $required ? 'critical' : 'warning', $path );
			}
		}

		return self::section( 'files', __( 'File permissions', 'webappick-pdf-invoice-for-woocommerce' ), $checks );
	}

	/**
	 * The active theme section.
	 *
	 * @return array<string,mixed>
	 */
	private static function section_theme() {
		$theme  = wp_get_theme();
		$checks = array(
			self::check( __( 'Theme', 'webappick-pdf-invoice-for-woocommerce' ), $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ), 'info' ),
			self::check( __( 'Child Theme', 'webappick-pdf-invoice-for-woocommerce' ), is_child_theme() ? __( 'Yes', 'webappick-pdf-invoice-for-woocommerce' ) : __( 'No', 'webappick-pdf-invoice-for-woocommerce' ), 'info' ),
		);

		return self::section( 'theme', __( 'Theme', 'webappick-pdf-invoice-for-woocommerce' ), $checks );
	}

	/**
	 * Whether WooCommerce HPOS (custom order tables) is in use.
	 *
	 * @return bool
	 */
	private static function hpos_enabled() {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Response schema of GET /system-status.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_status_schema() {
		$counts = array(
			'type'       => 'object',
			'properties' => array(
				'critical' => array( 'type' => 'integer' ),
				'warning'  => array( 'type' => 'integer' ),
				'passed'   => array(
					'description' => __( 'Checks with status "ok" or "info".', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'integer',
				),
			),
		);

		return Schema::object(
			'system-status',
			array(
				'summary'     => array(
					'description' => __( 'Number of checks by result, over all sections. "passed" counts "ok" and "info" checks.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
					'readonly'    => true,
					'properties'  => array(
						'critical' => array( 'type' => 'integer' ),
						'warnings' => array( 'type' => 'integer' ),
						'passed'   => array( 'type' => 'integer' ),
					),
				),
				'issues'      => array(
					'description' => __( 'Every critical and warning check, critical ones titled "label: value" and warnings "label is value".', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'severity' => array(
								'type' => 'string',
								'enum' => array( 'critical', 'warning' ),
							),
							'title'    => array( 'type' => 'string' ),
							'note'     => array( 'type' => 'string' ),
						),
					),
				),
				'sections'    => array(
					'description' => __( 'The report sections, in display order. Section ids: challan, server, wordpress, plugins, database, files, theme.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array( 'type' => 'string' ),
							'label'  => array( 'type' => 'string' ),
							'chip'   => array_merge(
								array( 'description' => __( 'Number of checks in this section by result.', 'webappick-pdf-invoice-for-woocommerce' ) ),
								$counts
							),
							'checks' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'label'  => array( 'type' => 'string' ),
										'value'  => array( 'type' => 'string' ),
										'status' => array(
											'type' => 'string',
											'enum' => array( 'ok', 'warning', 'critical', 'info' ),
										),
										'note'   => array(
											'description' => __( 'Hint shown under the value; for file checks, the directory path. Empty when there is none.', 'webappick-pdf-invoice-for-woocommerce' ),
											'type'        => 'string',
										),
									),
								),
							),
						),
					),
				),
				'report_text' => array(
					'description' => __( 'The whole report as plain text, for the Copy report button.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Response schema of POST /system-status/clear-cache.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_clear_cache_schema() {
		return Schema::object(
			'system-status-clear-cache',
			array(
				'removed' => array(
					'description' => __( 'Number of files deleted.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Response schema of GET /system-status/logs.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_logs_schema() {
		return Schema::object(
			'system-status-logs',
			array(
				'files' => array(
					'description' => __( 'Challan log files (challan*.log) in the WooCommerce log directory. Empty when there are none.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'     => array( 'type' => 'string' ),
							'size'     => array(
								'description' => __( 'File size formatted for display, for example "12 KB".', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
							'modified' => array(
								'description' => __( 'Last change, "Y-m-d H:i" in UTC.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
							'tail'     => array(
								'description' => __( 'The last 200 lines of the file.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
						),
					),
				),
			)
		);
	}
}
