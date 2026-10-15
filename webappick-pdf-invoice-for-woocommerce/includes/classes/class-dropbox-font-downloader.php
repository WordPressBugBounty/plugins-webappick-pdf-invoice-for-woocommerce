<?php

/**
 * Font downloader for DropBox
 *
 * @since      3.3.25
 * @package    Challan_Free
 * @subpackage Challan_Free/library
 * @author     Anwar <anwar.webappick@gmail.com>
 * @link       https://webappick.com
 */
class Challan_DropBoxFontDownloader
{
    /**
     * Rename file if contains html encoded space
     *
     * @param   $filePath
     * @return  void
     */
    static public function maybeRequireRename( $filePath ) {
        $fileSep    = explode('/', $filePath);
        $fileName   = $fileSep[ count($fileSep) - 1 ];
        if ( strpos($fileName, '%20') ) {
            $newFileName = str_replace('%20', ' ', $fileName);
            $newFilePath = CHALLAN_FREE_FONT_DIR . $newFileName;
//            rename($filePath, $newFilePath);
            copy($filePath, $newFilePath);
        }
    }

    /**
     * Dispatch font download process
     *
     * @param string $source
     * @param string $dest
     * @return  bool    true|false
     */
    static public function dispatchDownload( $source, $dest = '' ) {
        $fileSep = explode('/', $source);
        $destination = ! empty($dest) ? $dest : CHALLAN_FREE_FONT_DIR . $fileSep[ count($fileSep) - 1 ];

        //download font
        $downloadSuccess = self::downloadFont($source, $destination);

        if ( $downloadSuccess && self::isZipArchiveFont($destination) ) {
            //then extract zip file
            $downloadSuccess = self::extractZipArchiveFont($destination, CHALLAN_FREE_FONT_DIR);
        }
        self::rememberResult( basename( $destination ), (bool) $downloadSuccess );
        return (bool) $downloadSuccess;
    }

    /**
     * Remember a failed font download for a while, so the next page loads don't retry it
     * right away (prepareConfigForDownloadFont() skips it until then).
     *
     * @param string $file    Downloaded file name.
     * @param bool   $success Download result.
     * @return void
     */
    static public function rememberResult( $file, $success ) {
        $failed = get_transient( 'challan_font_download_failed' );
        $failed = is_array( $failed ) ? $failed : array();
        if ( $success ) {
            unset( $failed[ $file ] );
        } else {
            $failed[ $file ] = time();
        }
        if ( $failed ) {
            set_transient( 'challan_font_download_failed', $failed, 15 * MINUTE_IN_SECONDS );
        } else {
            delete_transient( 'challan_font_download_failed' );
        }
    }

    /**
     * Download font file from DropBox. If download being success return true either false
     *
     * @param string $source
     * @param string $destination
     * @return  bool      true|false
     */
    static public function downloadFont( $source, $destination ) {
        if ( ! woo_invoice_is_uploads_folder_writable() ) {
            return false;
        }

        // SSL verified, with a timeout; a failed download leaves no file behind.
        return woo_invoice_download_remote_file( $source, $destination, 60 );
    }

    /**
     * Check source file is a zip archive file or not.
     *
     * @param string $file
     * @return  bool    true|false
     */
    static public function isZipArchiveFont( $file ) {
        return $file && strpos($file, '.zip');
    }

    /**
     * Extract zip file to destination directory.
     *
     * @param string $zipFile
     * @param string $extractTo
     * @return  bool    true|false
     */
    static public function extractZipArchiveFont( $zipFile, $extractTo ) {
        // Each font file is moved into place complete, so a PDF being generated meanwhile never reads half a font.
        return woo_invoice_extract_zip_atomically( $zipFile, $extractTo );
    }


    /**
     * Prepare download configuration for MPDF
     *
     * @param   $return
     * @return  mixed|void
     */
    static public function prepareConfigForDownloadFont( $return = true ) {
        $fontConfig = include CHALLAN_FREE_ROOT_FILE_PATH . "includes/configs/config-fonts-dropbox.php";
        $fonts      = $fontConfig["others"];
        $downloaded = [];
        $download   = [];
        // Fonts whose download failed a moment ago wait before they are tried again.
        $failed     = get_transient( 'challan_font_download_failed' );
        $failed     = is_array( $failed ) ? $failed : array();
        foreach ( $fonts as $font ) {
            $fileSep = explode('/', $font);
            $destination = CHALLAN_FREE_FONT_DIR . $fileSep[ count($fileSep) - 1 ];
            if ( file_exists(woo_invoice_strip_zip_suffix($destination)) ) {
                $downloaded[] = $font;
            } elseif ( ! isset( $failed[ basename( $destination ) ] ) ) {
                $download[] = $font;
            }
        }
        $fontConfig["downloaded"]       = $downloaded;
        $fontConfig["download"]         = $download;
        $fontConfig["font_downloaded"]  = count($downloaded);
        $fontConfig["font_remaining"]   = count($download);
        $fontConfig["font_total"]       = count($fontConfig["others"]);
        if ( $return ) {
            return $fontConfig;
        }
    }
}
