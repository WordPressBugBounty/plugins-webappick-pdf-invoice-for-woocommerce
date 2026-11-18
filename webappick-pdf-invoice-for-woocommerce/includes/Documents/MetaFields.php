<?php
/**
 * Custom meta-field rendering on documents.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Renders the configured order-meta rows at their positions (v1
 * mechanism preserved: callbacks on the dynamic `woo_invoice_{position}`
 * actions, fired by the renderers with ($order, $template, $position)),
 * plus the `wpifw_custom_order_meta` rows in the order-data table.
 *
 * All meta reads go through WC_Order::get_meta (HPOS-safe — v1 used
 * get_post_meta in places, which breaks on HPOS; behavior preserved,
 * storage access fixed).
 */
class MetaFields {

	const POSITIONS = array(
		'before_order_data',
		'after_order_data',
		'before_billing_address',
		'after_billing_address',
		'before_shipping_address',
		'after_shipping_address',
	);

	/**
	 * Hook the position callbacks (v1 registered one per saved position;
	 * registering on all six is equivalent — the callback filters).
	 *
	 * @return void
	 */
	public function register() {
		foreach ( self::POSITIONS as $position ) {
			add_action( 'woo_invoice_' . $position, array( $this, 'render_position' ), 10, 4 );
		}
	}

	/**
	 * Print the configured order-meta rows for one position — the invoice
	 * list, or the packing slip's own list (v1 pro).
	 *
	 * @param \WC_Order|mixed                   $order    Order.
	 * @param string                            $template Document type id.
	 * @param string                            $position Current position id.
	 * @param \Challan\Settings\Repository|null $settings Render settings (overlay-aware).
	 * @return void
	 */
	public function render_position( $order, $template = 'invoice', $position = '', $settings = null ) {
		// Credit notes print the invoice's list, as v1 pro did.
		if ( ! $order instanceof \WC_Order || ! in_array( $template, array( 'invoice', 'credit_note', 'packing_slip' ), true ) || '' === $position ) {
			return;
		}

		// Through the render's repository when provided so preview
		// overlays apply; plain options otherwise (v1 callers).
		$get = static function ( $key ) use ( $settings ) {
			return $settings instanceof \Challan\Settings\Repository ? $settings->get( $key ) : get_option( $key );
		};

		// The packing slip has its own list (v1 pro: *_ps keys).
		$suffix    = 'packing_slip' === $template ? '_ps' : '';
		$labels    = $get( '_winvoice_order_meta_label' . $suffix );
		$names     = $get( '_winvoice_order_meta_name' . $suffix );
		$positions = $get( 'packing_slip' === $template ? '_winvoice_order_meta_position_ps' : '_winvoice_order_meta_name_position' );
		if ( ! is_array( $labels ) || ! is_array( $names ) || ! is_array( $positions ) ) {
			return;
		}

		$in_table = in_array( $position, array( 'before_order_data', 'after_order_data' ), true );

		foreach ( $labels as $key => $label ) {
			if ( ! isset( $positions[ $key ], $names[ $key ] ) || $positions[ $key ] !== $position ) {
				continue;
			}

			$value = $order->get_meta( $names[ $key ], true );

			// v1: meta keys containing "date" are re-rendered in the
			// configured date format.
			if ( false !== strpos( $names[ $key ], 'date' ) && '' !== (string) $value && ! is_array( $value ) ) {
				$timestamp = strtotime( (string) $value );
				if ( false !== $timestamp ) {
					$value = gmdate( (string) get_option( 'wpifw_date_format', 'd/m/Y' ), $timestamp );
				}
			}

			if ( '' === (string) $value || is_array( $value ) ) {
				continue;
			}

			if ( $in_table ) {
				echo '<tr><td class="order-data-label">' . esc_html( $label ) . '</td>'
					. '<td class="order-data-value">: ' . esc_html( (string) $value ) . '</td></tr>';
			} else {
				// Margin-free block: the meta continues on the next line
				// with no blank line above it (user decision 2026-08-20).
				echo '<p style="margin:0">' . esc_html( $label ) . ' : ' . esc_html( (string) $value ) . ' </p>';
			}
		}
	}

	/**
	 * The wpifw_custom_order_meta rows (meta_key => label) for the
	 * order-data table.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array{0:string,1:string}> [label, value] rows.
	 */
	public static function custom_order_meta_rows( \WC_Order $order ) {
		$map = get_option( 'wpifw_custom_order_meta' );
		if ( ! is_array( $map ) || array() === $map ) {
			return array();
		}

		$rows = array();
		foreach ( $map as $meta_key => $label ) {
			$value = $order->get_meta( (string) $meta_key, true );
			if ( '' !== (string) $value && ! is_array( $value ) ) {
				$rows[] = array( (string) $label, (string) $value );
			}
		}

		return $rows;
	}

	/**
	 * Distinct order meta keys matching a search term — options for the
	 * Order Info meta-key dropdown, loaded on demand over REST (v1
	 * semantics: wc_orders_meta under HPOS, shop_order postmeta
	 * otherwise). Capped at 30 matches per search.
	 *
	 * @param string $search Search term (matched anywhere in the key).
	 * @return array<int,string>
	 */
	public static function order_meta_keys( $search = '' ) {
		global $wpdb;
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// Cached per term for 10 minutes (v1 3.7.89 cached its key lists).
		$cache_key = 'challan_meta_keys_' . md5( ( $hpos ? 'h' : 'p' ) . (string) $search );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$like = '%' . $wpdb->esc_like( (string) $search ) . '%';

		if ( $hpos ) {
			$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API lists distinct order meta keys; LIMIT-bounded, transient-cached.
				$wpdb->prepare(
					"SELECT DISTINCT meta_key FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE %s ORDER BY meta_key LIMIT 30", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table prefix only.
					$like
				)
			);
		} else {
			$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API lists distinct order meta keys; LIMIT-bounded, transient-cached.
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
						LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
						WHERE p.post_type = 'shop_order' AND pm.meta_key LIKE %s
						ORDER BY pm.meta_key LIMIT 30",
					$like
				)
			);
		}

		$keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
		set_transient( $cache_key, $keys, 10 * MINUTE_IN_SECONDS );

		return $keys;
	}
}
