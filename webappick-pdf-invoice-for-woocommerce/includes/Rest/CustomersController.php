<?php
/**
 * Customer search for the Bulk download filters.
 *
 * @package Challan
 */

namespace Challan\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * GET challan/v1/customers?search= — registered customers matching the
 * term, for the multi-customer combobox. The customer FILTER itself is
 * pro-gated on the bulk endpoints; this read-only lookup is harmless.
 *
 * @since 4.0.0
 */
final class CustomersController extends AbstractController {

	/**
	 * Maximum number of customers returned.
	 */
	const LIMIT = 20;

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		/**
		 * Searches registered customers, for the Bulk screen's customer
		 * filter.
		 *
		 * Uses WooCommerce's own customer search
		 * (WC_Customer_Data_Store::search_customers()); at most 20 results.
		 * A blank term returns an empty list.
		 *
		 * Route:       GET /challan/v1/customers
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None (filtering bulk downloads by customer needs Pro)
		 * Request:     Query — `search` (required; see the route's args)
		 * Response:    200 JSON — see get_search_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *              rest_missing_callback_param (400) `search` missing
		 *
		 * @since 4.0.0
		 * @see   CustomersController::search()
		 */
		$this->register_schema_route(
			'/customers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'search' => array(
						'description'       => __( 'Search term, passed to WooCommerce\'s customer search.', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			'get_search_schema'
		);
	}

	/**
	 * Search customers via WooCommerce's own lookup.
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response Response matching get_search_schema().
	 */
	public function search( \WP_REST_Request $request ) {
		$term      = trim( (string) $request['search'] );
		$customers = array();

		if ( '' !== $term && class_exists( \WC_Customer_Data_Store::class ) ) {
			$store = new \WC_Customer_Data_Store();
			$ids   = array_slice( (array) $store->search_customers( $term, self::LIMIT ), 0, self::LIMIT );

			foreach ( $ids as $id ) {
				$user = get_userdata( (int) $id );
				if ( ! $user ) {
					continue;
				}
				$customers[] = array(
					'id'    => (int) $id,
					'name'  => (string) $user->display_name,
					'email' => (string) $user->user_email,
				);
			}
		}

		return rest_ensure_response( array( 'customers' => $customers ) );
	}

	/**
	 * Response schema of GET /customers.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_search_schema() {
		return Schema::object(
			'customers',
			array(
				'customers' => array(
					'description' => __( 'Matching customers, at most 20.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array(
								'description' => __( 'User ID.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'integer',
							),
							'name'  => array(
								'description' => __( 'Display name.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
							'email' => array(
								'description' => __( 'Account email.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'string',
							),
						),
					),
				),
			)
		);
	}
}
