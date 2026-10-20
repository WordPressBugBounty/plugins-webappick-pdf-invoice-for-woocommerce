<?php
/**
 * Global Functions
 *
 * @since      3.3.25
 * @package    Challan_Free
 * @subpackage Challan_Free/functions
 * @author     Anwar <anwar.webappick@gmail.com>
 * @link       https://webappick.com
 */
// If this file is called directly, abort.
if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! function_exists('challan_load_mpdf_lib') ) :
/**
 * Load MPDF library
 *
 * @return bool
 */
function challan_load_mpdf_lib() {
    if ( class_exists('\Mpdf\Mpdf') || class_exists(\Mpdf\Mpdf::class) ) {
        return true;
    }
    if ( file_exists(CHALLAN_FREE_INVOICE_DIR . "mpdf/vendor/autoload.php") ) {
        require_once CHALLAN_FREE_INVOICE_DIR . "mpdf/vendor/autoload.php";
        return true;
    }
    return false;
}
challan_load_mpdf_lib();
endif;

if ( ! function_exists('woo_invoice_mpdf') ) :
/**
 * Create the mPDF instance used for every Challan PDF.
 *
 * Uses Challan_Free_Mpdf, which fixes the mPDF 8.0.x "unserialize(): Extra data" warning on PHP 8.3+.
 *
 * @since 3.7.91
 * @param array $config mPDF configuration.
 * @return \Mpdf\Mpdf
 */
function woo_invoice_mpdf( $config = array() ) {
    require_once CHALLAN_FREE_ROOT_FILE_PATH . 'includes/classes/class-challan-free-mpdf.php';

    return class_exists('Challan_Free_Mpdf') ? new Challan_Free_Mpdf($config) : new \Mpdf\Mpdf($config);
}
endif;
