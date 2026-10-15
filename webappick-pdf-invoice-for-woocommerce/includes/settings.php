<?php
/**
 * Scripts for plugin settings
 *
 * @since      3.3.25
 * @package    Challan_Free
 * @subpackage Challan_Free/settings
 * @author     Anwar <anwar.webappick@gmail.com>
 * @link       https://webappick.com
 */
// If this file is called directly, abort.
if ( ! defined('ABSPATH') ) {
    exit;
}


/**
 * Admin Notice if not writable uploads directory.
 */
function challan_font_dir_notice() { ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <?php
            _e('<h1>Challan</h1>  <b>Your uploads folder is not writable. Please make <code style="color: #ff0000;">wp-content/uploads</code> folder writable to generate and save invoices.</b>', 'webappick-pdf-invoice-for-woocommerce'); //phpcs:ignore
            ?>
        </p>
    </div> <?php
}


if ( defined('CHALLAN_FREE_INVOICE_DIR') ) {
    /**
     * Read and Write permission checker for Invoice directory
     *
     * @var string.
     * @since 2.3.1
     */
    $woo_invoice_base_dir = wp_upload_dir()['basedir'];
    $woo_invoice_invoice_dir_without_trailing_slash = rtrim(CHALLAN_FREE_INVOICE_DIR, '/');

    if ( ! file_exists($woo_invoice_invoice_dir_without_trailing_slash) && is_writable($woo_invoice_base_dir) ) {
        mkdir($woo_invoice_invoice_dir_without_trailing_slash, 0755, true);
        // Protect files from public access.
        touch(CHALLAN_FREE_INVOICE_DIR . '.htaccess');
        $woo_invoice_content = 'deny from all';
        $woo_invoice_fp = fopen(CHALLAN_FREE_INVOICE_DIR . '.htaccess', 'wb');
        fwrite($woo_invoice_fp, $woo_invoice_content);
        fclose($woo_invoice_fp);
    } elseif ( ! is_writable($woo_invoice_base_dir) ) {
        add_action('admin_notices', 'challan_font_dir_notice');
    }
}

if ( defined('CHALLAN_FREE_FONT_DIR') ) {
    /**
     * Read and Write permission checker for Custom Font Directory..
     *
     * @var string
     * @since 2.3.1
     */
    $woo_invoice_base_dir = wp_upload_dir()['basedir'];
    $wpifw_invoice_font_dir = rtrim(CHALLAN_FREE_FONT_DIR, '/');

    if ( ! file_exists($wpifw_invoice_font_dir) && is_writable($woo_invoice_base_dir) ) {
        mkdir($wpifw_invoice_font_dir, 0755, true);

        // Protect files from public access.
        touch(CHALLAN_FREE_FONT_DIR . '.htaccess');
        $woo_invoice_content = 'deny from all';
        $woo_invoice_fp = fopen(CHALLAN_FREE_FONT_DIR . '.htaccess', 'wb');
        fwrite($woo_invoice_fp, $woo_invoice_content);
        fclose($woo_invoice_fp);
    }
}