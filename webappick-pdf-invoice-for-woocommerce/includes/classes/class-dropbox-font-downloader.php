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

        if ( $downloadSuccess ) {
            if ( self::isZipArchiveFont($destination) ) {
                //then extract zip file
                if ( self::extractZipArchiveFont($destination, CHALLAN_FREE_FONT_DIR) ) {
//                    self::maybeRequireRename($destination);
                    return true;
                } else {
                    return false;
                }
            }
            return true;
        } else {
            return false;
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
            return;
        }

        // Load WordPress Filesystem API
        global $wp_filesystem;

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
        // Security: Enable SSL verification to prevent MITM attacks
        $arrContextOptions = array(
            "ssl" => array(
                "verify_peer"      => true,
                "verify_peer_name" => true,
            ),
        );
        $data = file_get_contents( $source, false, stream_context_create( $arrContextOptions ) ); // phpcs:ignore

        // Use the WordPress Filesystem API to write the data to the destination
        if ( $wp_filesystem->put_contents( $destination, $data, FS_CHMOD_FILE ) ) {
            return true;
        } else {
            return false;
        }
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
        $zip = new ZipArchive();
        $res = $zip->open($zipFile);
        if ( TRUE === $res ) {
            if ( ! self::zipEntriesAreSafe( $zip ) ) {
                $zip->close();
                wp_delete_file( $zipFile );
                return false;
            }
            $zip->extractTo($extractTo);
            $zip->close();
            if ( file_exists( $zipFile ) ) {
                wp_delete_file( $zipFile );
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Reject ZIP entries that would write outside the destination directory.
     *
     * @param ZipArchive $zip Open archive.
     * @return bool
     */
    private static function zipEntriesAreSafe( $zip ) {
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = $zip->getNameIndex( $i );
            if ( ! is_string( $entry_name ) ) {
                return false;
            }
            $entry_name = str_replace( '\\', '/', $entry_name );
            if ( false !== strpos( $entry_name, '..' ) || ( isset( $entry_name[0] ) && '/' === $entry_name[0] ) ) {
                return false;
            }
        }
        return true;
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
        foreach ( $fonts as $font ) {
            $fileSep = explode('/', $font);
            $destination = CHALLAN_FREE_FONT_DIR . $fileSep[ count($fileSep) - 1 ];
            if ( file_exists(rtrim($destination, ".zip")) ) {
                $downloaded[] = $font;
            } else {
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
