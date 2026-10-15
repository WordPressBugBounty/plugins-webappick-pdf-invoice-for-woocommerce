<?php
/**
 * Fired during plugin activation
 *
 * @link  https://webappick.com
 * @since 1.0.0
 *
 * @package    Woo_Invoice
 * @subpackage Woo_Invoice/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Woo_Invoice
 * @subpackage Woo_Invoice/includes
 * @author     Md Ohidul Islam <wahid@webappick.com>
 */
class Woo_Invoice_Activator
{
    /**
     * Short Description. (use period)
     *
     * Long Description.
     *
     * @since 1.0.0
     */
    public static function activate() {
        // Enable PDF Invoicing.
        if ( ! get_option('wpifw_invoicing') ) {
            update_option('wpifw_invoicing', '1');
        }

        if ( ! get_option('wpifw_order_email') ) {
            update_option('wpifw_order_email', '1');
        }

        if ( ! get_option('wpifw_email_attach_check_list') ) {
            update_option('wpifw_email_attach_check_list', array( 'new_order', 'customer_completed_order', 'customer_processing_order', 'customer_refunded_order', 'customer_invoice' ));
        }

        if ( ! get_option('wpifw_download') ) {
            update_option('wpifw_download', '1');
        }

        if ( ! get_option('wpifw_invoice_download_check_list') ) {
            update_option('wpifw_invoice_download_check_list', array( 'processing', 'completed', 'payment_complete', 'always_allow' ));
        }

        if ( ! get_option('wpifw_disid') ) {
            update_option('wpifw_disid', 'SKU');
        }

        if ( ! get_option('wpifw_invoice_no') ) {
            update_option('wpifw_invoice_no', '1');
        }

        if ( ! get_option('wpifw_templateid') ) {
            update_option('wpifw_templateid', 'invoice-1');
        }

        if ( ! get_option('wpifw_currency_code') ) {
            update_option('wpifw_currency_code', '0');
        }

        if ( ! get_option('wpifw_payment_method_show') ) {
            update_option('wpifw_payment_method_show', '1');
        }

        if ( ! get_option('wpifw_display_phone') ) {
            update_option('wpifw_display_phone', '0');
        }

        if ( ! get_option('wpifw_display_email') ) {
            update_option('wpifw_display_email', '0');
        }

        if ( ! get_option('wpifw_cdetails') ) {
            if ( class_exists('WooCommerce') ) {
                $store          = new WC_Countries();
                $address        = $store->get_base_address();
                $address_2      = $store->get_base_address_2();
                $country        = $store->get_base_country();
                $city           = $store->get_base_city();
                $postcode       = $store->get_base_postcode();
                $store_location = '';
                if ( ! empty($address) ) {
                    $store_location .= $address . "\n";
                }
                if ( ! empty($address_2) ) {
                    $store_location .= $address_2 . "\n";
                }
                if ( ! empty($city) ) {
                    $store_location .= $city;
                }
                if ( ! empty($city) && ! empty($postcode) ) {
                    $store_location .= '-' . $postcode . "\n";
                } else {
                    $store_location .= "\n";
                }
                if ( ! empty($country) ) {
                    $store_location .= $country;
                }
                update_option('wpifw_cdetails', $store_location);
            }
        }

        if ( ! get_option('wpifw_cname') ) {
            update_option('wpifw_cname', get_bloginfo('name'));
        }

        if ( ! get_option('wpifw_logo_attachment_id') ) {
            if ( has_custom_logo() ) {
                $custom_logo_id  = get_theme_mod('custom_logo');
                $custom_logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
                update_option('wpifw_logo_attachment_id', $custom_logo_url);
            }
        }

	    $wpifw_delivery_address_buyer = "{{billing_first_name}}\t{{billing_last_name}}\n"
			. "{{billing_company}}\t{{billing_address_1}}\n"
			. "{{billing_address_2}}\t{{billing_city}}\n"
			. "{{billing_state}}\t{{billing_postcode}}\n"
			. "{{billing_country}}\t{{billing_phone}}\n"
			. "{{billing_email}}\t\n"
			. "{{shipping_first_name}}\t{{shipping_last_name}}\n"
			. "{{shipping_company}}\t{{shipping_address_1}}\n"
			. "{{shipping_address_2}}\t{{shipping_city}}\n"
			. "{{shipping_state}}\t{{shipping_postcode}}\n"
			. "{{shipping_country}}\t{{shipping_phone}}\n"
			. "{{shipping_email}}";

	    if ( ! get_option('wpifw_delivery_address_buyer') ) {
		    update_option('wpifw_delivery_address_buyer', $wpifw_delivery_address_buyer);
	    }

        $customer_info = "{{billing_first_name}} {{billing_last_name}}\n"
            . "{{billing_company}}\n"
            . "{{billing_address_1}}\n"
            . "{{billing_address_2}}\n"
            . "{{billing_city}} - {{billing_postcode}}\n"
            . "{{billing_state}}\n"
            . "{{billing_country}}\n"
            . "P: {{billing_phone}}\n"
            . "E: {{billing_email}}";

        if ( ! get_option('wpifw_buyer') ) {
            update_option('wpifw_buyer', $customer_info);
        }

        update_option('woo-invoice-activation-time', time());

    }

}
