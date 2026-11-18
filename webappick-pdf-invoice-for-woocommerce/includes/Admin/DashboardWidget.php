<?php
/**
 * WebAppick blog dashboard widget.
 *
 * @package Challan
 */

namespace Challan\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * "Latest News from WebAppick Blog" (v1 widget) — v2 caches the remote
 * fetch in a transient and fails soft; v1 fetched on every dashboard
 * load and rendered nothing on failure.
 */
class DashboardWidget {

	const TRANSIENT = 'challan_dashboard_feed';
	const FEED_URL  = 'https://webappick.com/wp-json/wp/v2/posts?per_page=5&_fields=title,link';

	/**
	 * Hook in (v1 priority 1).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ), 1 );
	}

	/**
	 * Register the widget.
	 *
	 * @return void
	 */
	public function add_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'challan_dashboard_widget',
			__( 'Latest News from WebAppick Blog', 'webappick-pdf-invoice-for-woocommerce' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render posts + upsell footer.
	 *
	 * @return void
	 */
	public function render() {
		$posts = $this->posts();

		if ( array() === $posts ) {
			printf(
				'<p>%s <a href="https://webappick.com/blog/" target="_blank" rel="noopener">webappick.com/blog</a></p>',
				esc_html__( 'Visit the WebAppick blog:', 'webappick-pdf-invoice-for-woocommerce' )
			);
		} else {
			echo '<ul style="margin:0 0 12px">';
			foreach ( $posts as $post ) {
				printf(
					'<li><a href="%s" target="_blank" rel="noopener">%s</a></li>',
					esc_url( $post['link'] ),
					esc_html( $post['title'] )
				);
			}
			echo '</ul>';
		}

		printf(
			'<p style="border-top:1px solid #eee;padding-top:10px;margin-bottom:0">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_html__( 'Paid stamps, QR codes, signatures, ZATCA and multilingual documents:', 'webappick-pdf-invoice-for-woocommerce' ),
			'https://webappick.com/plugin/woocommerce-pdf-invoice-packing-slips/',
			esc_html__( 'Challan Pro', 'webappick-pdf-invoice-for-woocommerce' )
		);
	}

	/**
	 * Latest posts, cached for 12 hours.
	 *
	 * @return array<int,array{title:string,link:string}>
	 */
	private function posts() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$posts = array();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Not a VIP site (vip_safe_wp_remote_get() is unavailable); the feed result is cached, and 5s keeps slow hosts from blanking the widget.
		$response = wp_remote_get( self::FEED_URL, array( 'timeout' => 5 ) );

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {
				foreach ( array_slice( $data, 0, 5 ) as $post ) {
					if ( isset( $post['title']['rendered'], $post['link'] ) ) {
						$posts[] = array(
							'title' => wp_strip_all_tags( (string) $post['title']['rendered'] ),
							'link'  => (string) $post['link'],
						);
					}
				}
			}
		}

		// Cache failures briefly too, so a down blog never hammers.
		set_transient( self::TRANSIENT, $posts, array() === $posts ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );

		return $posts;
	}
}
