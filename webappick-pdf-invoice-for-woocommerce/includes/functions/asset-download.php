<?php
/**
 * Safe downloads for the assets Challan fetches at runtime (mPDF library, fonts, data files).
 *
 * Every download has a timeout, only one request downloads a given asset at a time, and a
 * failed download is not retried on every page load (it waits a while before trying again).
 *
 * @since      3.7.90
 * @package    Challan_Free
 * @subpackage Challan_Free/functions
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'woo_invoice_download_remote_file' ) ) {
	/**
	 * Download a remote file to a local path.
	 *
	 * Streams the response to "<destination>.part" and only moves it into place when the
	 * server answered 200 with a non-empty body, so a failed download never leaves a broken file.
	 *
	 * @param string $url         Source URL.
	 * @param string $destination Local file path.
	 * @param int    $timeout     Seconds before giving up.
	 * @return bool
	 */
	function woo_invoice_download_remote_file( $url, $destination, $timeout = 60 ) {
		$part = $destination . '.part';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 5,
				'sslverify'   => true,
				'stream'      => true,
				'filename'    => $part,
			)
		);

		$ok = ! is_wp_error( $response )
			&& 200 === (int) wp_remote_retrieve_response_code( $response )
			&& file_exists( $part )
			&& filesize( $part ) > 0;

		if ( ! $ok ) {
			if ( file_exists( $part ) ) {
				wp_delete_file( $part );
			}
			return false;
		}

		if ( file_exists( $destination ) ) {
			wp_delete_file( $destination );
		}
		return rename( $part, $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Move the finished download into place.
	}
}

if ( ! function_exists( 'woo_invoice_run_asset_download' ) ) {
	/**
	 * Run an asset download once at a time, and wait before retrying after a failure.
	 *
	 * @param string   $key         Asset key, e.g. "mpdf".
	 * @param callable $callback    Performs the download; returns true on success.
	 * @param int      $retry_after Seconds to wait after a failed download.
	 * @return bool True when the download ran and succeeded.
	 */
	function woo_invoice_run_asset_download( $key, $callback, $retry_after = 900 ) {
		global $wpdb;
		$retry = 'challan_asset_download_retry_' . $key;

		// A recent attempt failed: don't hold up this page load with another one.
		if ( get_transient( $retry ) ) {
			return false;
		}

		// Only one request downloads at a time. GET_LOCK is atomic and MySQL releases it when
		// the request ends, even if PHP is killed mid-download, so it can never get stuck.
		$lock = substr( $wpdb->prefix . 'challan_dl_' . $key, 0, 64 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, nothing to cache.
			return false;
		}

		try {
			$ok = (bool) call_user_func( $callback );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, nothing to cache.
		}

		if ( $ok ) {
			delete_transient( $retry );
		} else {
			set_transient( $retry, time(), $retry_after );
		}
		return $ok;
	}
}

if ( ! function_exists( 'woo_invoice_strip_zip_suffix' ) ) {
	/**
	 * "Font.ttf.zip" -> "Font.ttf" (rtrim( $path, '.zip' ) strips characters, not the suffix).
	 *
	 * @param string $path File path or name.
	 * @return string
	 */
	function woo_invoice_strip_zip_suffix( $path ) {
		return '.zip' === strtolower( substr( $path, -4 ) ) ? substr( $path, 0, -4 ) : $path;
	}
}

if ( ! function_exists( 'woo_invoice_delete_tree' ) ) {
	/**
	 * Delete a file or directory tree. Quiet when parts are already gone.
	 *
	 * @param string $path File or directory.
	 * @return void
	 */
	function woo_invoice_delete_tree( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			$items = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be removed by another request meanwhile.
			foreach ( array_diff( (array) $items, array( '.', '..' ) ) as $item ) {
				woo_invoice_delete_tree( rtrim( $path, '/' ) . '/' . $item );
			}
			@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- May be removed by another request meanwhile.
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			wp_delete_file( $path );
		}
	}
}

if ( ! function_exists( 'woo_invoice_extract_zip_atomically' ) ) {
	/**
	 * Extract a downloaded zip so that its contents appear complete or not at all.
	 *
	 * The archive is extracted into a hidden temporary folder next to the destination and each
	 * top-level item is then moved into place with rename(). Another request can therefore never
	 * load a half-extracted library (e.g. mpdf/vendor/autoload.php before the rest of mPDF).
	 * Entries that would write outside the destination are rejected; the zip is always deleted.
	 *
	 * @param string $zip_file Downloaded zip.
	 * @param string $dest_dir Destination directory.
	 * @return bool
	 */
	function woo_invoice_extract_zip_atomically( $zip_file, $dest_dir ) {
		$dest_dir = trailingslashit( $dest_dir );
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file ) ) {
			wp_delete_file( $zip_file );
			return false;
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = str_replace( '\\', '/', (string) $zip->getNameIndex( $i ) );
			if ( '' === $name || false !== strpos( $name, '..' ) || '/' === $name[0] ) {
				$zip->close();
				wp_delete_file( $zip_file );
				return false;
			}
		}

		$tmp = $dest_dir . '.challan-extract-' . bin2hex( random_bytes( 4 ) ) . '/';
		$ok  = wp_mkdir_p( $tmp ) && $zip->extractTo( $tmp );
		$zip->close();
		wp_delete_file( $zip_file );

		if ( $ok ) {
			foreach ( array_diff( (array) scandir( $tmp ), array( '.', '..', '__MACOSX' ) ) as $item ) {
				$target = $dest_dir . $item;
				if ( file_exists( $target ) && is_dir( $target ) ) {
					// Replace an old (possibly half-extracted) copy: move it aside first.
					$old = $dest_dir . '.challan-old-' . bin2hex( random_bytes( 4 ) );
					if ( ! @rename( $target, $old ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Swap in the new copy.
						$ok = false;
						continue;
					}
					woo_invoice_delete_tree( $old );
				}
				if ( ! @rename( $tmp . $item, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move into place.
					$ok = false;
				}
			}
		}
		woo_invoice_delete_tree( $tmp );
		return $ok;
	}
}
