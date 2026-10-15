<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * Filter to change invoice strings.
 */
if ( ! function_exists('woo_invoice_free_filter_label') ) {
    function woo_invoice_free_filter_label( $label, $order, $template ) {
        woo_invoice_free_reload_text_domain();
        return apply_filters(
            'woo_invoice_filter_template_label',
            __($label, 'webappick-pdf-invoice-for-woocommerce'), //phpcs:ignore
            $order,
            $template
        );
    }
}

/**
 * Function to switch language according to order language.
 * @param $language_code
 * @param bool $cookie_lang
 */
function woo_invoice_free_switch_language_callback( $language_code, $cookie_lang = true ) {
    if ( ! empty($language_code) ) {
        if ( class_exists('SitePress', false) ) {
            // WPML Switch Language.
            global $sitepress;
            if ( $sitepress->get_current_language() !== $language_code ) {
                $sitepress->switch_lang($language_code, $cookie_lang);
            }
        }
        // when polylang plugin is activated
        if ( defined('POLYLANG_BASENAME') || function_exists('PLL') ) {
            if ( pll_current_language() !== $language_code ) {
                PLL()->curlang = PLL()->model->get_language($language_code);
            }
        }
    }
}

/**
 * Function to restore language.
 */
function woo_invoice_free_restore_language_callback() {
    $language_code = '';
    if ( class_exists('SitePress', false) ) {
        // WPML restore Language.
        global $sitepress;
        $language_code = $sitepress->get_default_language();
    }

    // when polylang plugin is activated
    if ( defined('POLYLANG_BASENAME') || function_exists('PLL') ) {
        $language_code = pll_default_language();
    }
    /**
     * Filter to hijack Default Language code before restore
     *
     * @param string $language_code
     */

    if ( ! empty($language_code) ) {
        woo_invoice_free_switch_language_callback($language_code);
    }
}

/**
 * Reload text domain after switch language.
 */
function woo_invoice_free_reload_text_domain() {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Loads the bundled /languages translations again after switching to the order's language.
    load_plugin_textdomain(
        'webappick-pdf-invoice-for-woocommerce',
        false,
        dirname(CHALLAN_FREE_PLUGIN_BASE_NAME) . '/languages/'
    );

}

if ( ! function_exists('challan_get_cached_meta_keys') ) {
    /**
     * Return a meta key list from a transient and run the query only when the transient is empty.
     *
     * The lists are cleared by challan_flush_meta_keys_cache() when the Settings tab is saved,
     * so newly added meta keys show up right after a save.
     *
     * @param string   $cache_key Transient name.
     * @param callable $query     Returns the meta key rows.
     * @return array
     */
    function challan_get_cached_meta_keys( $cache_key, $query ) {
        $meta_keys = get_transient( $cache_key );
        if ( false === $meta_keys ) {
            $meta_keys = call_user_func( $query );
            $meta_keys = is_array( $meta_keys ) ? $meta_keys : array();
            set_transient( $cache_key, $meta_keys, 12 * HOUR_IN_SECONDS );
        }

        return $meta_keys;
    }
}

if ( ! function_exists('challan_flush_meta_keys_cache') ) {
    /**
     * Delete the cached order, product and order item meta key lists.
     *
     * @return void
     */
    function challan_flush_meta_keys_cache() {
        delete_transient( 'challan_order_meta_keys_hpos' );
        delete_transient( 'challan_order_meta_keys_cpt' );
        delete_transient( 'challan_product_meta_keys' );
        delete_transient( 'challan_item_meta_keys' );
    }
}

if ( ! function_exists('challan_order_meta_query') ) {
    /**
     * @return array
     */
    function challan_order_meta_query(){
        $hpos_enabled = woo_invoice_hpos_enabled();
        $cache_key    = $hpos_enabled ? 'challan_order_meta_keys_hpos' : 'challan_order_meta_keys_cpt';

        return challan_get_cached_meta_keys( $cache_key, function () use ( $hpos_enabled ) {
            global $wpdb;
            // Check if HPOS (High-Performance Order Storage) is enabled
            if ( $hpos_enabled ) {
                // HPOS is enabled, query the wc_orders_meta table
                // Note: Table name uses $wpdb->prefix which is a trusted WordPress value
                $table_name = esc_sql( $wpdb->prefix . 'wc_orders_meta' );
                return $wpdb->get_results( "SELECT DISTINCT meta_key FROM {$table_name}" ); // phpcs:ignore
            }

            // Traditional CPT-based orders are in use, query the postmeta table
            // Note: $wpdb->postmeta and $wpdb->posts are trusted WordPress table name constants
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API lists distinct meta keys; cached by challan_get_cached_meta_keys().
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.meta_key FROM {$wpdb->postmeta} AS pm LEFT JOIN {$wpdb->posts} AS p ON pm.post_id = p.id WHERE p.post_type = %s GROUP BY pm.meta_key",
                'shop_order'
            ) ); // phpcs:ignore
        } );
    }
}

if ( ! function_exists('challan_product_meta_query') ) {
    /**
     * @return array
     */
    function challan_product_meta_query(){
        return challan_get_cached_meta_keys( 'challan_product_meta_keys', function () {
            global $wpdb;

            // Note: $wpdb->postmeta and $wpdb->posts are trusted WordPress table name constants
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API lists distinct meta keys; cached by challan_get_cached_meta_keys().
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.meta_key, pm.meta_id FROM {$wpdb->postmeta} AS pm LEFT JOIN {$wpdb->posts} AS p ON pm.post_id = p.id WHERE p.post_type = %s GROUP BY pm.meta_key",
                'product'
            ) ); // phpcs:ignore
        } );
    }
}

if ( ! function_exists('challan_item_meta_query') ) {
    /**
     * @return array
     */
    function challan_item_meta_query(){
        return challan_get_cached_meta_keys( 'challan_item_meta_keys', function () {
            global $wpdb;

            if ( ! class_exists( 'WooCommerce' ) ) {
                return array();
            }

            // Note: $wpdb->order_itemmeta is a trusted WooCommerce table name constant
            return $wpdb->get_results( "SELECT DISTINCT meta_key FROM {$wpdb->order_itemmeta}" ); // phpcs:ignore
        } );
    }
}

if ( ! function_exists('challan_order_meta_data_position') ) {
    /**
     * @return string[].
     */
    function challan_order_meta_data_position(){
        $order_meta_position_arr = array(
            'after_order_data'        => 'After order data',
            'before_order_data'       => 'Before order data',
            'before_billing_address'  => 'Before billing address',
            'after_billing_address'   => 'After billing address',
            'before_shipping_address' => 'Before shipping address',
            'after_shipping_address'  => 'After shipping address',
        );

        return $order_meta_position_arr;
    }
}


function woo_invoice_get_default_languages(){
    return array(
        'af'             => 'Afrikaans',
        'ar'             => 'العربية',
        'ary'            => 'العربية المغربية',
        'as'             => 'অসমীয়া',
        'azb'            => 'گؤنئی آذربایجان',
        'az'             => 'Azərbaycan dili',
        'bel'            => 'Беларуская мова',
        'bg_BG'          => 'Български',
        'bn_BD'          => 'বাংলা',
        'bo'             => 'བོད་ཡིག',
        'bs_BA'          => 'Bosanski',
        'ca'             => 'Català',
        'ceb'            => 'Cebuano',
        'cs_CZ'          => 'Čeština',
        'cy'             => 'Cymraeg',
        'da_DK'          => 'Dansk',
        'de_DE_formal'   => 'Deutsch (Sie)',
        'de_DE'          => 'Deutsch',
        'de_CH_informal' => 'Deutsch (Schweiz, Du)',
        'de_CH'          => 'Deutsch (Schweiz)',
        'de_AT'          => 'Deutsch (Österreich)',
        'dsb'            => 'Dolnoserbšćina',
        'dzo'            => 'རྫོང་ཁ',
        'el'             => 'Ελληνικά',
        'en_CA'          => 'English (Canada)',
        'en_NZ'          => 'English (New Zealand)',
        'en_ZA'          => 'English (South Africa)',
        'en_GB'          => 'English (UK)',
        'en_AU'          => 'English (Australia)',
        'eo'             => 'Esperanto',
        'es_DO'          => 'Español de República Dominicana',
        'es_CR'          => 'Español de Costa Rica',
        'es_VE'          => 'Español de Venezuela',
        'es_CO'          => 'Español de Colombia',
        'es_CL'          => 'Español de Chile',
        'es_UY'          => 'Español de Uruguay',
        'es_PR'          => 'Español de Puerto Rico',
        'es_ES'          => 'Español',
        'es_GT'          => 'Español de Guatemala',
        'es_PE'          => 'Español de Perú',
        'es_MX'          => 'Español de México',
        'es_EC'          => 'Español de Ecuador',
        'es_AR'          => 'Español de Argentina',
        'et'             => 'Eesti',
        'eu'             => 'Euskara',
        'fa_AF'          => '(فارسی (افغانستان',
        'fa_IR'          => 'فارسی',
        'fi'             => 'Suomi',
        'fr_FR'          => 'Français',
        'fr_CA'          => 'Français du Canada',
        'fr_BE'          => 'Français de Belgique',
        'fur'            => 'Friulian',
        'gd'             => 'Gàidhlig',
        'gl_ES'          => 'Galego',
        'gu'             => 'ગુજરાતી',
        'haz'            => 'هزاره گی',
        'he_IL'          => 'עִבְרִית',
        'hi_IN'          => 'हिन्दी',
        'hr'             => 'Hrvatski',
        'hsb'            => 'Hornjoserbšćina',
        'hu_HU'          => 'Magyar',
        'hy'             => 'Հայերեն',
        'id_ID'          => 'Bahasa Indonesia',
        'is_IS'          => 'Íslenska',
        'it_IT'          => 'Italiano',
        'ja'             => '日本語',
        'jv_ID'          => 'Basa Jawa',
        'ka_GE'          => 'ქართული',
        'kab'            => 'Taqbaylit',
        'kk'             => 'Қазақ тілі',
        'km'             => 'ភាសាខ្មែរ',
        'kn'             => 'ಕನ್ನಡ',
        'ko_KR'          => '한국어',
        'ckb'            => 'كوردی‎',
        'lo'             => 'ພາສາລາວ',
        'lt_LT'          => 'Lietuvių kalba',
        'lv'             => 'Latviešu valoda',
        'mk_MK'          => 'Македонски јазик',
        'ml_IN'          => 'മലയാളം',
        'mn'             => 'Монгол',
        'mr'             => 'मराठी',
        'ms_MY'          => 'Bahasa Melayu',
        'my_MM'          => 'ဗမာစာ',
        'nb_NO'          => 'Norsk bokmål',
        'ne_NP'          => 'नेपाली',
        'nl_NL_formal'   => 'Nederlands (Formeel)',
        'nl_BE'          => 'Nederlands (België)',
        'nl_NL'          => 'Nederlands',
        'nn_NO'          => 'Norsk nynorsk',
        'oci'            => 'Occitan',
        'pa_IN'          => 'ਪੰਜਾਬੀ',
        'pl_PL'          => 'Polski',
        'ps'             => 'پښتو',
        'pt_PT'          => 'Português',
        'pt_PT_ao90'     => 'Português (AO90)',
        'pt_AO'          => 'Português de Angola',
        'pt_BR'          => 'Português do Brasil',
        'rhg'            => 'Ruáinga',
        'ro_RO'          => 'Română',
        'ru_RU'          => 'Русский',
        'sah'            => 'Сахалыы',
        'snd'            => 'سنڌي',
        'si_LK'          => 'සිංහල',
        'sk_SK'          => 'Slovenčina',
        'skr'            => 'سرائیکی',
        'sl_SI'          => 'Slovenščina',
        'sq'             => 'Shqip',
        'sr_RS'          => 'Српски језик',
        'sv_SE'          => 'Svenska',
        'sw'             => 'Kiswahili',
        'szl'            => 'Ślōnskŏ gŏdka',
        'ta_IN'          => 'தமிழ்',
        'ta_LK'          => 'தமிழ்',
        'te'             => 'తెలుగు',
        'th'             => 'ไทย',
        'tl'             => 'Tagalog',
        'tr_TR'          => 'Türkçe',
        'tt_RU'          => 'Татар теле',
        'tah'            => 'Reo Tahiti',
        'ug_CN'          => 'ئۇيغۇرچە',
        'uk'             => 'Українська',
        'ur'             => 'اردو',
        'uz_UZ'          => 'O‘zbekcha',
        'vi'             => 'Tiếng Việt',
        'zh_TW'          => '繁體中文',
        'zh_HK'          => '香港中文版	',
        'zh_CN'          => '简体中文',
    );
}
// Define rtl
function woo_invoice_is_rtl() {
    global $locale;
    if ( false !== strpos( $locale, 'ar' )
        || false !== strpos( $locale, 'he' )
        || false !== strpos( $locale, 'he_IL' )
        || false !== strpos( $locale, 'ur' )
    ) {
        $rtl = true;
    } else {
        $rtl = false;
    }

    return $rtl;
}

/**
 * Check if HPOS (High-Performance Order Storage) is enabled.
 *
 * @return bool
 */
function woo_invoice_hpos_enabled() {
    return class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Get WC_Order object from the given value.
 *
 * @since 3.6.27
 * @param int|WC_Order $order Order id or order object
 * @return WC_Order|bool Order object or false if invalid
 */
function woo_invoice_get_order($order) {
    // Check if the order is an integer
    if (is_int($order)) {
        $order = wc_get_order($order);
    }
    // Check if the order is a string that can be converted to a positive integer
    elseif (is_string($order) && 0 < absint($order)) {
        $order = wc_get_order(absint($order));
    }

    // If the order is already a WC_Order object, return it
    if ($order instanceof WC_Order) {
        return $order;
    }

    // Return false if the order is invalid
    return false;
}

/**
 * Get order meta data depending on whether HPOS is enabled.
 *
 * @param int|WC_Order $order Order id or order object
 * @param string $meta_key
 * @param bool $is_single
 * @return mixed
 */
function woo_invoice_get_meta($order, $meta_key, $is_single = true) {
    $order = woo_invoice_get_order($order);

    if (!$order) {
        return false;
    }

    if (woo_invoice_hpos_enabled()) {
        return $order->get_meta($meta_key, $is_single);
    }
    return get_post_meta($order->get_id(), $meta_key, $is_single);
}

/**
 * Update order meta data depending on whether HPOS is enabled.
 *
 * @param int|WC_Order $order Order id or order object
 * @param string $meta_key
 * @param mixed $meta_value
 */
function woo_invoice_update_meta($order, $meta_key, $meta_value) {
    $order = woo_invoice_get_order($order);

    if (!$order) {
        return false;
    }

    if (woo_invoice_hpos_enabled()) {
        $order->update_meta_data($meta_key, $meta_value);
        $order->save();
    } else {
        update_post_meta($order->get_id(), $meta_key, $meta_value);
    }
    return true;
}

/**
 * Stop the request with a compact notice.
 * Used inside the invoice preview frame, which otherwise keeps an A4-page height.
 *
 * @param string $message Notice text.
 */
function woo_invoice_exit_with_notice( $message ) {
    if ( ! headers_sent() ) {
        status_header( 404 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
    }

    $message = esc_html( $message );
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
html,body{margin:0;height:auto;background:#fff;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;}
.challan-order-notice{box-sizing:border-box;margin:16px;padding:12px 14px;background:#fcf9e8;border:1px solid #dba617;border-left-width:4px;color:#1d2327;font-size:13px;line-height:1.5;}
</style></head><body data-challan-notice="1"><p class="challan-order-notice">' . $message . '</p></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}