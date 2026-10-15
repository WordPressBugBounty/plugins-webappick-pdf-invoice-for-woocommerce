<?php
/**
 * Fonts downloader for admin ui from DropBox
 *
 * @since      3.3.25
 * @package    Challan_Free
 * @subpackage Challan_Free/library
 * @author     Anwar <anwar.webappick@gmail.com>
 * @link       https://webappick.com
 */

/**
 * Admin UI font downloader
 */
class Challan_AdminUIFontDownloader
{

    /**
     * Font configuration form config file
     *
     * @var array
     */
    static public $fontConfig = [];

    /**
     * Download data files for MPDF
     *
     * @return  bool true|false
     */
    static public function downloadFont() {
        // The admin UI font is no longer downloaded: its archive is not published any more
        // (the request always failed) and the admin CSS does not load it from this folder.
        return false;
    }

    /**
     * Check fonts already downloaded or not
     *
     * @return bool
     */
    static public function hasDownloaded() {
        $fontConfig = self::getConfig();
        foreach ( $fontConfig['admin-ui-font-list'] as $fontpath ) {
            if ( file_exists(CHALLAN_ADMIN_UI_FONTS_DIR . $fontpath) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load and get default config
     *
     * @return array|mixed
     */
    static public function getConfig() {
        if ( empty(self::$fontConfig) ) {
            self::$fontConfig = include CHALLAN_FREE_ROOT_FILE_PATH . "includes/configs/config-fonts-dropbox.php"; // phpcs:ignore
        }
        return self::$fontConfig;
    }

}
