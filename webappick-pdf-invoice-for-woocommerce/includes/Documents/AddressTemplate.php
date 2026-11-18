<?php
/**
 * {{placeholder}} address-template substitution.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Substitutes v1's shipping-label placeholders. Behavior preserved from
 * pro v4.5.x: the 22 known billing_ and shipping_ keys read WC_Order
 * getters (shipping_email from order meta), any other {{key}} falls back
 * to order meta — retried with a leading underscore — arrays imploded
 * with '-', state and country resolved to display names, empty lines
 * collapsed, lines joined with <br>.
 */
final class AddressTemplate {

	/**
	 * Render a template against one order.
	 *
	 * @param string    $template Template with {{placeholders}}.
	 * @param \WC_Order $order    Order.
	 * @return string HTML (escaped values joined with <br>).
	 */
	public static function render( $template, \WC_Order $order ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $template );
		$out   = array();

		foreach ( $lines as $line ) {
			$rendered = preg_replace_callback(
				'/{{(.*?)}}/',
				static function ( $matches ) use ( $order ) {
					return self::value( trim( $matches[1] ), $order );
				},
				$line
			);
			// Tabs separate placeholders on one line in the v1 default template.
			$rendered = trim( preg_replace( '/[\t ]+/', ' ', (string) $rendered ) );
			if ( '' !== $rendered ) {
				$out[] = esc_html( $rendered );
			}
		}

		return implode( '<br>', $out );
	}

	/**
	 * Resolve one placeholder key.
	 *
	 * @param string    $key   Placeholder key.
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private static function value( $key, \WC_Order $order ) {
		switch ( $key ) {
			case 'billing_first_name':
				return (string) $order->get_billing_first_name();
			case 'billing_last_name':
				return (string) $order->get_billing_last_name();
			case 'billing_company':
				return (string) $order->get_billing_company();
			case 'billing_address_1':
				return (string) $order->get_billing_address_1();
			case 'billing_address_2':
				return (string) $order->get_billing_address_2();
			case 'billing_city':
				return (string) $order->get_billing_city();
			case 'billing_postcode':
				return (string) $order->get_billing_postcode();
			case 'billing_phone':
				return (string) $order->get_billing_phone();
			case 'billing_email':
				return (string) $order->get_billing_email();
			case 'billing_state':
				return self::state_name( $order->get_billing_state(), $order->get_billing_country() );
			case 'billing_country':
				return self::country_name( $order->get_billing_country() );
			case 'shipping_first_name':
				return (string) $order->get_shipping_first_name();
			case 'shipping_last_name':
				return (string) $order->get_shipping_last_name();
			case 'shipping_company':
				return (string) $order->get_shipping_company();
			case 'shipping_address_1':
				return (string) $order->get_shipping_address_1();
			case 'shipping_address_2':
				return (string) $order->get_shipping_address_2();
			case 'shipping_city':
				return (string) $order->get_shipping_city();
			case 'shipping_postcode':
				return (string) $order->get_shipping_postcode();
			case 'shipping_phone':
				return method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';
			case 'shipping_email':
				return self::meta( $order, '_shipping_email' );
			case 'shipping_state':
				return self::state_name( $order->get_shipping_state(), $order->get_shipping_country() );
			case 'shipping_country':
				return self::country_name( $order->get_shipping_country() );
			default:
				// v1 fallback: order meta, retried with a leading underscore.
				$value = self::meta( $order, $key );
				if ( '' === $value ) {
					$value = self::meta( $order, '_' . $key );
				}
				return $value;
		}
	}

	/**
	 * Order meta as string (arrays imploded with '-', v1 behavior).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 * @return string
	 */
	private static function meta( \WC_Order $order, $key ) {
		$value = $order->get_meta( $key );
		if ( is_array( $value ) ) {
			return implode( '-', array_map( 'strval', $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * State display name (falls back to the raw code).
	 *
	 * @param string $state   State code.
	 * @param string $country Country code.
	 * @return string
	 */
	private static function state_name( $state, $country ) {
		if ( '' === (string) $state ) {
			return '';
		}
		$states = WC()->countries->get_states( $country );
		return isset( $states[ $state ] ) ? (string) $states[ $state ] : (string) $state;
	}

	/**
	 * Country display name (falls back to the raw code).
	 *
	 * @param string $country Country code.
	 * @return string
	 */
	private static function country_name( $country ) {
		if ( '' === (string) $country ) {
			return '';
		}
		return isset( WC()->countries->countries[ $country ] )
			? (string) WC()->countries->countries[ $country ]
			: (string) $country;
	}
}
