<!-- SYSTEM STATUS TAB-->
<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
?>
<li>
    <div class="woo-invoice-row">
        <div class="woo-invoice-col-sm-8">
            <div class="woo-invoice-card woo-invoice-mr-0">
                <div class="woo-invoice-card-body">
                    <table class="system-status-table">
                        <tbody>
                            <?php
                            // Read plugin header data.
                            $woo_invoice_chalan_plugin_data = get_plugin_data(CHALLAN_FREE_ROOT_FILE);

                            // WordPress check upload file size.
                            function woo_invoice_wp_minimum_upload_file_size()
                            {
                                $wp_size = wp_max_upload_size();
                                if (!$wp_size) {
                                    $wp_size = 'unknown';
                                } else {
                                    $wp_size = round(($wp_size / 1024 / 1024));
                                    $wp_size = $wp_size == 1024 ? '1GB' : $wp_size . 'MB'; //phpcs:ignore
                                }

                                return $wp_size;
                            }

                            // Minimum upload size set by hosting provider.
                            function woo_invoice_wp_upload_size_from_hosting()
                            {
                                $ini_size = ini_get('upload_max_filesize');
                                if (!$ini_size) {
                                    $ini_size = 'unknown';
                                } elseif (is_numeric($ini_size)) {
                                    $ini_size .= ' bytes';
                                } else {
                                    $ini_size .= 'B';
                                }

                                return $ini_size;
                            }

                            function woo_invoice_convert_to_bytes(string $from): ?int
                            {
                                $units  = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
                                $number = substr($from, 0, -2);
                                $suffix = strtoupper(substr($from, -2));

                                //B or no suffix
                                if (is_numeric(substr($suffix, 0, 1))) {
                                    return preg_replace('/[^\d]/', '', $from);
                                }

                                $exponent = array_flip($units)[$suffix] ?? null;
                                if ($exponent === null) { //phpcs:ignore
                                    return null;
                                }

                                return $number * (1024 ** $exponent);
                            }

                            // Check upload folder is writable.
                            function woo_invoice_upload_folder_is_writable()
                            {
                                $upload_dir                   = wp_upload_dir();
                                $base_dir                     = $upload_dir['basedir'];
                                $wpifw_invoice_dir            = $base_dir . "/WOO-INVOICE";

                                // Check if the directory exists and is writable using wp_is_writable().
                                if (!file_exists($wpifw_invoice_dir)) {
                                    if (!wp_mkdir_p($wpifw_invoice_dir)) {
                                        return 0; // Directory couldn't be created, return 0.
                                    }
                                }

                                // Check if the WOO-INVOICE directory is writable.
                                $upload_dir_permission_status = wp_is_writable($wpifw_invoice_dir) ? '1' : '0';

                                return $upload_dir_permission_status;
                            }

                            // Check zipArchive extension enable from hosting.
                            function woo_invoice_check_zip_extension()
                            {
                                $extension = '';
                                $extension = in_array('zip', get_loaded_extensions());

                                return $extension;
                            }

                            // Check MBstring extension enable from hosting.
                            function woo_invoice_check_mbstring_extension()
                            {
                                $extension = '';
                                $extension = in_array('mbstring', get_loaded_extensions());

                                return $extension;
                            }

                            // Check dom extension

                            function woo_invoice_check_dom_extension()
                            {
                                $extension = '';
                                $extension = in_array('dom', get_loaded_extensions());

                                return $extension;
                            }

                            // Minimum PHP version.
                            $woo_invoice_chalan_current_php_version = phpversion();
                            $woo_invoice_chalan_minimum_php_version = $woo_invoice_chalan_plugin_data['RequiresPHP'] ? $woo_invoice_chalan_plugin_data['RequiresPHP'] : '5.6';
                            $woo_invoice_chalan_php_version_status         = $woo_invoice_chalan_current_php_version < $woo_invoice_chalan_minimum_php_version ? 0 : 1;

                            // Minimum WordPress Version.
                            $woo_invoice_chalan_wp_current_version = get_bloginfo('version');
                            $woo_invoice_chalan_minimum_wp_version = $woo_invoice_chalan_plugin_data['RequiresWP'] ? $woo_invoice_chalan_plugin_data['RequiresWP'] : '4.4';
                            $woo_invoice_chalan_wp_version_status         = $woo_invoice_chalan_wp_current_version < $woo_invoice_chalan_minimum_wp_version ? 0 : 1;

                            // WooCommerce Version Check
                            $woo_invoice_chalan_wc_current_version = class_exists('woocommerce') ? WC_VERSION : 'Not Active WooCommerce';

                            // Default to '3.2' or your plugin’s minimum version requirement
                            $woo_invoice_chalan_minimum_wc_version = isset($woo_invoice_chalan_plugin_data['WC requires at least']) ? $woo_invoice_chalan_plugin_data['WC requires at least'] : '3.2';

                            // Version comparison
                            $woo_invoice_chalan_wc_status = version_compare($woo_invoice_chalan_wc_current_version, $woo_invoice_chalan_minimum_wc_version, '>=');

                            // WordPress minimum upload size .
                            $woo_invoice_wp_minimum_upload_file_size = '40MB';

                            // Minimum WordPress upload size..
                            $woo_invoice_chalan_wp_upload_size_status = woo_invoice_convert_to_bytes(woo_invoice_wp_minimum_upload_file_size()) < woo_invoice_convert_to_bytes($woo_invoice_wp_minimum_upload_file_size) ? 0 : 1;

                            // Minimum upload file size from hosting provider.
                            $woo_invoice_chalan_wp_upload_size_status_from_hosting = woo_invoice_convert_to_bytes(woo_invoice_wp_upload_size_from_hosting()) < woo_invoice_convert_to_bytes($woo_invoice_wp_minimum_upload_file_size) ? 0 : 1;

                            // PHP Limit Time
                            $woo_invoice_chalan_php_minimum_limit_time = '120';
                            $woo_invoice_chalan_php_current_limit_time = ini_get('max_execution_time');
                            $woo_invoice_chalan_php_limit_time_status = $woo_invoice_chalan_php_minimum_limit_time <= $woo_invoice_chalan_php_current_limit_time ? 1 : 0;

                            // PHP Max Input Vars.
                            $woo_invoice_chalan_php_max_input_var = '300';
                            $woo_invoice_chalan_php_current_max_input_var = ini_get('max_input_vars');
                            $woo_invoice_chalan_php_max_input_var_status = $woo_invoice_chalan_php_max_input_var <= $woo_invoice_chalan_php_current_max_input_var ? 1 : 0;


                            // Check WordPress debug status.
                            $woo_invoice_chalan_wp_debug_status = WP_DEBUG == true ? 1 : 0;

                            // Check upload folder is writable.
                            $woo_invoice_chalan_uplaod_folder_writable_status = woo_invoice_upload_folder_is_writable() == 0 ? 0 : 1;

                            // Check if zipArchie extension is enable in hosting.
                            $woo_invoice_chalan_check_zip_extension_status = woo_invoice_check_zip_extension() != '1' ? 0 : '1';

                            // Check MBstring extension from hsoting.
                            $woo_invoice_chalan_check_mbstring_extension_status = woo_invoice_check_mbstring_extension() != '1' ? 0 : '1';

                            // Check dom extension.
                            $woo_invoice_chalan_check_dom_extension_status = woo_invoice_check_dom_extension() != '1' ? 0 : '1';

                            $woo_invoice_system_status = array(

                                array(
                                    'title'           => esc_html__('PHP Version', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => esc_html__('Current Version:  ', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_chalan_current_php_version,
                                    'status'          => $woo_invoice_chalan_php_version_status,
                                    'success_message' => esc_html__('- ok', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message' => esc_html__('Required Version: ', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_chalan_minimum_php_version, //phpcs:ignore
                                ),

                                array(
                                    'title'           => esc_html__('WordPress Version', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => $woo_invoice_chalan_wp_current_version,
                                    'status'          => $woo_invoice_chalan_wp_version_status,
                                    'success_message' => esc_html__('- ok', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message' => esc_html__('Required: ', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_chalan_minimum_wp_version, //phpcs:ignore
                                ),

                                array(
                                    'title'           => esc_html__('Woocommerce Version', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => $woo_invoice_chalan_wc_current_version,
                                    'status'          => $woo_invoice_chalan_wc_status,
                                    'success_message' => esc_html__('- ok', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message' => esc_html__('Required: ', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_chalan_minimum_wc_version, //phpcs:ignore
                                ),

                                array(
                                    'title'           => esc_html__('WordPress Upload Limit', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => woo_invoice_wp_minimum_upload_file_size(),
                                    'status'          => $woo_invoice_chalan_wp_upload_size_status,
                                    'success_message' => esc_html__('- ok', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message' => esc_html__('Required:', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_wp_minimum_upload_file_size,    //phpcs:ignore
                                ),

                                array(
                                    'title'           => esc_html__('WordPress Upload Limit Set By Hosting Provider', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => woo_invoice_wp_upload_size_from_hosting(),
                                    'status'          => $woo_invoice_chalan_wp_upload_size_status_from_hosting,
                                    'success_message' => esc_html__('- ok', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message' => esc_html__('Required:', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_wp_minimum_upload_file_size, //phpcs:ignore
                                ),

                                array(
                                    'title'           => esc_html__('PHP Limit Time', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => esc_html__('Current Limit Time: ', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_chalan_php_current_limit_time,
                                    'status'          => $woo_invoice_chalan_php_limit_time_status,
                                    'success_message' => esc_html__('- Ok', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message' => esc_html__('Required:', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_chalan_php_minimum_limit_time,    //phpcs:ignore
                                ),

                                array(
                                    'title'           => esc_html__('PHP Max Input Vars', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => esc_html__('Current PHP Max Input Vars: ', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_chalan_php_current_max_input_var,
                                    'status'          => $woo_invoice_chalan_php_max_input_var_status,
                                    'success_message' => esc_html__('- Ok', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message' => esc_html__('Recommend: ', 'webappick-pdf-invoice-for-woocommerce') . $woo_invoice_chalan_php_max_input_var,    //phpcs:ignore
                                ),


                                array(
                                    'title'           => esc_html__('WordPress Upload Directory Writable Permission', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => '',
                                    'status'          => $woo_invoice_chalan_uplaod_folder_writable_status,
                                    'success_message' => esc_html__('Writable - Ok', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message'   => esc_html__('Upload folder not writable permission', 'webappick-pdf-invoice-for-woocommerce'),
                                ),

                                array(
                                    'title'           => esc_html__('WordPress Debug Mode', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => '',
                                    'status'          => $woo_invoice_chalan_wp_debug_status,
                                    'success_message' => esc_html__('WordPress Debug Mode is On', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message'   => __('<b>WP_DEBUG_LOG</b> is false. Plugin can not write error logs if WP_DEBUG_LOG is set to false. You can learn more about debugging in WordPress from <a target="_blank" href="https://wordpress.org/support/article/debugging-in-wordpress/">here</a>', 'webappick-pdf-invoice-for-woocommerce'),
                                ),

                                array(
                                    'title'           => esc_html__('zipArchive Extension', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => '',
                                    'status'          => $woo_invoice_chalan_check_zip_extension_status,
                                    'success_message' => esc_html__('Enable', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message'   => esc_html__('Please enable zip extension from hosting.', 'webappick-pdf-invoice-for-woocommerce'),
                                ),

                                array(
                                    'title'           => esc_html__('MBString extension', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => '',
                                    'status'          => $woo_invoice_chalan_check_mbstring_extension_status,
                                    'success_message' => esc_html__('Enable', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message'   => esc_html__('Please enable MBString extension from hosting.', 'webappick-pdf-invoice-for-woocommerce'),
                                ),

                                array(
                                    'title'           => esc_html__('Dom extension', 'webappick-pdf-invoice-for-woocommerce'),
                                    'version'         => '',
                                    'status'          => $woo_invoice_chalan_check_dom_extension_status,
                                    'success_message' => esc_html__('Enable', 'webappick-pdf-invoice-for-woocommerce'),
                                    'error_message'   => esc_html__('Dom extension is not enable from hosting.', 'webappick-pdf-invoice-for-woocommerce'),
                                ),
                            );
                            ?>
                            <tr>
                                <th><?php esc_html_e('Title', 'webappick-pdf-invoice-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Status', 'webappick-pdf-invoice-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Message', 'webappick-pdf-invoice-for-woocommerce'); ?></th>
                            </tr>
                            <!-- PHP Version -->
                            <?php
                            foreach ($woo_invoice_system_status as $woo_invoice_value) { ?>
                                <tr>
                                    <td><?php printf('%s', esc_html($woo_invoice_value['title'])); ?></td>

                                    <td>
                                        <?php if (1 == $woo_invoice_value['status']) { ?>
                                            <span class="dashicons dashicons-yes"></span>
                                        <?php } else { ?>
                                            <span class="dashicons dashicons-warning"></span>

                                        <?php }; ?>
                                    </td>
                                    <td>
                                        <?php if (1 == $woo_invoice_value['status']) { ?>
                                            <p class="wpifw_status_message"> <?php printf('%s', esc_html($woo_invoice_value['version'])); ?> <?php echo $woo_invoice_value['success_message']; //phpcs:ignore 
                                                                                                                                    ?></p>
                                        <?php } else { ?>
                                            <?php printf('%s', esc_html($woo_invoice_value['version'])); ?>
                                            <p class="wpifw_status_message"><?php echo $woo_invoice_value['error_message']; //phpcs:ignore 
                                                                            ?></p>

                                        <?php }; ?>

                                    </td>
                                </tr>
                            <?php } ?>

                        </tbody>
                    </table>

                </div><!-- end .woo-invoice-card-body -->
            </div><!-- end .woo-invoice-card -->
        </div><!-- end .woo-invoice-col-sm-8 -->

        <div class="woo-invoice-col-sm-4 woo-invoice-col-12 ">
            <div class="woo-invoice-banner-sidebar">
                <?php require __DIR__ . '/../banner-card.php'; ?>

                <div class="woo-invoice-banner-container">
                    <div class="woo-invoice-banner-logo">
                        <a class="wapk-woo-invoice-banner-logo" href="<?php echo esc_url('https://webappick.com/plugin/woocommerce-pdf-invoice-packing-slips/?utm_source=customer_site&utm_medium=free_vs_pro&utm_campaign=woo_invoice_free'); ?>" target="_blank"><img src="<?php echo esc_url($woo_invoice_banner_logo_dir); //phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>" alt="Woo Invoice"></a>
                    </div>
                    <div class="woo-invoice-banner-title">
                        <h2>Why Upgrade to the Challan Pro?</h2>
                        <ul>
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                Customizable Readymade Templates
                            </li>

                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                Paid Stamp, Signature, Watermark
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                Multilingual & Multicurrency Invoice
                            </li>
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                Multiple Tax Classes Support
                            </li>
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                Add Image, QR & BAR Code
                            </li>
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                ZATCA-compliant (for Saudi Arabia)
                            </li>
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                GST Invoice (for India)
                            </li>
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                Compatible with a Variety of Plugins
                            </li>
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                Dedicated Customer Support and Live Chat
                            </li>
                            <li>
                                <span>
                                    <svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 20 20" aria-hidden="true" class="ctx-flex-shrink-0 ctx-w-4 ctx-h-4 ctx-text-blue-600 dark:text-blue-500" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                                30-Day Money Back Guarantee
                            </li>

                        </ul>

                    </div>
                    <button class="woo-invoice-banner-btn" onclick=" window.open('https://webappick.com/plugin/woocommerce-pdf-invoice-packing-slips', '_blank'); return false;">
                        Get Challan pro Now
                        <svg width="20" height="20" fill="#fff" viewBox="0 0 24 24" version="1.2" baseProfile="tiny" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10.396 18.433 17 12l-6.604-6.433A2 2 0 0 0 7 7v10a2 2 0 0 0 3.396 1.433z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div><!-- end .woo-invoice-col-sm-4 -->
    </div>
</li>
<!--END SYSTEM STATUS TAB-->