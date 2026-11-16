<?php
/**
 * Sidebar promo card (Black Friday & Cyber Monday deal), shared by every settings tab.
 *
 * @package    Woo_Invoice
 * @subpackage Woo_Invoice/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$woo_invoice_banner_url   = 'https://webappick.com/plugin/woocommerce-pdf-invoice-packing-slips/?utm_source=customer_site&utm_medium=sidebar_banner&utm_campaign=black_friday_2026&utm_content=challan_free';
$woo_invoice_banner_image = plugin_dir_url( __FILE__ ) . '../images/black-friday-2026.webp';
?>
<div class="woo-invoice-card">
	<a class="woo-invoice-bf-banner" href="<?php echo esc_url( $woo_invoice_banner_url ); ?>" target="_blank" rel="noopener noreferrer">
		<img src="<?php echo esc_url( $woo_invoice_banner_image ); ?>" width="1481" height="2000" alt="<?php esc_attr_e( 'Challan Pro Black Friday & Cyber Monday deal: up to 60% off, 15th Nov - 7th Dec 2026. Try Challan Pro now.', 'webappick-pdf-invoice-for-woocommerce' ); ?>">
	</a>
</div>
