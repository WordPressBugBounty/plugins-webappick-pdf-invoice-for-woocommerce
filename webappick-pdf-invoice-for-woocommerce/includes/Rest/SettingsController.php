<?php
/**
 * GET/PATCH /challan/v1/settings
 *
 * @package Challan
 */

namespace Challan\Rest;

use Challan\Settings\Registry;
use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Settings read/write endpoint. PATCH accepts a flat JSON object of
 * { "option_name": value } pairs and persists only registered keys.
 *
 * The PATCH body is deliberately not declared key by key in `args`: core
 * would then reject the whole request when one value has the wrong type,
 * while Repository::save() saves the valid keys and reports each invalid
 * one in `errors`.
 *
 * @since 4.0.0
 */
class SettingsController extends AbstractController {

	/**
	 * Settings repository.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * Settings registry (the source of the response schema).
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Create the controller.
	 *
	 * @param Repository    $repository Settings repository.
	 * @param Registry|null $registry   Settings registry; defaults to the
	 *                                  plugin's shared registry.
	 */
	public function __construct( Repository $repository, ?Registry $registry = null ) {
		$this->repository = $repository;
		$this->registry   = null !== $registry ? $registry : \Challan\Plugin::instance()->registry();
	}

	/**
	 * Register this controller's REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		/**
		 * Reads and partially updates plugin settings.
		 *
		 * GET returns every registered setting with its typed value. PATCH
		 * saves only the keys sent: unknown keys, Pro keys while Challan Pro
		 * is inactive, and invalid values are reported per key in `errors`;
		 * the other keys are still saved.
		 *
		 * Route:       GET|PATCH /challan/v1/settings
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None (Pro keys need Challan Pro active)
		 * Request:     GET — none.
		 *              PATCH — JSON body (Content-Type: application/json),
		 *              an object of setting key => value. Keys and value
		 *              types: see get_settings_schema(); values are checked
		 *              per key by Repository::save().
		 * Response:    200 JSON — see get_settings_schema(). GET returns
		 *              `settings`; PATCH returns `saved` and `errors`.
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *              challan_rest_empty_body (400) PATCH body missing,
		 *              empty or not a JSON object
		 *              challan_rest_invalid_settings (400) no key could be
		 *              saved; `data.errors` holds the reason per key
		 *
		 * @since 4.0.0
		 * @see   SettingsController::get_settings()
		 * @see   SettingsController::update_settings()
		 */
		$this->register_schema_route(
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			),
			'get_settings_schema'
		);
	}

	/**
	 * All settings values, typed.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_settings_schema()
	 *                           (the `settings` key).
	 */
	public function get_settings() {
		return rest_ensure_response( array( 'settings' => $this->repository->all() ) );
	}

	/**
	 * Partial update. Body: flat JSON object of option name => value.
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Response matching
	 *                                     get_settings_schema() (the `saved`
	 *                                     and `errors` keys), or
	 *                                     challan_rest_empty_body /
	 *                                     challan_rest_invalid_settings.
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$values = $request->get_json_params();

		if ( ! is_array( $values ) || array() === $values ) {
			return new \WP_Error(
				'challan_rest_empty_body',
				__( 'Request body must be a JSON object of settings to update.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->repository->save( $values, challan_is_pro_active() );

		if ( array() !== $result['errors'] && array() === $result['saved'] ) {
			return new \WP_Error(
				'challan_rest_invalid_settings',
				__( 'No settings were saved.', 'webappick-pdf-invoice-for-woocommerce' ),
				array(
					'status' => 400,
					'errors' => $result['errors'],
				)
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Response schema of /settings (GET and PATCH).
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings_schema() {
		return Schema::object(
			'settings',
			array(
				'settings' => array(
					'description' => __( 'GET only. Every registered setting with its typed value.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'object',
					'properties'  => SettingsSchema::properties( $this->registry ),
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				'saved'    => array(
					'description' => __( 'PATCH only. The keys that were saved, with their new typed values (the same types as in "settings").', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				// PATCH only.
				'errors'   => SettingsSchema::save_errors(),
			)
		);
	}
}
