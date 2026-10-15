<?php
/**
 * Data file downloader for MPDF from DropBox
 *
 * @since      3.3.25
 * @package    Challan_Free
 * @subpackage Challan_Free/library
 * @author     Anwar <anwar.webappick@gmail.com>
 * @link       https://webappick.com
 */

/**
 * MPDF library downloader
 */
class Challan_MpdfLibDownloader {

    static public function hasDownloaded(){
        return file_exists(CHALLAN_FREE_INVOICE_DIR . "mpdf/vendor/autoload.php");
    }
    /**
     * Download data files for MPDF
     *
     * @return  bool true|false
     */
    static public function downloadLib(){
        //has downloaded already
        if ( self::hasDownloaded() ) {
            return true;
        }

        if ( ! woo_invoice_is_uploads_folder_writable() ) {
            return false;
        }
        // default font downloader
        $fontConfig     = include CHALLAN_FREE_ROOT_FILE_PATH . "includes/configs/config-fonts-dropbox.php"; // phpcs:ignore
        $baseUrl        = implode("", $fontConfig['base-url']);
        $path           = $fontConfig['mpdf-lib'];
        $source         = "{$baseUrl}{$path}";
        $fileSep        = explode('/', $source);
        $destination    = CHALLAN_FREE_INVOICE_DIR . $fileSep[ count($fileSep) - 1 ];
        //download file (SSL verified, with a timeout; a failed download leaves no file behind)
        if ( ! woo_invoice_download_remote_file( $source, $destination, 120 ) ) {
            return false;
        }
        // Extracted so that mpdf/vendor/autoload.php only appears once the whole library is in place.
        return woo_invoice_extract_zip_atomically( $destination, CHALLAN_FREE_INVOICE_DIR ) && self::hasDownloaded();
    }

}
