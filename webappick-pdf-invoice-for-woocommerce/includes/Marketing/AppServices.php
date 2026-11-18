<?php
/**
 * WebAppick AppServices integration.
 *
 * @package Challan
 */

namespace Challan\Marketing;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Boots the vendored WebAppick AppServices client (libs/WebAppick):
 * opt-in usage insights, the deactivation-feedback modal, the
 * support-ticket form and remote promo banners — same project identity
 * as v1 ('Woo Invoice Free'), so existing opt-in state carries over.
 *
 * The library itself is WebAppick's shared cross-product client,
 * vendored verbatim; this wrapper is fresh v2 code.
 */
class AppServices {

	const PROJECT_UUID = '28261c3c-793e-4c74-bf7f-2837b8c6ddb4';

	/**
	 * The booted insights service, for the opt-in bridge below.
	 *
	 * @var object|null
	 */
	private static $insights = null;

	/**
	 * Hook in — v1 initialized on init priority 1.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'boot' ), 1 );

		// v1 bridge: saving the Send Debug Info toggle opts the insights
		// client in/out (wpifw_data_collection is also stored as a bool).
		$sync = array( __CLASS__, 'sync_opt_in' );
		add_action(
			'update_option_wpifw_data_collection',
			static function ( $old, $value ) use ( $sync ) {
				call_user_func( $sync, $value );
			},
			10,
			2 
		);
		add_action(
			'add_option_wpifw_data_collection',
			static function ( $name, $value ) use ( $sync ) {
				call_user_func( $sync, $value );
			},
			10,
			2 
		);
	}

	/**
	 * Push the toggle state into the insights client (v1 called
	 * trackerOptIn/trackerOptOut on settings save).
	 *
	 * @param mixed $value New option value.
	 * @return void
	 */
	public static function sync_opt_in( $value ) {
		if ( null === self::$insights ) {
			return;
		}
		if ( ! empty( $value ) && '0' !== $value ) {
			self::$insights->optIn( true );
		} else {
			self::$insights->optOut();
		}
	}

	/**
	 * Instantiate and initialize the client.
	 *
	 * @return void
	 */
	public function boot() {
		if ( ! class_exists( \Challan\AppServices\Client::class ) ) {
			$client_file = CHALLAN_PATH . 'libs/WebAppick/AppServices/Client.php';
			if ( ! file_exists( $client_file ) ) {
				return;
			}
			include_once $client_file;
		}

		$client = new \Challan\AppServices\Client( self::PROJECT_UUID, 'Woo Invoice Free', CHALLAN_ROOT_FILE );

		$insights = $client->insights();
		$insights->add_extra(
			array(
				'products'   => $insights->get_post_count( 'product' ),
				'variations' => $insights->get_post_count( 'product_variation' ),
				'orders'     => $insights->get_post_count( 'order' ),
			)
		);

		$slug = $client->getSlug();
		add_filter(
			"WebAppick_{$slug}_Support_Ticket_Recipient_Email",
			static function () {
				return 'support@webappick.com';
			}
		);
		add_filter(
			"WebAppick_{$slug}_Support_Page_URL",
			static function () {
				return 'https://webappick.com/support/';
			}
		);

		$insights->init();
		self::$insights = $insights;

		// Promo banners stay off unless a feed is configured (v1 3.7.91:
		// the old feed URL is gone, CHAL-516).
		$source = (string) apply_filters( 'challan_promotions_source_url', '' );
		if ( '' !== $source ) {
			$promotions = $client->promotions();
			$promotions->set_source( $source );
			$promotions->init();
		}
	}
}
