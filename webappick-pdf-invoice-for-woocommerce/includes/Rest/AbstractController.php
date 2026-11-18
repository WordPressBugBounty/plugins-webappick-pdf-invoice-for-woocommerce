<?php
/**
 * Base class for all challan/v1 REST controllers.
 *
 * @package Challan
 */

namespace Challan\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Shared namespace, permission model and schema registration.
 *
 * COMPATIBILITY CONTRACT: the namespace is `challan/v1` (pre-existing,
 * never renamed).
 *
 * Permission model: routes that use permission_check() require a logged-in
 * user with `manage_woocommerce`, mirroring the legacy admin/shop-manager
 * rule. A route may use its own permission callback instead; today two do:
 * `GET /orders/{id}/document` (managers; the order's customer when the
 * download settings allow it; a request carrying the order key, from an
 * email download link or, with Pro, a guest link; or access granted
 * through the `challan_document_access` filter) and
 * `POST /version-control/install` (`update_plugins`).
 *
 * Cookie-authenticated requests need a valid `wp_rest` nonce (the
 * `X-WP-Nonce` header or a `_wpnonce` parameter); without one WordPress
 * treats the request as logged out, before any permission callback runs.
 *
 * Pro's controllers extend this class.
 */
abstract class AbstractController {

	const REST_NAMESPACE = 'challan/v1';

	/**
	 * Capability required by permission_check().
	 *
	 * @var string
	 */
	protected $capability = 'manage_woocommerce';

	/**
	 * Public response schemas already built, keyed by schema method name.
	 *
	 * @since 4.0.0
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $public_schemas = array();

	/**
	 * Register this controller's routes. Called on rest_api_init.
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * Permission callback: a logged-in user with `manage_woocommerce`.
	 *
	 * @return true|\WP_Error True when allowed; `challan_rest_forbidden` with
	 *                        status 401 (logged out) or 403 (logged in)
	 *                        otherwise.
	 */
	public function permission_check() {
		if ( current_user_can( $this->capability ) ) {
			return true;
		}
		return new \WP_Error(
			'challan_rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'webappick-pdf-invoice-for-woocommerce' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Register a route together with its response schema.
	 *
	 * The schema is published in the route's OPTIONS response and in the
	 * namespace index (`/wp-json/challan/v1`). It is built on first use and
	 * then reused for the rest of the request.
	 *
	 * @since 4.0.0
	 *
	 * @param string                  $route         Route path, e.g. '/settings'.
	 * @param array<int|string,mixed> $handlers      One handler (an array with a
	 *                                               'callback' key) or a list of
	 *                                               handlers, as accepted by
	 *                                               register_rest_route().
	 * @param string                  $schema_method Name of a method on this
	 *                                               controller that returns the
	 *                                               response schema.
	 * @return bool The result of register_rest_route().
	 */
	protected function register_schema_route( $route, array $handlers, $schema_method ) {
		if ( isset( $handlers['callback'] ) ) {
			$handlers = array( $handlers );
		}

		if ( method_exists( $this, $schema_method ) ) {
			$handlers['schema'] = function () use ( $schema_method ) {
				return $this->get_public_schema( $schema_method );
			};
		} else {
			_doing_it_wrong(
				esc_html( static::class . '::register_schema_route' ),
				esc_html( sprintf( 'Schema method "%s" does not exist; the route was registered without a schema.', $schema_method ) ),
				'4.0.0'
			);
		}

		return register_rest_route( self::REST_NAMESPACE, $route, $handlers );
	}

	/**
	 * A response schema as published to clients.
	 *
	 * Like core's WP_REST_Controller::get_public_item_schema(), this removes
	 * the server-only `arg_options` key from each top-level property.
	 *
	 * @since 4.0.0
	 *
	 * @param string $schema_method Name of the method that builds the schema.
	 * @return array<string,mixed>
	 */
	public function get_public_schema( $schema_method ) {
		if ( ! isset( $this->public_schemas[ $schema_method ] ) ) {
			$schema = (array) $this->{$schema_method}();

			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				foreach ( $schema['properties'] as $name => $property ) {
					if ( is_array( $property ) ) {
						unset( $schema['properties'][ $name ]['arg_options'] );
					}
				}
			}

			$this->public_schemas[ $schema_method ] = $schema;
		}

		return $this->public_schemas[ $schema_method ];
	}
}
