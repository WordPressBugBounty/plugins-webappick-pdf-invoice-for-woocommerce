<?php
/**
 * Version-control and WebAppick-plugins endpoints.
 *
 * @package Challan
 */

namespace Challan\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * GET  /challan/v1/version-control          — free-plugin release data from wp.org.
 * POST /challan/v1/version-control/install  — install a specific free version (rollback).
 * GET  /challan/v1/webappick-plugins        — live wp.org cards for the WebAppick plugins.
 *
 * Both screens show the FREE plugin's data on free and pro installs
 * alike (the pro package updates through its licence, not here).
 *
 * wp.org answers are cached in transients for 6 hours.
 *
 * @since 4.0.0
 */
class UpdatesController extends AbstractController {

	/**
	 * The wp.org slug of the free plugin (frozen).
	 */
	const FREE_SLUG = 'webappick-pdf-invoice-for-woocommerce';

	/**
	 * Register this controller's REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		/**
		 * Returns Challan Free's release data from WordPress.org for the
		 * Version Control screen.
		 *
		 * The installed and latest versions, the last 10 changelog entries
		 * (parsed from the wp.org changelog) and the 12 newest versions that
		 * can be installed. On a Pro site this still describes the free
		 * plugin. The wp.org answer is cached for 6 hours.
		 *
		 * Route:       GET /challan/v1/version-control
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_version_control_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *              challan_rest_wporg_unreachable (502) WordPress.org did
		 *              not answer
		 *
		 * @since 4.0.0
		 * @see   UpdatesController::get_version_control()
		 */
		$this->register_schema_route(
			'/version-control',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_version_control' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_version_control_schema'
		);

		/**
		 * Installs a specific Challan Free version from WordPress.org
		 * (rollback or re-install).
		 *
		 * Replaces the plugin files with the wp.org package of that version;
		 * settings, invoice numbers and documents are not touched, except
		 * that a 3.x install sets the invoice template back to a v1 one
		 * (Upgrader::prepare_v1_rollback()), since v1 has no stylesheet for
		 * the v2 templates. A 3.x install is refused while Challan Pro is
		 * active (Pro 5 needs Challan 4).
		 *
		 * Route:       POST /challan/v1/version-control/install
		 * Permission:  Own rule (install_permission_check()): update_plugins
		 * Licence:     None
		 * Request:     JSON body — `version` (see the route's args)
		 * Response:    200 JSON — see get_install_schema()
		 * Errors:      rest_forbidden (401/403) the user can't update plugins
		 *              challan_rest_unknown_version (400) the version is not
		 *              on WordPress.org (or WordPress.org did not answer)
		 *              challan_rest_rollback_blocked_by_pro (409) a 3.x
		 *              version while Challan Pro is active
		 *              challan_rest_install_failed (500) the installer failed
		 *
		 * @since 4.0.0
		 * @see   UpdatesController::install_version()
		 */
		$this->register_schema_route(
			'/version-control/install',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_version' ),
				'permission_callback' => array( $this, 'install_permission_check' ),
				'args'                => array(
					'version' => array(
						'description' => __( 'Version to install, as listed in "versions" by GET /version-control, for example "3.7.92".', 'webappick-pdf-invoice-for-woocommerce' ),
						'type'        => 'string',
						'default'     => '',
					),
				),
			),
			'get_install_schema'
		);

		/**
		 * Returns cards for WebAppick's WordPress.org plugins (CTX Feed,
		 * Challan, Disco) for the Plugins screen.
		 *
		 * Combines a fixed catalog with live wp.org data (icon, rating,
		 * reviews, installs, last update, tested-up-to, version; cached for
		 * 6 hours) and this site's install state. The wp.org fields are null
		 * when WordPress.org did not answer.
		 *
		 * Route:       GET /challan/v1/webappick-plugins
		 * Permission:  Admin (manage_woocommerce)
		 * Licence:     None
		 * Request:     None.
		 * Response:    200 JSON — see get_plugins_schema()
		 * Errors:      challan_rest_forbidden (401/403) not a manager
		 *
		 * @since 4.0.0
		 * @see   UpdatesController::get_plugins()
		 */
		$this->register_schema_route(
			'/webappick-plugins',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_plugins' ),
				'permission_callback' => array( $this, 'permission_check' ),
			),
			'get_plugins_schema'
		);
	}

	/**
	 * Permission callback for installing a version: `update_plugins`.
	 *
	 * Returns false (not a WP_Error), so WordPress core answers with its own
	 * `rest_forbidden` error, 401 logged out or 403 logged in.
	 *
	 * @since 4.0.0
	 *
	 * @return bool
	 */
	public function install_permission_check() {
		return current_user_can( 'update_plugins' );
	}

	/**
	 * The wp.org plugin information, cached.
	 *
	 * @param string $slug   Plugin slug.
	 * @param bool   $full   Include versions + sections (changelog).
	 * @return array<string,mixed>|null
	 */
	private function wporg_info( $slug, $full = false ) {
		$cache_key = 'challan_wporg_' . ( $full ? 'full_' : 'card_' ) . $slug;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$fields = $full
			? '&request%5Bfields%5D%5Bversions%5D=1&request%5Bfields%5D%5Bsections%5D=1&request%5Bfields%5D%5Bicons%5D=1'
			: '&request%5Bfields%5D%5Bicons%5D=1';

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Not a VIP site; vip_safe_wp_remote_get() is unavailable.
		$response = wp_remote_get(
			'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=' . rawurlencode( $slug ) . $fields,
			array( 'timeout' => 8 ) // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Admin-only, cached plugins_api lookup; the full-details payload needs more than 3s on slow hosts.
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return null;
		}

		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Version-control payload.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response|\WP_Error Response matching
	 *                                     get_version_control_schema(), or
	 *                                     challan_rest_wporg_unreachable.
	 */
	public function get_version_control() {
		$info = $this->wporg_info( self::FREE_SLUG, true );
		if ( null === $info ) {
			return new \WP_Error(
				'challan_rest_wporg_unreachable',
				__( 'Could not reach WordPress.org — try again in a minute.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 502 )
			);
		}

		$releases = $this->parse_changelog( (string) ( $info['sections']['changelog'] ?? '' ) );

		// Release dates by version, for the rollback picker.
		$dates = array();
		foreach ( $releases as $release ) {
			$dates[ $release['version'] ] = $release['date'];
		}

		$versions = array();
		foreach ( array_keys( (array) ( $info['versions'] ?? array() ) ) as $version ) {
			if ( '' !== $version && ctype_digit( $version[0] ) ) {
				$versions[] = $version;
			}
		}
		usort(
			$versions,
			static function ( $a, $b ) {
				return version_compare( $b, $a );
			} 
		);

		$rollback = array();
		foreach ( array_slice( $versions, 0, 12 ) as $version ) {
			$rollback[] = array(
				'version' => $version,
				'date'    => isset( $dates[ $version ] ) ? $dates[ $version ] : '',
			);
		}

		return rest_ensure_response(
			array(
				'installed'  => CHALLAN_VERSION,
				'latest'     => array(
					'version'  => (string) $info['version'],
					'released' => isset( $dates[ $info['version'] ] ) ? $dates[ $info['version'] ] : (string) ( $info['last_updated'] ?? '' ),
				),
				'up_to_date' => version_compare( CHALLAN_VERSION, (string) $info['version'], '>=' ),
				'releases'   => array_slice( $releases, 0, 10 ),
				'versions'   => $rollback,
			)
		);
	}

	/**
	 * Parse the wp.org changelog HTML into typed release entries.
	 *
	 * Blocks look like: <h4>3.7.85 ( May 21, 2026 )</h4><p>Type: text</p>
	 * — h4 headings that do not start with a version number (promo
	 * blocks) are skipped.
	 *
	 * @param string $html Changelog section HTML.
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_changelog( $html ) {
		$releases = array();

		if ( ! preg_match_all( '#<h4>\s*([0-9][^<(]*?)\s*(?:\(([^)]*)\))?\s*</h4>(.*?)(?=<h4>|$)#s', $html, $blocks, PREG_SET_ORDER ) ) {
			return $releases;
		}

		foreach ( $blocks as $block ) {
			$version = trim( $block[1] );
			$body    = $block[3];

			// One entry per <li> or <br>/<p> line.
			$body  = preg_replace( '#</(p|li)>|<br\s*/?>#i', "\n", $body );
			$lines = array_filter( array_map( 'trim', explode( "\n", wp_strip_all_tags( $body ) ) ) );

			$entries = array();
			foreach ( $lines as $line ) {
				// The SPA prints plain text: decode wp.org's &#8220; etc.
				$line      = html_entity_decode( $line, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$entries[] = array(
					'type' => $this->entry_type( $line ),
					'text' => $line,
				);
			}

			if ( array() !== $entries ) {
				$releases[] = array(
					'version' => $version,
					'date'    => trim( (string) ( $block[2] ?? '' ) ),
					'entries' => $entries,
				);
			}
		}

		return $releases;
	}

	/**
	 * Chip type for one changelog line.
	 *
	 * @param string $line Entry text.
	 * @return string security|fixed|new|improved|removed
	 */
	private function entry_type( $line ) {
		$lower = strtolower( $line );

		if ( 0 === strpos( $lower, 'security' ) || false !== strpos( $lower, 'vulnerability' ) ) {
			return 'security';
		}
		if ( 0 === strpos( $lower, 'fix' ) || 0 === strpos( $lower, 'bug' ) ) {
			return 'fixed';
		}
		if ( 0 === strpos( $lower, 'new' ) || 0 === strpos( $lower, 'add' ) || 0 === strpos( $lower, 'feature' ) ) {
			return 'new';
		}
		if ( 0 === strpos( $lower, 'remov' ) || 0 === strpos( $lower, 'deprecat' ) ) {
			return 'removed';
		}

		return 'improved';
	}

	/**
	 * Install a specific free-plugin version from wp.org (rollback or
	 * re-install). Replaces plugin files; settings and documents stay
	 * untouched, except the invoice template on a 3.x install.
	 *
	 * @since 4.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Response matching
	 *                                     get_install_schema(), or an error
	 *                                     listed on the route.
	 */
	public function install_version( \WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$version = isset( $params['version'] ) ? sanitize_text_field( (string) $params['version'] ) : '';

		$info = $this->wporg_info( self::FREE_SLUG, true );
		if ( null === $info || empty( $info['versions'][ $version ] ) ) {
			return new \WP_Error(
				'challan_rest_unknown_version',
				__( 'That version is not available on WordPress.org.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		// A 3.x (v1) release can't run Challan Pro 5, which needs Challan 4:
		// documents would stop until Challan 4 is back.
		$rollback = ctype_digit( substr( $version, 0, 1 ) ) && version_compare( $version, '4.0.0', '<' );
		if ( $rollback && challan_is_pro_active() ) {
			return new \WP_Error(
				'challan_rest_rollback_blocked_by_pro',
				__( 'Challan Pro 5 needs Challan 4, so a 3.x version can\'t be installed while Challan Pro is active. Deactivate Challan Pro first.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install(
			esc_url_raw( (string) $info['versions'][ $version ] ),
			array( 'overwrite_package' => true )
		);

		if ( true !== $result ) {
			return new \WP_Error(
				'challan_rest_install_failed',
				is_wp_error( $result )
					? $result->get_error_message()
					: __( 'Installation failed — try again from the Plugins screen.', 'webappick-pdf-invoice-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}

		if ( $rollback ) {
			// v1 has no stylesheet for the v2 templates: hand it a v1 one.
			\Challan\Upgrader::prepare_v1_rollback();
		}

		return rest_ensure_response(
			array(
				'installed' => $version,
			)
		);
	}

	/**
	 * WebAppick plugin cards from wp.org, with local install state.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response Response matching get_plugins_schema().
	 */
	public function get_plugins() {
		$catalog = array(
			array(
				'slug'    => 'webappick-product-feed-for-woocommerce',
				'name'    => 'CTX Feed',
				'tagline' => __( 'Multi-channel product feed manager', 'webappick-pdf-invoice-for-woocommerce' ),
				'blurb'   => __( 'Create WooCommerce product feeds for Google Shopping, Facebook, TikTok and 220+ channels, kept updated automatically.', 'webappick-pdf-invoice-for-woocommerce' ),
				'file'    => 'webappick-product-feed-for-woocommerce/woo-feed.php',
				'admin'   => 'admin.php?page=webappick-manage-feeds',
				'docs'    => 'https://webappick.com/docs/woo-feed/',
				'price'   => __( 'Free · Pro available', 'webappick-pdf-invoice-for-woocommerce' ),
			),
			array(
				'slug'    => self::FREE_SLUG,
				'name'    => 'Challan',
				'tagline' => __( 'PDF invoices & packing slips', 'webappick-pdf-invoice-for-woocommerce' ),
				'blurb'   => __( 'WooCommerce PDF invoice generator with automatic email attachment. Packing slips, shipping labels, credit notes, multilingual.', 'webappick-pdf-invoice-for-woocommerce' ),
				'file'    => 'webappick-pdf-invoice-for-woocommerce/woo-invoice.php',
				'admin'   => 'admin.php?page=webappick-woo-invoice',
				'docs'    => 'https://webappick.com/docs/challan/',
				'price'   => __( 'Free · Pro from $69 / year', 'webappick-pdf-invoice-for-woocommerce' ),
			),
			array(
				'slug'    => 'disco',
				'name'    => 'Disco',
				'tagline' => __( 'Dynamic discounts & pricing rules', 'webappick-pdf-invoice-for-woocommerce' ),
				'blurb'   => __( 'WooCommerce discount rules: automatic product and cart discounts, bulk pricing, BOGO deals and dynamic pricing without coupon codes.', 'webappick-pdf-invoice-for-woocommerce' ),
				'file'    => 'disco/disco.php',
				'admin'   => 'admin.php?page=disco',
				'docs'    => 'https://webappick.com/docs/disco/',
				'price'   => __( 'Free on WordPress.org', 'webappick-pdf-invoice-for-woocommerce' ),
			),
		);

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();

		$cards = array();
		foreach ( $catalog as $entry ) {
			$info  = $this->wporg_info( $entry['slug'] );
			$icons = is_array( $info ) && ! empty( $info['icons'] ) ? $info['icons'] : array();

			$is_installed = isset( $installed[ $entry['file'] ] );
			$is_active    = $is_installed && is_plugin_active( $entry['file'] );

			$cards[] = array(
				'slug'        => $entry['slug'],
				'name'        => $entry['name'],
				'tagline'     => $entry['tagline'],
				'blurb'       => $entry['blurb'],
				'docs'        => $entry['docs'],
				'price'       => $entry['price'],
				'icon'        => (string) ( $icons['2x'] ?? $icons['1x'] ?? $icons['svg'] ?? $icons['default'] ?? '' ),
				'rating'      => is_array( $info ) ? round( ( (int) $info['rating'] ) / 20, 1 ) : null,
				'reviews'     => is_array( $info ) ? (int) $info['num_ratings'] : null,
				'installs'    => is_array( $info ) ? (int) $info['active_installs'] : null,
				'updated'     => is_array( $info ) ? human_time_diff( strtotime( (string) $info['last_updated'] ) ) : null,
				'tested'      => is_array( $info ) ? (string) $info['tested'] : null,
				'version'     => is_array( $info ) ? (string) $info['version'] : null,
				'active'      => $is_active,
				'installed'   => $is_installed,
				'admin_url'   => $is_active ? admin_url( $entry['admin'] ) : '',
				'install_url' => $is_installed
					? ''
					: wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . $entry['slug'] ), 'install-plugin_' . $entry['slug'] ),
				'wporg_url'   => 'https://wordpress.org/plugins/' . $entry['slug'] . '/',
			);
		}

		return rest_ensure_response( array( 'plugins' => $cards ) );
	}

	/**
	 * Response schema of GET /version-control.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_version_control_schema() {
		return Schema::object(
			'version-control',
			array(
				'installed'  => self::readonly(
					__( 'Installed Challan Free version (CHALLAN_VERSION).', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'type' => 'string' )
				),
				'latest'     => self::readonly(
					__( 'Latest version on WordPress.org and its release date (from the changelog heading, else wp.org\'s last_updated). The date is free text as written in the changelog.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'       => 'object',
						'properties' => array(
							'version'  => array( 'type' => 'string' ),
							'released' => array( 'type' => 'string' ),
						),
					)
				),
				'up_to_date' => self::readonly(
					__( 'Whether the installed version is the latest or newer.', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'type' => 'boolean' )
				),
				'releases'   => self::readonly(
					__( 'The 10 newest changelog entries, newest first.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'version' => array( 'type' => 'string' ),
								'date'    => array(
									'description' => __( 'Date as written in the changelog heading; empty when missing.', 'webappick-pdf-invoice-for-woocommerce' ),
									'type'        => 'string',
								),
								'entries' => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'type' => array(
												'description' => __( 'Guessed from the start of the line.', 'webappick-pdf-invoice-for-woocommerce' ),
												'type' => 'string',
												'enum' => array( 'security', 'fixed', 'new', 'removed', 'improved' ),
											),
											'text' => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
					)
				),
				'versions'   => self::readonly(
					__( 'The 12 newest versions that can be installed, newest first, with the changelog date when known (otherwise empty).', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'version' => array( 'type' => 'string' ),
								'date'    => array( 'type' => 'string' ),
							),
						),
					)
				),
			)
		);
	}

	/**
	 * Response schema of POST /version-control/install.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_install_schema() {
		return Schema::object(
			'version-control-install',
			array(
				'installed' => self::readonly(
					__( 'The version that was installed.', 'webappick-pdf-invoice-for-woocommerce' ),
					array( 'type' => 'string' )
				),
			)
		);
	}

	/**
	 * Response schema of GET /webappick-plugins.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_plugins_schema() {
		$nullable = static function ( $type, $description ) {
			return array(
				'description' => $description,
				'type'        => array( $type, 'null' ),
			);
		};

		return Schema::object(
			'webappick-plugins',
			array(
				'plugins' => self::readonly(
					__( 'One card per WebAppick plugin: CTX Feed, Challan, Disco.', 'webappick-pdf-invoice-for-woocommerce' ),
					array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'        => array( 'type' => 'string' ),
								'name'        => array( 'type' => 'string' ),
								'tagline'     => array( 'type' => 'string' ),
								'blurb'       => array( 'type' => 'string' ),
								'docs'        => array(
									'type'   => 'string',
									'format' => 'uri',
								),
								'price'       => array( 'type' => 'string' ),
								'icon'        => array(
									'description' => __( 'wp.org icon URL; empty when unknown.', 'webappick-pdf-invoice-for-woocommerce' ),
									'type'        => 'string',
								),
								'rating'      => $nullable( 'number', __( 'Average rating out of 5, one decimal; null without wp.org data.', 'webappick-pdf-invoice-for-woocommerce' ) ),
								'reviews'     => $nullable( 'integer', __( 'Number of ratings; null without wp.org data.', 'webappick-pdf-invoice-for-woocommerce' ) ),
								'installs'    => $nullable( 'integer', __( 'Active installs as rounded by wp.org; null without wp.org data.', 'webappick-pdf-invoice-for-woocommerce' ) ),
								'updated'     => $nullable( 'string', __( 'Time since the last update, e.g. "3 days"; null without wp.org data.', 'webappick-pdf-invoice-for-woocommerce' ) ),
								'tested'      => $nullable( 'string', __( 'Highest WordPress version tested; null without wp.org data.', 'webappick-pdf-invoice-for-woocommerce' ) ),
								'version'     => $nullable( 'string', __( 'Latest version on wp.org; null without wp.org data.', 'webappick-pdf-invoice-for-woocommerce' ) ),
								'active'      => array( 'type' => 'boolean' ),
								'installed'   => array( 'type' => 'boolean' ),
								'admin_url'   => array(
									'description' => __( 'The plugin\'s admin page when it is active; empty otherwise.', 'webappick-pdf-invoice-for-woocommerce' ),
									'type'        => 'string',
								),
								'install_url' => array(
									'description' => __( 'Nonce-signed install link when the plugin is not installed; empty otherwise.', 'webappick-pdf-invoice-for-woocommerce' ),
									'type'        => 'string',
								),
								'wporg_url'   => array(
									'type'   => 'string',
									'format' => 'uri',
								),
							),
						),
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
	private static function readonly( $description, array $schema ) {
		return array_merge(
			array( 'description' => $description ),
			$schema,
			array(
				'context'  => array( 'edit' ),
				'readonly' => true,
			)
		);
	}
}
