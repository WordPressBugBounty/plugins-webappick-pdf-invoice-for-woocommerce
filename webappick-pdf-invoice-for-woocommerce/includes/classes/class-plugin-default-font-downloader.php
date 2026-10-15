<?php
/**
 * Plugin default Font downloader for MPDF from DropBox
 *
 * @since      3.3.25
 * @package    Challan_Free
 * @subpackage Challan_Free/library
 * @author     Anwar <anwar.webappick@gmail.com>
 * @link       https://webappick.com
 */

class Challan_PluginDefaultFontDownloader {

    /**
     * Dispatch font download
     *
     * @return  bool true|false
     */
    static public function downloadDefaultFonts(){
        if ( ! class_exists("Challan_DropBoxFontDownloader") ) {
            require_once CHALLAN_FREE_ROOT_FILE_PATH . "includes/classes/class-dropbox-font-downloader.php";
        }
        // default font downloader
        $fontConfig         = include CHALLAN_FREE_ROOT_FILE_PATH . "includes/configs/config-fonts-dropbox.php"; // phpcs:ignore
        $baseUrl            = implode("", $fontConfig['base-url']);
        $downloadCounter    = 0;
        $allDownloaded      = true;
        foreach ( $fontConfig['default'] as $path ) {
            $source         = "{$baseUrl}{$path}";
            $fileSep        = explode('/', $source);
            $destination    = CHALLAN_FREE_FONT_DIR . $fileSep[ count($fileSep) - 1 ];
            if ( ! file_exists(woo_invoice_strip_zip_suffix($destination)) ) {
                if ( ! Challan_DropBoxFontDownloader::dispatchDownload("{$baseUrl}{$path}") ) {
                    $allDownloaded = false;
                }
                ++$downloadCounter;
                if ( 0 == $downloadCounter % 4 ) {
                    sleep(1);
                }
            }
        }
        return $allDownloaded;
    }
}
