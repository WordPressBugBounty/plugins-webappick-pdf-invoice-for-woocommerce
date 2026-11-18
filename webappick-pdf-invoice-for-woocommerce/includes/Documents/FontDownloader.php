<?php
/**
 * Background font pack downloader.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Downloads the extended mPDF font pack in the background after
 * activation (v1 behavior, secured):
 *
 * - FONTS ONLY — the PDF engine code ships inside the plugin.
 * - Source pinned to the bundled mPDF release tag on the official
 *   mPDF GitHub repository, fetched with wp_remote_get (TLS verified —
 *   v1 disabled peer verification; v2 never does).
 * - Every file is validated (font magic bytes, sane size) before it is
 *   kept.
 * - Batched Action Scheduler jobs (WooCommerce ships the library) so
 *   activation never blocks; resumes until done. Falls back to WP-Cron
 *   in the edge case where Action Scheduler is not loaded.
 * - Completion sets the LEGACY flag challan_mpdf_all_font_downloading_done
 *   so upgraded sites keep a consistent status signal.
 *
 * The dist build ships the DejaVu core set inside vendor/, so documents
 * render immediately; this pack adds the multilingual coverage.
 */
class FontDownloader {

	const CRON_HOOK      = 'challan_download_fonts';
	const DONE_FLAG      = 'challan_mpdf_all_font_downloading_done';
	const SOURCE_BASE    = 'https://raw.githubusercontent.com/mpdf/mpdf/';
	const MPDF_TAG       = 'v8.3.1'; // Keep in sync with composer.json.
	const BATCH_SIZE     = 8;
	const MIN_FONT_BYTES = 10240;

	/**
	 * Extended font files (the bundled DejaVu set excluded).
	 *
	 * Generated from the pinned mPDF release's ttfonts directory.
	 *
	 * @return string[]
	 */
	public static function manifest() {
		return array(
			'AboriginalSansREGULAR.ttf',
			'Abyssinica_SIL.ttf',
			'Aegean.otf',
			'Aegyptus.otf',
			'Akkadian.otf',
			'DBSILBR.ttf',
			'Dhyana-Bold.ttf',
			'Dhyana-Regular.ttf',
			'DhyanaOFL.txt',
			'Eeyek-Regular.ttf',
			'FreeMono.ttf',
			'FreeMonoBold.ttf',
			'FreeMonoBoldOblique.ttf',
			'FreeMonoOblique.ttf',
			'FreeSans.ttf',
			'FreeSansBold.ttf',
			'FreeSansBoldOblique.ttf',
			'FreeSansOblique.ttf',
			'FreeSerif.ttf',
			'FreeSerifBold.ttf',
			'FreeSerifBoldItalic.ttf',
			'FreeSerifItalic.ttf',
			'GNUFreeFontinfo.txt',
			'Garuda-Bold.ttf',
			'Garuda-BoldOblique.ttf',
			'Garuda-Oblique.ttf',
			'Garuda.ttf',
			'Jomolhari-OFL.txt',
			'Jomolhari.ttf',
			'KhmerOFL.txt',
			'KhmerOS.ttf',
			'Lateef font OFL.txt',
			'LateefRegOT.ttf',
			'Lohit-Kannada.ttf',
			'LohitKannadaOFL.txt',
			'Padauk-book.ttf',
			'Pothana2000.ttf',
			'Quivira.otf',
			'Sun-ExtA.ttf',
			'Sun-ExtB.ttf',
			'SundaneseUnicode-1.0.5.ttf',
			'SyrCOMEdessa.otf',
			'SyrCOMEdessa_license.txt',
			'TaameyDavidCLM-LICENSE.txt',
			'TaameyDavidCLM-Medium.ttf',
			'TaiHeritagePro.ttf',
			'Tharlon-Regular.ttf',
			'TharlonOFL.txt',
			'UnBatang_0613.ttf',
			'Uthman.otf',
			'XB Riyaz.ttf',
			'XB RiyazBd.ttf',
			'XB RiyazBdIt.ttf',
			'XB RiyazIt.ttf',
			'XW Zar Font Info.txt',
			'ZawgyiOne.ttf',
			'ayar.ttf',
			'damase_v.2.ttf',
			'kaputaunicode.ttf',
			'lannaalif-v1-03.ttf',
			'ocrb10.ttf',
			'ocrbinfo.txt',
		);
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run_batch' ) );
		// v1 re-checked fonts after plugin upgrades.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Action Scheduler group for all Challan jobs.
	 */
	const AS_GROUP = 'challan';

	/**
	 * Kick off (or resume) the background download.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( get_option( self::DONE_FLAG ) ) {
			return;
		}
		// The upgrade runs early (plugins_loaded): Action Scheduler's data
		// store is not ready yet, so queue once it is.
		if ( function_exists( 'as_schedule_single_action' ) && ! did_action( 'action_scheduler_init' ) ) {
			add_action(
				'action_scheduler_init',
				static function () {
					self::enqueue( 10 );
				}
			);
			return;
		}
		self::enqueue( 10 );
	}

	/**
	 * Queue the next batch run, deduplicated. Prefers Action Scheduler
	 * (reliable retries, admin visibility under Tools → Scheduled
	 * Actions); WP-Cron is only the no-WooCommerce fallback.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	private static function enqueue( $delay ) {
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( false === as_next_scheduled_action( self::CRON_HOOK, array(), self::AS_GROUP ) ) {
				as_schedule_single_action( time() + $delay, self::CRON_HOOK, array(), self::AS_GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/**
	 * Download progress.
	 *
	 * @return array{total:int,done:int,complete:bool}
	 */
	public static function status() {
		$dir      = self::fonts_dir();
		$manifest = self::manifest();
		$done     = 0;
		foreach ( $manifest as $file ) {
			if ( '' !== $dir && file_exists( trailingslashit( $dir ) . $file ) ) {
				++$done;
			}
		}

		return array(
			'total'    => count( $manifest ),
			'done'     => $done,
			'complete' => (bool) get_option( self::DONE_FLAG ),
		);
	}

	/**
	 * Download the next batch of missing fonts, rescheduling until done.
	 *
	 * @return void
	 */
	public function run_batch() {
		$dir = self::fonts_dir();
		if ( '' === $dir ) {
			return;
		}

		$missing = array();
		foreach ( self::manifest() as $file ) {
			if ( ! file_exists( trailingslashit( $dir ) . $file ) ) {
				$missing[] = $file;
			}
		}

		if ( array() === $missing ) {
			update_option( self::DONE_FLAG, '1' );
			return;
		}

		foreach ( array_slice( $missing, 0, self::BATCH_SIZE ) as $file ) {
			$this->download_one( $file, $dir );
		}

		// Resume shortly with the next batch (or mark complete).
		self::enqueue( 30 );
	}

	/**
	 * Fetch and validate one font file.
	 *
	 * @param string $file Font filename from the manifest.
	 * @param string $dir  Destination directory.
	 * @return void
	 */
	private function download_one( $file, $dir ) {
		$url = self::SOURCE_BASE . self::MPDF_TAG . '/ttfonts/' . rawurlencode( $file );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Not a VIP site (vip_safe_wp_remote_get() is unavailable); runs in a background cron job, and multi-MB font files need the long timeout.
		$response = wp_remote_get( $url, array( 'timeout' => 25 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return; // Next cron run retries.
		}

		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) < self::MIN_FONT_BYTES || ! self::looks_like_font( $body ) ) {
			return;
		}

		file_put_contents( trailingslashit( $dir ) . $file, $body ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- controlled uploads subdir.
	}

	/**
	 * TTF/OTF/TTC magic-byte check.
	 *
	 * @param string $bytes File contents.
	 * @return bool
	 */
	private static function looks_like_font( $bytes ) {
		$magic = substr( $bytes, 0, 4 );
		return in_array( $magic, array( "\x00\x01\x00\x00", 'OTTO', 'true', 'ttcf' ), true );
	}

	/**
	 * The downloaded-fonts directory under uploads.
	 *
	 * @return string Absolute path, or '' when unavailable.
	 */
	public static function fonts_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'challan/fonts';
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			file_put_contents( trailingslashit( $dir ) . 'index.html', '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		}

		return $dir;
	}
}
