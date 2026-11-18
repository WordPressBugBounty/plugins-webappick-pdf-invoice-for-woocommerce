<?php
/**
 * Version-gated upgrade routines.
 *
 * @package Challan
 */

namespace Challan;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Runs once per plugin version bump (stored in challan_db_version) —
 * settings migrations for updates that change how an option is
 * interpreted. Migrations must be idempotent and must never overwrite
 * an explicit admin choice.
 */
final class Upgrader {

	const VERSION_OPTION = 'challan_db_version';

	/**
	 * Set once the move to the current templates has run (one time only, so a
	 * later admin choice is never overwritten).
	 */
	const TEMPLATE_MIGRATION_OPTION = 'challan_template_migrated';

	/**
	 * The v1 template id a store had before the move to Simple (kept for
	 * a rollback to 3.x, see prepare_v1_rollback()).
	 */
	const V1_TEMPLATE_OPTION = 'challan_v1_templateid';

	/**
	 * The invoice templates v1 Free ships; v1 Free prints any other id
	 * unstyled.
	 */
	const V1_FREE_TEMPLATES = array( 'invoice-1', 'invoice-2' );

	/**
	 * Set once the v1 logo URL has been resolved to an attachment id.
	 */
	const LOGO_MIGRATION_OPTION = 'challan_logo_migrated';

	/**
	 * Set once the packing-slip paper got an explicit 'custom' choice.
	 */
	const PACKING_PAPER_MIGRATION_OPTION = 'challan_packing_paper_migrated';

	/**
	 * Set once a store upgraded from v1 Free got its v1 behaviour pinned.
	 */
	const V1_FREE_MIGRATION_OPTION = 'challan_v1_free_migrated';

	/**
	 * Written by Activation on a store with no v1 data (a new install).
	 */
	const NEW_INSTALL_OPTION = 'challan_new_install';

	/**
	 * Files removed from the v1 fonts folder by secure_legacy_font_dir()
	 * (time + paths), kept for the site owner. Not autoloaded.
	 */
	const LEGACY_FONT_DIR_REMOVED_OPTION = 'challan_legacy_font_dir_removed';

	/**
	 * The v1 fonts folder, under uploads. v2 still reads fonts from it.
	 */
	const LEGACY_FONT_DIR = 'WOO-INVOICE/WOO-INVOICE-FONTS';

	/**
	 * The delivery-address template v1 Free's activator seeded. v1 Free
	 * never read it (its labels print the formatted shipping address).
	 */
	const V1_FREE_LABEL_SEED = "{{billing_first_name}}\t{{billing_last_name}}\n{{billing_company}}\t{{billing_address_1}}\n{{billing_address_2}}\t{{billing_city}}\n{{billing_state}}\t{{billing_postcode}}\n{{billing_country}}\t{{billing_phone}}\n{{billing_email}}\t\n{{shipping_first_name}}\t{{shipping_last_name}}\n{{shipping_company}}\t{{shipping_address_1}}\n{{shipping_address_2}}\t{{shipping_city}}\n{{shipping_state}}\t{{shipping_postcode}}\n{{shipping_country}}\t{{shipping_phone}}\n{{shipping_email}}";

	/**
	 * Run pending migrations when the stored version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		self::migrate_legacy_template();
		self::migrate_legacy_logo();
		self::migrate_packing_slip_custom_paper();
		self::migrate_v1_free_store();

		if ( get_option( self::VERSION_OPTION ) === CHALLAN_VERSION ) {
			return;
		}

		self::migrate_invoice_custom_paper();

		// Updates skip the activation hook: create and protect the upload
		// folders (.htaccess deny + index.html) and queue the font pack,
		// as a fresh activation does.
		Activation::protect_upload_dirs();
		self::secure_legacy_font_dir();
		Documents\FontDownloader::schedule();

		update_option( self::VERSION_OPTION, CHALLAN_VERSION );
	}

	/**
	 * Secure the v1 fonts folder (uploads/WOO-INVOICE/WOO-INVOICE-FONTS),
	 * which v2 keeps reading fonts from.
	 *
	 * Challan Pro up to 4.5.15 let any logged-in user upload any file into
	 * it, including a replacement .htaccess (CVE-2026-15504). On a site that
	 * was attacked, such files would survive the update to v2. So, once per
	 * version: remove files that a web server could run or that change its
	 * configuration (fonts and everything else stay), and restore the
	 * folder's deny-all .htaccess. Removed paths are logged and kept in
	 * an option.
	 *
	 * @return string[] Removed file paths.
	 */
	public static function secure_legacy_font_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array();
		}
		$dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . self::LEGACY_FONT_DIR;
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return array();
		}

		$removed = array();
		$files   = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			if ( ! $file->isFile() && ! $file->isLink() ) {
				continue;
			}
			$name = $file->getFilename();
			$top  = wp_normalize_path( $file->getPath() ) === $dir;
			if ( self::is_unsafe_upload_name( $name, $top ) && wp_delete_file_from_directory( $file->getPathname(), $dir ) ) {
				$removed[] = wp_normalize_path( $file->getPathname() );
			}
		}

		// mPDF reads fonts from disk; nothing in this folder is served.
		file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- own uploads subfolder.
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- own uploads subfolder.
		}

		if ( array() !== $removed ) {
			update_option(
				self::LEGACY_FONT_DIR_REMOVED_OPTION,
				array(
					'time'  => time(),
					'files' => $removed,
				),
				false
			);
			error_log( 'Challan: removed files that do not belong in the fonts folder: ' . implode( ', ', $removed ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a security clean-up the site owner should be able to find.
		}

		return $removed;
	}

	/**
	 * Whether a file in the fonts folder is one a web server could run or
	 * that changes its configuration.
	 *
	 * @param string $name File name.
	 * @param bool   $top  Whether it sits directly in the fonts folder (whose
	 *                     own .htaccess is rewritten, not removed).
	 * @return bool
	 */
	private static function is_unsafe_upload_name( $name, $top ) {
		$lower = strtolower( $name );
		if ( '.htaccess' === $lower ) {
			return ! $top;
		}
		if ( in_array( $lower, array( '.user.ini', 'web.config', 'php.ini' ), true ) ) {
			return true;
		}

		return 1 === preg_match( '/\.(php\d*|phtml|pht|phar|phps|shtml|cgi|pl|py|jsp|aspx?|sh)(\.|$)/i', $name );
	}

	/**
	 * 4.0: the invoice paper select gained a 'custom' choice and W/H
	 * now apply only when it is selected (the Labels rule). v1 applied
	 * W/H whenever both were set — sites in that state get an explicit
	 * 'custom' so their documents keep the same dimensions.
	 *
	 * @return void
	 */
	private static function migrate_invoice_custom_paper() {
		$width  = (float) get_option( 'wpifw_invoice_custom_paper_wide', 0 );
		$height = (float) get_option( 'wpifw_invoice_custom_paper_height', 0 );

		if ( $width > 0 && $height > 0 && 'custom' !== (string) get_option( 'wpifw_invoice_paper_size', '' ) ) {
			update_option( 'wpifw_invoice_paper_size', 'custom' );
		}
	}

	/**
	 * Simple is the default template (user decision 2026-10-20). Stores on
	 * a v1 template (invoice-1…9), an unknown id or none move to Simple —
	 * once. Stores already on one of the four templates keep it. The id
	 * being replaced is kept in V1_TEMPLATE_OPTION.
	 *
	 * @return void
	 */
	private static function migrate_legacy_template() {
		if ( get_option( self::TEMPLATE_MIGRATION_OPTION ) ) {
			return;
		}

		$current   = (string) get_option( 'wpifw_templateid', '' );
		$templates = Documents\Templates::all();
		if ( ! isset( $templates[ $current ] ) ) {
			if ( '' !== $current ) {
				update_option( self::V1_TEMPLATE_OPTION, $current, false );
			}
			update_option( 'wpifw_templateid', Documents\Templates::DEFAULT_TEMPLATE );
		}

		update_option( self::TEMPLATE_MIGRATION_OPTION, '1' );
	}

	/**
	 * Get the stored settings ready for a v1 (3.x) plugin, after Version
	 * control installed one over this plugin.
	 *
	 * Challan 3.x (v1) Free has no Simple, Modern, Classic or POS stylesheet: given any
	 * of those ids it prints the invoice unstyled. Put back the v1
	 * template the store had before the update (v1 Free's own two only),
	 * else v1's default. The migration flag is cleared so that updating to
	 * v2 again moves the store to Simple again.
	 *
	 * @return string The template id v1 will print with.
	 */
	public static function prepare_v1_rollback() {
		$saved  = (string) get_option( self::V1_TEMPLATE_OPTION, '' );
		$target = in_array( $saved, self::V1_FREE_TEMPLATES, true ) ? $saved : self::V1_FREE_TEMPLATES[0];

		update_option( 'wpifw_templateid', $target );
		delete_option( self::TEMPLATE_MIGRATION_OPTION );

		return $target;
	}

	/**
	 * Resolve a logo still stored as a URL into an attachment, once.
	 *
	 * The v1 plugin seeded wpifw_logo_attachment_id with the theme logo URL on activation
	 * and only turned it into an attachment (file path + image id) when a
	 * document was rendered. Stores that never rendered one after setting the
	 * logo still hold the URL: resolve it once, as v1 would have.
	 *
	 * @return void
	 */
	private static function migrate_legacy_logo() {
		if ( get_option( self::LOGO_MIGRATION_OPTION ) ) {
			return;
		}

		$legacy = get_option( 'wpifw_logo_attachment_id' );
		if ( (int) get_option( 'wpifw_logo_attachment_image_id' ) < 1
			&& is_string( $legacy )
			&& preg_match( '#^https?://#', $legacy ) ) {
			$image_id = attachment_url_to_postid( $legacy ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid -- One-time upgrade step guarded by LOGO_MIGRATION_OPTION; not a hot path.
			if ( $image_id ) {
				update_option( 'wpifw_logo_attachment_id', (string) get_attached_file( $image_id ) );
				update_option( 'wpifw_logo_attachment_image_id', (string) $image_id );
			}
		}

		update_option( self::LOGO_MIGRATION_OPTION, '1' );
	}

	/**
	 * The packing-slip paper select gained an explicit 'custom' choice
	 * (W/H now apply only with it, like the invoice). v1 free applied W/H
	 * whenever both were set, so such sites get 'custom' and keep their
	 * size. v1 pro stores (the hyphenated wpifw-pickingslip-paper-size
	 * exists) are left to Challan Pro, which copies their own choice.
	 *
	 * @return void
	 */
	private static function migrate_packing_slip_custom_paper() {
		if ( get_option( self::PACKING_PAPER_MIGRATION_OPTION ) ) {
			return;
		}

		$width  = (float) get_option( 'wpifw_pickingslip_custom_paper_wide', 0 );
		$height = (float) get_option( 'wpifw_pickingslip_custom_paper_height', 0 );
		if ( $width > 0 && $height > 0 && false === get_option( 'wpifw-pickingslip-paper-size' ) ) {
			update_option( 'wpifw_pickingslip_paper_size', 'custom' );
		}

		update_option( self::PACKING_PAPER_MIGRATION_OPTION, '1' );
	}

	/**
	 * A store upgraded from v1 Free keeps what v1 Free printed — once.
	 *
	 * The v1 Free plugin had no numbering mode, packing-slip options or label template
	 * of its own; v2 has these settings with new defaults, so without this
	 * step an upgraded store would change behaviour:
	 *
	 * - Numbering: v1 Free always numbered prefix + counter + suffix and
	 *   never stored `wpifw_invoice_number_type` (v2's default is the order
	 *   number). Pinned to `pre_custom_number_suf`.
	 * - Packing slip: v1 Free printed it on the invoice paper (custom size
	 *   from the packing-slip W/H) with the invoice's product identifier and
	 *   title length. Copied into the packing-slip settings.
	 * - Labels: v1 Free's activator seeded a billing + shipping macro
	 *   template that v1 Free never used. Cleared while unchanged, so labels
	 *   keep v1's formatted shipping address (+ phone / email).
	 *
	 * v1 Pro stores are detected by `wpifw_invoice_number_type`, which v1 Pro
	 * always stored, and are left to Challan Pro's upgrader. New installs
	 * carry NEW_INSTALL_OPTION and are untouched.
	 *
	 * @return void
	 */
	private static function migrate_v1_free_store() {
		if ( get_option( self::V1_FREE_MIGRATION_OPTION ) ) {
			return;
		}

		// v1 Free numbered every new order as prefix + counter + suffix. The
		// counter option exists once v1 numbered one order; a prefix or
		// suffix alone (e.g. a network-activated sub-site that never got
		// v1's activation defaults) means the same scheme.
		$v1_numbering = false !== get_option( 'wpifw_invoice_no' )
			|| '' !== (string) get_option( 'wpifw_invoice_no_prefix', '' )
			|| '' !== (string) get_option( 'wpifw_invoice_no_suffix', '' );
		if ( false === get_option( self::NEW_INSTALL_OPTION )
			&& false === get_option( 'wpifw_invoice_number_type' )
			&& $v1_numbering ) {
			update_option( 'wpifw_invoice_number_type', 'pre_custom_number_suf' );
			// Tells Challan Pro this store came from v1 Free, not v1 Pro.
			update_option( 'challan_v1_free_store', '1', false );

			$paper = (string) get_option( 'wpifw_invoice_paper_size', '' );
			$paper = 'Letter' === $paper ? 'letter' : $paper;
			update_option( 'wpifw_pickingslip_paper_size', '' !== $paper ? $paper : 'A4' );

			foreach ( array(
				'wpifw_packingslip_disid'                => 'wpifw_disid',
				'wpifw_packingslip_product_title_length' => 'wpifw_invoice_product_title_length',
			) as $packing_key => $invoice_key ) {
				$value = get_option( $invoice_key );
				if ( false === get_option( $packing_key ) && false !== $value ) {
					update_option( $packing_key, $value );
				}
			}

			$label = str_replace( "\r\n", "\n", (string) get_option( 'wpifw_delivery_address_buyer', '' ) );
			if ( trim( $label ) === trim( self::V1_FREE_LABEL_SEED ) ) {
				update_option( 'wpifw_delivery_address_buyer', '' );
			}
		}

		update_option( self::V1_FREE_MIGRATION_OPTION, '1' );
	}
}
