<?php
/**
 * Sidebar promo card (Halloween deal), shared by every settings tab.
 *
 * @package    Woo_Invoice
 * @subpackage Woo_Invoice/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$woo_invoice_banner_url  = 'https://webappick.com/plugin/woocommerce-pdf-invoice-packing-slips/?utm_source=customer_site&utm_medium=sidebar_banner&utm_campaign=halloween_2026&utm_content=challan_free';
$woo_invoice_banner_logo = plugin_dir_url( __FILE__ ) . '../images/challan-pro.svg';
$woo_invoice_banner_code = 'HW2026';
?>
<div class="woo-invoice-card">
	<div class="woo-invoice-banner-card woo-invoice-hw">
		<span class="woo-invoice-hw-moon" aria-hidden="true"></span>
		<svg class="woo-invoice-hw-bat woo-invoice-hw-bat-1" viewBox="0 0 50 18" aria-hidden="true"><path d="M0 10C6 4 12 4 16 8C18 2 22 0 24 6L25 3L26 6C28 0 32 2 34 8C38 4 44 4 50 10C44 9 40 11 38 15C34 11 30 12 28 16L25 14L22 16C20 12 16 11 12 15C10 11 6 9 0 10Z"/></svg>
		<svg class="woo-invoice-hw-bat woo-invoice-hw-bat-2" viewBox="0 0 50 18" aria-hidden="true"><path d="M0 10C6 4 12 4 16 8C18 2 22 0 24 6L25 3L26 6C28 0 32 2 34 8C38 4 44 4 50 10C44 9 40 11 38 15C34 11 30 12 28 16L25 14L22 16C20 12 16 11 12 15C10 11 6 9 0 10Z"/></svg>

		<div class="woo-invoice-hw-content">
			<a class="woo-invoice-hw-logo" href="<?php echo esc_url( $woo_invoice_banner_url ); ?>" target="_blank" rel="noopener noreferrer">
				<img src="<?php echo esc_url( $woo_invoice_banner_logo ); ?>" alt="<?php esc_attr_e( 'Challan Pro', 'webappick-pdf-invoice-for-woocommerce' ); ?>">
			</a>
			<div class="woo-invoice-hw-title"><?php esc_html_e( 'Halloween Deal', 'webappick-pdf-invoice-for-woocommerce' ); ?></div>
			<div class="woo-invoice-hw-discount"><?php esc_html_e( '30% OFF', 'webappick-pdf-invoice-for-woocommerce' ); ?></div>
			<div class="woo-invoice-hw-plans"><?php esc_html_e( 'All Pro Plans', 'webappick-pdf-invoice-for-woocommerce' ); ?></div>
			<div class="woo-invoice-hw-coupon-label"><?php esc_html_e( 'Use This Coupon Code', 'webappick-pdf-invoice-for-woocommerce' ); ?></div>
			<button type="button" class="woo-invoice-hw-coupon" data-code="<?php echo esc_attr( $woo_invoice_banner_code ); ?>" data-copied="<?php esc_attr_e( 'Copied!', 'webappick-pdf-invoice-for-woocommerce' ); ?>" title="<?php esc_attr_e( 'Click to copy', 'webappick-pdf-invoice-for-woocommerce' ); ?>" onclick="var b=this;if(navigator.clipboard){navigator.clipboard.writeText(b.dataset.code);}b.classList.add('is-copied');setTimeout(function(){b.classList.remove('is-copied');},1500);"><?php echo esc_html( $woo_invoice_banner_code ); ?></button>
			<a class="woo-invoice-hw-cta" href="<?php echo esc_url( $woo_invoice_banner_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Challan Pro Now', 'webappick-pdf-invoice-for-woocommerce' ); ?></a>
		</div>

		<svg class="woo-invoice-hw-pumpkin" viewBox="0 0 120 110" aria-hidden="true">
			<path d="M58 22c-1-9 2-16 9-20l5 4c-5 3-7 9-6 16z" fill="#5fae3f"/>
			<path d="M70 10c6-5 14-4 16 2-4-2-9-1-12 3" fill="none" stroke="#7cc24f" stroke-width="2.5" stroke-linecap="round"/>
			<ellipse cx="38" cy="66" rx="30" ry="38" fill="#e8710f"/>
			<ellipse cx="82" cy="66" rx="30" ry="38" fill="#e8710f"/>
			<ellipse cx="60" cy="64" rx="30" ry="42" fill="#f7931e"/>
			<path d="M33 52l15 8-15 6c-3-5-3-10 0-14zM87 52l-15 8 15 6c3-5 3-10 0-14z" fill="#ffd54a"/>
			<path d="M57 66l3-5 3 5z" fill="#ffd54a"/>
			<path d="M30 76c10 14 50 14 60 0-4 12-15 20-30 20S34 88 30 76zm12 4 4 6 4-5 5 7 5-7 5 7 5-7 4 5 4-6" fill="#ffd54a"/>
		</svg>
	</div>
</div>
