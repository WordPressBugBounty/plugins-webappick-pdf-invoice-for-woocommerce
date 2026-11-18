<?php
/**
 * The complete settings field inventory.
 *
 * COMPATIBILITY CONTRACT (see CLAUDE.md): every key below is the EXACT
 * wp_options row name used by v1 (3.7.x). Keys are never renamed, and value
 * formats match what v1 wrote: toggles store '1' / '0' (legacy hidden-input
 * pattern), scalars store strings, list settings store arrays.
 *
 * @package Challan
 */

namespace Challan\Settings;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Static provider of the built-in (free) field definitions.
 *
 * Shape per field:
 *   'type'    => bool|string|int|textarea|css|array
 *   'default' => value returned when the option was never saved
 *   'section' => UI section id (general|invoice|packing_slip|delivery_address|seller|buyer|labels|meta_fields)
 *   'pro'     => (optional) true when the field only saves while pro is active
 */
final class Definitions {

	/**
	 * All built-in fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields() {
		return array(
			// ── General ────────────────────────────────────────────────
			'wpifw_invoicing'                            => array(
				'type'    => 'bool',
				'default' => '1',
				'section' => 'general',
			),
			'wpifw_order_email'                          => array(
				'type'    => 'bool',
				'default' => '1',
				'section' => 'general',
			),
			'wpifw_download'                             => array(
				'type'    => 'bool',
				'default' => '1',
				'section' => 'general',
			),
			'wpifw_output_type_html'                     => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'general',
			),
			// v1 insights opt-in (Send Debug Info). Stored as a bool AND
			// pushed to the AppServices client on change (v1 behavior).
			'wpifw_data_collection'                      => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'general',
			),
			// NEW v2 option: v1 shipped this feature commented out
			// (add_invoice_download_link) — a Download Invoice button in
			// customer emails, authorized by the order key.
			'challan_email_download_button'              => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'general',
			),
			'wpifw_currency_code'                        => array(
				'type'    => 'bool',
				'default' => '0',
				'section' => 'general',
			),
			'wpifw_payment_method_show'                  => array(
				'type'    => 'bool',
				'default' => '1',
				'section' => 'general',
			),
			'wpifw_show_order_note'                      => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'general',
			),
			'wpifw_invoice_no'                           => array(
				'type'    => 'int',
				'default' => 1,
				'section' => 'general',
			),
			'wpifw_invoice_no_prefix'                    => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'general',
			),
			'wpifw_invoice_no_suffix'                    => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'general',
			),
			// v1-pro option, registered free so the default applies
			// everywhere; the type select is pro-gated in the UI.
			// Default and empty both mean order number (user decision
			// 2026-08-20).
			'wpifw_invoice_number_type'                  => array(
				'type'    => 'string',
				'choices' => array( 'order_number', 'pre_order_number_suf', 'pre_custom_number_suf' ),
				'default' => 'order_number',
				'section' => 'general',
				'pro'     => true,
			),
			'wpifw_templateid'                           => array(
				'type'    => 'string',
				'default' => 'challan-simple',
				'section' => 'general',
			),
			'wpifw_date_format'                          => array(
				'type'    => 'string',
				'default' => 'd/m/Y',
				'section' => 'general',
			),
			'wpifw_pdf_invoice_button_behaviour'         => array(
				'type'    => 'string',
				'choices' => array( 'new_tab', 'download' ),
				'default' => 'new_tab',
				'section' => 'general',
			),
			'wpifw_pdf_invoice_debug_mode'               => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'general',
			),
			'wpifw_disid'                                => array(
				'type'    => 'string',
				'default' => 'SKU',
				'section' => 'general',
			),
			'wpifw_invoice_download_check_list'          => array(
				'type'    => 'array',
				'default' => array( 'processing', 'completed', 'payment_complete', 'always_allow' ),
				'section' => 'general',
			),
			'wpifw_email_attach_check_list'              => array(
				'type'    => 'array',
				'default' => array( 'new_order', 'customer_completed_order', 'customer_processing_order', 'customer_refunded_order', 'customer_invoice' ),
				'section' => 'general',
			),
			'wpifw_bulk_type'                            => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'general',
			),
			'wpifw_rtl'                                  => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'general',
			),
			'wpifw_pdf_document_language'                => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'general',
			),
			'remove_invalid_characters_from_title'       => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'general',
			),

			// ── Seller ─────────────────────────────────────────────────
			'wpifw_cname'                                => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_cdetails'                             => array(
				'type'    => 'textarea',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_block_title_from'                     => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_logo_attachment_id'                   => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_logo_attachment_image_id'             => array(
				'type'    => 'int',
				'default' => 0,
				'section' => 'seller',
			),
			'wpifw_logo_selector_attachment_id'          => array(
				'type'    => 'int',
				'default' => 0,
				'section' => 'seller',
			),
			'wpifw_logo_width'                           => array(
				'type'    => 'string',
				'default' => '20%',
				'section' => 'seller',
			),
			'wpifw_terms_and_condition'                  => array(
				'type'    => 'textarea',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_other_information'                    => array(
				'type'    => 'textarea',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_display_phone'                        => array(
				'type'    => 'bool',
				'default' => '0',
				'section' => 'seller',
			),
			'wpifw_display_email'                        => array(
				'type'    => 'bool',
				'default' => '0',
				'section' => 'seller',
			),
			'wpifw_display_bank_account'                 => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_display_ssn'                          => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_display_vat_id'                       => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_SSN'                                  => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'seller',
			),
			'wpifw_VAT_ID'                               => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'seller',
			),

			// ── Buyer ──────────────────────────────────────────────────
			'wpifw_buyer'                                => array(
				'type'    => 'textarea',
				'default' => '',
				'section' => 'buyer',
			),
			'wpifw_buyer_shipping_address'               => array(
				'type'    => 'textarea',
				'default' => '',
				'section' => 'buyer',
			),
			'wpifw_display_shipping_address'             => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'buyer',
			),
			'wpifw_hide_for_same_address'                => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'buyer',
			),

			// ── Labels (PDF strings) ───────────────────────────────────
			'wpifw_INVOICE_TITLE'                        => array(
				'type'    => 'string',
				'default' => 'Invoice',
				'section' => 'labels',
			),
			'wpifw_payment_method_text'                  => array(
				'type'    => 'string',
				'default' => 'Payment Method',
				'section' => 'labels',
			),
			'wpifw_INVOICE_NUMBER_TEXT'                  => array(
				'type'    => 'string',
				'default' => 'Invoice Number',
				'section' => 'labels',
			),
			'wpifw_INVOICE_DATE_TEXT'                    => array(
				'type'    => 'string',
				'default' => 'Invoice Date',
				'section' => 'labels',
			),
			'wpifw_ORDER_NUMBER_TEXT'                    => array(
				'type'    => 'string',
				'default' => 'Order Number',
				'section' => 'labels',
			),
			'wpifw_ORDER_DATE_TEXT'                      => array(
				'type'    => 'string',
				'default' => 'Order Date',
				'section' => 'labels',
			),
			'wpifw_SL'                                   => array(
				'type'    => 'string',
				'default' => 'SL',
				'section' => 'labels',
			),
			'wpifw_PRODUCT'                              => array(
				'type'    => 'string',
				'default' => 'Product',
				'section' => 'labels',
			),
			'wpifw_PRICE'                                => array(
				'type'    => 'string',
				'default' => 'Price',
				'section' => 'labels',
			),
			'wpifw_QUANTITY'                             => array(
				'type'    => 'string',
				'default' => 'Quantity',
				'section' => 'labels',
			),
			'wpifw_ROW_TOTAL'                            => array(
				'type'    => 'string',
				'default' => 'Total',
				'section' => 'labels',
			),
			'wpifw_SUBTOTAL_TEXT'                        => array(
				'type'    => 'string',
				'default' => 'Sub Total',
				'section' => 'labels',
			),
			'wpifw_TAX_TEXT'                             => array(
				'type'    => 'string',
				'default' => 'Tax',
				'section' => 'labels',
			),
			'wpifw_DISCOUNT_TEXT'                        => array(
				'type'    => 'string',
				'default' => 'Discount',
				'section' => 'labels',
			),
			'wpifw_REFUNDED_TEXT'                        => array(
				'type'    => 'string',
				'default' => 'Refunded',
				'section' => 'labels',
			),
			'wpifw_SHIPPING_TEXT'                        => array(
				'type'    => 'string',
				'default' => 'Shipping',
				'section' => 'labels',
			),
			'wpifw_GRAND_TOTAL_TEXT'                     => array(
				'type'    => 'string',
				'default' => 'Grand Total',
				'section' => 'labels',
			),
			'wpifw_DOWNLOAD_INVOICE_TEXT'                => array(
				'type'    => 'string',
				'default' => 'Download Invoice',
				'section' => 'labels',
			),
			'wpifw_PACKING_SLIP_TEXT'                    => array(
				'type'    => 'string',
				'default' => 'Packing Slip',
				'section' => 'labels',
			),
			'wpifw_PACKING_SLIP_ORDER_NUMBER_TEXT'       => array(
				'type'    => 'string',
				'default' => 'Order Number',
				'section' => 'labels',
			),
			'wpifw_PACKING_SLIP_ORDER_DATE_TEXT'         => array(
				'type'    => 'string',
				'default' => 'Order Date',
				'section' => 'labels',
			),
			'wpifw_PACKING_SLIP_ORDER_METHOD_TEXT'       => array(
				'type'    => 'string',
				'default' => 'Shipping method',
				'section' => 'labels',
			),
			'wpifw_PACKING_SLIP_PRODUCT_TEXT'            => array(
				'type'    => 'string',
				'default' => 'Product',
				'section' => 'labels',
			),
			'wpifw_PACKING_SLIP_WEIGHT_TEXT'             => array(
				'type'    => 'string',
				'default' => 'Weight',
				'section' => 'labels',
			),
			'wpifw_PACKING_SLIP_QUANTITY_TEXT'           => array(
				'type'    => 'string',
				'default' => 'Quantity',
				'section' => 'labels',
			),

			// ── Invoice display ────────────────────────────────────────
			'wpifw_invoice_paper_size'                   => array(
				'type'    => 'string',
				'default' => 'A4',
				'section' => 'invoice',
			),
			'wpifw_invoice_custom_paper_height'          => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_invoice_custom_paper_wide'            => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_pdf_font_family'                      => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_invoice_font_size'                    => array(
				'type'    => 'string',
				'default' => '11',
				'section' => 'invoice',
			),
			'wpifw_invoice_footer_font_size'             => array(
				'type'    => 'string',
				'default' => '9',
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_invoice_product_per_page'             => array(
				'type'    => 'string',
				'default' => '6',
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_invoice_product_title_length'         => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
			),
			'wpifw_invoice_description_limit'            => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_product_image_show'                   => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'invoice',
			),
			// tax-compliance Phase 1 (PRD 2026-08-20): split the Tax
			// totals row into one row per rate, labels carrying the
			// percentage. New v2 option — challan_ prefix, free.
			'challan_split_tax_totals'                   => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'invoice',
			),
			// v1 stored '' when never saved and rendered shipping WITHOUT
			// tax for any value other than the with_tax constant — this
			// default matches that behavior while giving the select a
			// real selection.
			'wpifw_invoice_display_shipping_total'       => array(
				'type'    => 'string',
				'choices' => array( 'wpifw_invoice_display_shipping_total_without_tax', 'wpifw_invoice_display_shipping_total_with_tax' ),
				'default' => 'wpifw_invoice_display_shipping_total_without_tax',
				'section' => 'invoice',
			),
			'wpifw_custom_css'                           => array(
				'type'    => 'css',
				'default' => '',
				'section' => 'invoice',
			),
			'wpifw_select_product_column'                => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'invoice',
			),
			// Header segment order (Seller Info card): logo, QR/barcode,
			// company address — v1's fixed layout order as the default.
			// New v2 option; segment ids match the v1
			// woo_invoice_header_position_customization filter values.
			'challan_header_sort'                        => array(
				'type'    => 'array',
				'default' => array( 'logo', 'bar_qr_code', 'seller' ),
				'section' => 'invoice',
			),
			// Address/order-data row order (Order Info card) — v1 layout
			// as the default. New v2 option.
			'challan_address_sort'                       => array(
				'type'    => 'array',
				'default' => array( 'billing', 'shipping', 'order_data' ),
				'section' => 'invoice',
			),
			'wpifw_enable_invoice_background'            => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_invoice_background_attachment_id'     => array(
				'type'    => 'int',
				'default' => 0,
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_invoice_background_opacity'           => array(
				'type'    => 'string',
				'default' => '0.3', // v1's opacity when never saved.
				'section' => 'invoice',
				'pro'     => true,
			),
			'wpifw_product_post_meta_show'               => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
			),
			'wpifw_order_item_meta_show'                 => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
			),
			'wpifw_custom_order_meta'                    => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'invoice',
			),
			'wpifw_header_sort_index'                    => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
			),
			'address_sort_index'                         => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
			),
			'product_header_sort_index'                  => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
			),
			'order_total_sort_index'                     => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'invoice',
			),

			// ── Packing slip ───────────────────────────────────────────
			'wpifw_pickingslip_paper_size'               => array(
				'type'    => 'string',
				'default' => 'A4',
				'section' => 'packing_slip',
			),
			'wpifw_pickingslip_custom_paper_height'      => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'packing_slip',
				'pro'     => true,
			),
			'wpifw_pickingslip_custom_paper_wide'        => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'packing_slip',
				'pro'     => true,
			),
			'wpifw_packingslip_disid'                    => array(
				'type'    => 'string',
				'default' => 'SKU',
				'section' => 'packing_slip',
			),
			'wpifw_packingslip_product_title_length'     => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'packing_slip',
			),
			'wpifw_packingslip_description_limit'        => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'packing_slip',
				'pro'     => true,
			),
			'wpifw_packingslip_product_per_page'         => array(
				'type'    => 'string',
				'default' => '6',
				'section' => 'packing_slip',
				'pro'     => true,
			),
			'wpifw_enable_packingslip_background'        => array(
				'type'    => 'bool',
				'default' => '',
				'section' => 'packing_slip',
				'pro'     => true,
			),
			'wpifw_packingslip_background_attachment_id' => array(
				'type'    => 'int',
				'default' => 0,
				'section' => 'packing_slip',
				'pro'     => true,
			),
			'wpifw_packingslip_background_opacity'       => array(
				'type'    => 'string',
				'default' => '1',
				'section' => 'packing_slip',
				'pro'     => true,
			),
			'wpifw_ps_header_sort_index'                 => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'packing_slip',
			),
			'wpifw_ps_address_sort_index'                => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'packing_slip',
			),
			'wpifw_ps_product_header_sort_index'         => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'packing_slip',
			),

			// ── Delivery address ───────────────────────────────────────
			'wpifw_delivery_address_buyer'               => array(
				'type'    => 'textarea',
				'default' => '',
				'section' => 'delivery_address',
			),
			'wpifw_delivery_address_block_title_to'      => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'delivery_address',
			),
			'wpifw_delivery_address_num_col'             => array(
				'type'    => 'int',
				'default' => 3,
				'section' => 'delivery_address',
			),
			'wpifw_delivery_address_num_row'             => array(
				'type'    => 'int',
				'default' => 7,
				'section' => 'delivery_address',
			),
			'wpifw_delivery_address_font_size'           => array(
				'type'    => 'string',
				'default' => '11',
				'section' => 'delivery_address',
			),
			'wpifw_delivery_address_paper'               => array(
				'type'    => 'string',
				'default' => 'A4',
				'section' => 'delivery_address',
			),

			// ── Custom meta fields ─────────────────────────────────────
			'_winvoice_order_meta_label'                 => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'meta_fields',
			),
			'_winvoice_order_meta_name'                  => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'meta_fields',
			),
			'_winvoice_order_meta_name_position'         => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'meta_fields',
			),
			'_winvoice_order_meta_position'              => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'meta_fields',
			),
			'_winvoice_order_meta_label_ps'              => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'meta_fields',
			),
			'_winvoice_order_meta_name_ps'               => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'meta_fields',
			),
			'_winvoice_order_meta_position_ps'           => array(
				'type'    => 'array',
				'default' => array(),
				'section' => 'meta_fields',
			),
			'_winvoice_order_item_meta_label'            => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'meta_fields',
			),
			'_winvoice_order_item_meta_name'             => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'meta_fields',
			),
			'_winvoice_post_meta_label'                  => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'meta_fields',
			),
			'_winvoice_post_meta_name'                   => array(
				'type'    => 'string',
				'default' => '',
				'section' => 'meta_fields',
			),
		);
	}

	/**
	 * Section ids in display order.
	 *
	 * @return string[]
	 */
	public static function sections() {
		return array( 'general', 'invoice', 'packing_slip', 'delivery_address', 'seller', 'buyer', 'labels', 'meta_fields' );
	}
}
