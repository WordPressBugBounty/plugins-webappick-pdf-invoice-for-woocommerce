<?php
/**
 * GET /challan/v1/bootstrap — everything the SPA needs for first paint —
 * and GET /challan/v1/order-meta-keys.
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
 * Single round-trip payload: settings values + schema + system + pro state,
 * plus the order meta key search used by the Order Info field picker.
 *
 * @since 4.0.0
 */
class BootstrapController extends AbstractController {

	/**
	 * Settings registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Settings repository.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * Create the controller.
	 *
	 * @param Registry   $registry   Settings registry.
	 * @param Repository $repository Settings repository.
	 */
	public function __construct( Registry $registry, Repository $repository ) {
		$this->registry   = $registry;
		$this->repository = $repository;
	}

	/**
	 * Register this controller's REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		/**
		 * Returns everything the admin app needs for its first render.
		 *
		 * One round trip: settings values, the settings field list, system
		 * facts, fonts, WooCommerce lists (gateways, order statuses, roles,
		 * attributes, emails), document languages and the Pro state. The
		 * payload is filterable through `challan_bootstrap_payload` (Challan
		 * Pro adds `assets`), and its schema through
		 * `challan_bootstrap_schema`.
		 *
		 * Route:       GET /challan/v1/bootstrap
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_bootstrap_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0
		 * @see   BootstrapController::get_bootstrap()
		 */
		$this->register_schema_route(
			'/bootstrap',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bootstrap' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_bootstrap_schema'
		);

		/**
		 * Searches the meta keys used on orders, for the Order Info field
		 * picker.
		 *
		 * Matches the term anywhere in the key; at most 30 keys, sorted,
		 * cached for 10 minutes per term. Reads the HPOS order meta table
		 * when HPOS is on, order post meta otherwise.
		 *
		 * Route:       GET /challan/v1/order-meta-keys
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     Query — `search` (see the route's args)
		 * Response:    200 JSON — see get_order_meta_keys_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0
		 * @see   BootstrapController::get_order_meta_keys()
		 */
		$this->register_schema_route(
			'/order-meta-keys',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order_meta_keys' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'search' => array(
						'description'       => __( 'Text to find anywhere in the meta key. Empty lists the first keys alphabetically.', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			'get_order_meta_keys_schema'
		);
	}

	/**
	 * Order meta keys matching a search term (Order Info dropdown).
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response Response matching get_order_meta_keys_schema().
	 */
	public function get_order_meta_keys( \WP_REST_Request $request ) {
		return rest_ensure_response(
			array( 'keys' => \Challan\Documents\MetaFields::order_meta_keys( (string) $request->get_param( 'search' ) ) )
		);
	}

	/**
	 * Bootstrap payload.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_bootstrap_schema().
	 */
	public function get_bootstrap() {
		$payload = array(
			'version'        => CHALLAN_VERSION,
			'settings'       => $this->repository->all(),
			'schema'         => $this->registry->schema(),
			'system'         => SystemStatusController::snapshot(),
			'fonts'          => $this->fonts_payload(),
			'gateways'       => $this->gateways_payload(),
			'order_statuses' => $this->order_statuses_payload(),
			'roles'          => $this->roles_payload(),
			// Global product attributes (v1 Pro's attribute chooser).
			'attributes'     => $this->attributes_payload(),
			// Document-language choices (v1 Pro's list).
			'languages'      => \Challan\Documents\Locales::all(),
			'emails'         => $this->emails_payload(),
			'pro'            => array(
				'active'   => challan_is_pro_active(),
				/**
				 * Filter whether Challan Pro has an active licence.
				 * Challan Pro returns its licence state.
				 *
				 * @param bool $licensed Defaults to false.
				 */
				'licensed' => apply_filters( 'challan_pro_licensed', false ),
				/**
				 * Filter the Challan Pro version shown in the admin app.
				 * Challan Pro returns CHALLAN_PRO_VERSION.
				 *
				 * @param string|null $version Defaults to null (no Pro).
				 */
				'version'  => apply_filters( 'challan_pro_version', null ),
			),
		);

		/**
		 * Filter the full bootstrap payload before it is returned to the SPA.
		 *
		 * @param array<string,mixed> $payload Bootstrap payload.
		 */
		return rest_ensure_response( apply_filters( 'challan_bootstrap_payload', $payload ) );
	}

	/**
	 * Global product attributes: slug (without pa_) => label.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function attributes_payload() {
		$attributes = array();
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
				$attributes[] = array(
					'value' => (string) $taxonomy->attribute_name,
					'label' => (string) $taxonomy->attribute_label,
				);
			}
		}

		return $attributes;
	}

	/**
	 * Font choices for the Fonts screen: mPDF's built-in families plus
	 * the custom fonts registered in the frozen legacy option.
	 *
	 * @return array{families:string[],custom:array<int,array{key:string,files:int}>}
	 */
	private function fonts_payload() {
		// mPDF is autoloaded lazily by the PDF engine — load it here too
		// (and tolerate its absence: dev checkouts before composer install).
		if ( ! class_exists( \Mpdf\Config\FontVariables::class ) && file_exists( CHALLAN_PATH . 'vendor/autoload.php' ) ) {
			require_once CHALLAN_PATH . 'vendor/autoload.php';
		}

		$families = array();
		if ( class_exists( \Mpdf\Config\FontVariables::class ) ) {
			$families = array_keys( ( new \Mpdf\Config\FontVariables() )->getDefaults()['fontdata'] );
			sort( $families );
		}

		$custom = array();
		$list   = get_option( 'wpifw_custom_font_list', array() );
		if ( is_array( $list ) ) {
			foreach ( $list as $key => $files ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key || ! is_array( $files ) ) {
					continue;
				}
				$custom[] = array(
					'key'   => $key,
					'files' => count( array_filter( $files ) ),
				);
			}
		}

		return array(
			'families' => $families,
			'custom'   => $custom,
		);
	}

	/**
	 * Order statuses for the Bulk download filters — from
	 * wc_get_order_statuses(), so statuses registered by third-party
	 * plugins are included automatically.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function order_statuses_payload() {
		$statuses = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $key => $label ) {
				$statuses[] = array(
					'value' => (string) $key,
					'label' => (string) $label,
				);
			}
		}
		return $statuses;
	}

	/**
	 * Registered WooCommerce emails for the attachment selector — from
	 * the live mailer, so emails added by third-party plugins are
	 * included automatically. Values are the email ids v1 stored in
	 * wpifw_email_attach_check_list.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function emails_payload() {
		$emails = array();
		if ( function_exists( 'WC' ) && method_exists( WC(), 'mailer' ) ) {
			foreach ( (array) WC()->mailer()->get_emails() as $email ) {
				if ( ! is_object( $email ) || '' === (string) $email->id ) {
					continue;
				}
				$emails[] = array(
					'value'    => (string) $email->id,
					'label'    => (string) $email->get_title(),
					'customer' => method_exists( $email, 'is_customer_email' ) && $email->is_customer_email(),
				);
			}
		}

		// WooCommerce sends admin and customer emails with the same title
		// (e.g. "Cancelled order"); say who receives each when they clash.
		$counts = array_count_values( wp_list_pluck( $emails, 'label' ) );
		foreach ( $emails as &$entry ) {
			if ( $counts[ $entry['label'] ] > 1 ) {
				$entry['label'] .= $entry['customer'] ? ' ' . __( '(to customer)', 'webappick-pdf-invoice-for-woocommerce' ) : ' ' . __( '(to admin)', 'webappick-pdf-invoice-for-woocommerce' );
			}
			unset( $entry['customer'] );
		}
		unset( $entry );

		return $emails;
	}

	/**
	 * Editable roles for the "who can generate documents" checklist.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function roles_payload() {
		$roles = array();
		if ( function_exists( 'get_editable_roles' ) || file_exists( ABSPATH . 'wp-admin/includes/user.php' ) ) {
			if ( ! function_exists( 'get_editable_roles' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			foreach ( get_editable_roles() as $slug => $role ) {
				$roles[] = array(
					'value' => (string) $slug,
					'label' => translate_user_role( (string) $role['name'] ),
				);
			}
		}
		return $roles;
	}

	/**
	 * Enabled payment gateways for the Bulk download filters.
	 *
	 * @return array<int,array{id:string,title:string}>
	 */
	private function gateways_payload() {
		$gateways = array();
		if ( function_exists( 'WC' ) && null !== WC()->payment_gateways ) {
			foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway ) {
				if ( 'yes' === $gateway->enabled ) {
					$gateways[] = array(
						'id'    => (string) $gateway->id,
						'title' => wp_strip_all_tags( (string) $gateway->get_title() ),
					);
				}
			}
		}
		return $gateways;
	}

	/**
	 * Response schema of GET /bootstrap.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_bootstrap_schema() {
		$value_label = self::list_of(
			array(
				'value' => array( 'type' => 'string' ),
				'label' => array( 'type' => 'string' ),
			)
		);

		$schema = Schema::object(
			'bootstrap',
			array(
				'version'        => self::readonly_property(
					__( 'Challan version (CHALLAN_VERSION).', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'type' => 'string' )
				),
				'settings'       => self::readonly_property(
					__( 'Every registered setting with its typed value, as returned by GET /settings.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'       => 'object',
						'properties' => SettingsSchema::properties( $this->registry ),
					)
				),
				'schema'         => self::readonly_property(
					__( 'The settings field list the admin app renders from: section => setting key => { type, default, pro }. "type" is the Challan field type (bool, int, string, textarea, css, array) and "default" is the stored default (for example "1" for an enabled toggle), not the typed value. A section without fields is sent as [].', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'                 => 'object',
						'additionalProperties' => array(
							'type'                 => array( 'object', 'array' ),
							'additionalProperties' => array(
								'type'       => 'object',
								'properties' => array(
									'type'    => array( 'type' => 'string' ),
									'default' => array(
										'description' => __( 'Stored default; its JSON type depends on the field.', 'webappick-pdf-invoice-for-woocommerce' ),
										'type'        => array( 'string', 'boolean', 'integer', 'number', 'array', 'object', 'null' ),
									),
									'pro'     => array( 'type' => 'boolean' ),
								),
							),
						),
					)
				),
				'system'         => self::readonly_property(
					__( 'Environment facts for the Status screen.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'       => 'object',
						'properties' => array(
							'plugin_version'     => array( 'type' => 'string' ),
							'php_version'        => array( 'type' => 'string' ),
							'wp_version'         => array( 'type' => 'string' ),
							'wc_version'         => array(
								'description' => __( 'Null when WooCommerce is not loaded.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => array( 'string', 'null' ),
							),
							'wc_active'          => array( 'type' => 'boolean' ),
							'hpos_enabled'       => array( 'type' => 'boolean' ),
							'memory_limit'       => array(
								'description' => __( 'PHP memory_limit as ini_get() returns it; false if unavailable.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => array( 'string', 'boolean' ),
							),
							'max_execution_time' => array(
								'description' => __( 'PHP max_execution_time as ini_get() returns it; false if unavailable.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => array( 'string', 'boolean' ),
							),
							'uploads_writable'   => array( 'type' => 'boolean' ),
							'mpdf_installed'     => array( 'type' => 'boolean' ),
							'fonts_downloaded'   => array( 'type' => 'boolean' ),
							'locale'             => array( 'type' => 'string' ),
						),
					)
				),
				'fonts'          => self::readonly_property(
					__( 'Fonts for the Fonts screen.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'       => 'object',
						'properties' => array(
							'families' => array(
								'description' => __( 'mPDF built-in font families, sorted. Empty when mPDF is not installed.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
							),
							'custom'   => array(
								'description' => __( 'Custom fonts from the wpifw_custom_font_list option: key and number of font files.', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'array',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'key'   => array( 'type' => 'string' ),
										'files' => array( 'type' => 'integer' ),
									),
								),
							),
						),
					)
				),
				'gateways'       => self::readonly_property(
					__( 'Enabled payment gateways (bulk download filter).', 'webappick-pdf-invoice-for-woocommerce' ),
					self::list_of(
						array(
							'id'    => array( 'type' => 'string' ),
							'title' => array( 'type' => 'string' ),
						)
					)
				),
				'order_statuses' => self::readonly_property(
					__( 'Order statuses from wc_get_order_statuses(), including those added by other plugins. "value" keeps the "wc-" prefix.', 'webappick-pdf-invoice-for-woocommerce' ),
					$value_label
				),
				'roles'          => self::readonly_property(
					__( 'User roles the current user may edit (get_editable_roles()), for the "who can generate documents" setting.', 'webappick-pdf-invoice-for-woocommerce' ),
					$value_label
				),
				'attributes'     => self::readonly_property(
					__( 'Global product attributes: "value" is the attribute name without the "pa_" prefix.', 'webappick-pdf-invoice-for-woocommerce' ),
					$value_label
				),
				'languages'      => self::readonly_property(
					__( 'Document languages: locale code => native language name.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
					)
				),
				'emails'         => self::readonly_property(
					__( 'WooCommerce emails for the attachment setting. "value" is the email ID stored in wpifw_email_attach_check_list; a label shared by an admin and a customer email gets "(to admin)" / "(to customer)" added.', 'webappick-pdf-invoice-for-woocommerce' ),
					$value_label
				),
				'pro'            => self::readonly_property(
					__( 'Challan Pro state.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'       => 'object',
						'properties' => array(
							'active'   => array(
								'description' => __( 'Whether Challan Pro is active (challan_pro_active filter).', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'boolean',
							),
							'licensed' => array(
								'description' => __( 'Whether Challan Pro has an active licence (challan_pro_licensed filter).', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => 'boolean',
							),
							'version'  => array(
								'description' => __( 'Challan Pro version, or null without Pro (challan_pro_version filter).', 'webappick-pdf-invoice-for-woocommerce' ),
								'type'        => array( 'string', 'null' ),
							),
						),
					)
				),
			)
		);

		/**
		 * Filter the published schema of GET /challan/v1/bootstrap.
		 *
		 * Add-ons that add keys through `challan_bootstrap_payload` describe
		 * them here, under `properties`.
		 *
		 * @since 4.0.0
		 *
		 * @param array<string,mixed> $schema Bootstrap response schema.
		 */
		return apply_filters( 'challan_bootstrap_schema', $schema );
	}

	/**
	 * Response schema of GET /order-meta-keys.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_order_meta_keys_schema() {
		return Schema::object(
			'order-meta-keys',
			array(
				'keys' => self::readonly_property(
					__( 'Matching order meta keys, sorted, at most 30.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					)
				),
			)
		);
	}

	/**
	 * A read-only response property in the edit context.
	 *
	 * @param string              $description Translated description.
	 * @param array<string,mixed> $schema      Property schema.
	 * @return array<string,mixed>
	 */
	private static function readonly_property( $description, array $schema ) {
		return array_merge(
			array( 'description' => $description ),
			$schema,
			array(
				'context'  => array( 'edit' ),
				'readonly' => true,
			)
		);
	}

	/**
	 * A list of objects with the given properties.
	 *
	 * @param array<string,mixed> $properties Item properties.
	 * @return array<string,mixed>
	 */
	private static function list_of( array $properties ) {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => $properties,
			),
		);
	}
}
