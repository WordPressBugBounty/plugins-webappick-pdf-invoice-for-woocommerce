<?php
/**
 * Plugin orchestrator.
 *
 * @package Challan
 */

namespace Challan;

use Challan\Admin\Assets;
use Challan\Admin\BulkActions;
use Challan\Admin\DashboardWidget;
use Challan\Admin\Menu;
use Challan\Admin\Notices;
use Challan\Admin\OrderActions;
use Challan\Admin\OrderMetabox;
use Challan\Admin\PluginLinks;
use Challan\Compat\LegacyAjax;
use Challan\Compat\LegacyMenus;
use Challan\Documents\DeliveryAddressRenderer;
use Challan\Documents\EmailAttachments;
use Challan\Documents\InvoiceNumber;
use Challan\Documents\InvoiceRenderer;
use Challan\Documents\PackingSlipRenderer;
use Challan\Documents\PdfEngine;
use Challan\Frontend\AccountDownloads;
use Challan\Marketing\AppServices;
use Challan\Rest\BootstrapController;
use Challan\Documents\FontDownloader;
use Challan\Rest\DocumentsController;
use Challan\Rest\FontDownloaderController;
use Challan\Rest\PreviewController;
use Challan\Rest\UpdatesController;
use Challan\Rest\SettingsController;
use Challan\Rest\SystemStatusController;
use Challan\Settings\Registry;
use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Wires the v2 modules together. Modules attach here as they are built:
 * settings-api (this file), admin-ui, documents-api.
 */
final class Plugin {

	/**
	 * The single plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

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
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private: use Plugin::instance().
	 */
	private function __construct() {
	}

	/**
	 * Boot the plugin. Called on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot() {
		// On init (first thing): the migrations read translated template
		// names, and translations must not load before init (WP 6.7+).
		add_action( 'init', array( Upgrader::class, 'maybe_upgrade' ), 0 );

		if ( is_admin() ) {
			( new Menu() )->register();
			( new Assets() )->register();
			( new OrderActions() )->register();
			( new OrderMetabox() )->register();
			( new BulkActions() )->register();
			( new Notices() )->register();
			( new PluginLinks() )->register();
			( new DashboardWidget() )->register();
			( new LegacyMenus() )->register();
		}

		( new LegacyAjax( $this->repository(), $this->renderers(), $this->engine() ) )->register();
		( new FontDownloader() )->register();

		// Email attachments, numbering and customer downloads run in every
		// context (orders/emails fire from admin, frontend, cron and API).
		$this->invoice_number()->register();
		( new \Challan\Documents\MetaFields() )->register();
		\Challan\Documents\Language::register();
		( new EmailAttachments( $this->repository(), $this->invoice_renderer(), $this->engine(), $this->invoice_number() ) )->register();
		( new AccountDownloads( $this->repository() ) )->register();
		( new AppServices() )->register();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Shared invoice renderer instance.
	 *
	 * @var InvoiceRenderer|null
	 */
	private $invoice_renderer;

	/**
	 * Shared PDF engine instance.
	 *
	 * @var PdfEngine|null
	 */
	private $engine;

	/**
	 * Shared renderer map.
	 *
	 * @var array<string,\Challan\Documents\AbstractRenderer>|null
	 */
	private $renderers;

	/**
	 * Renderer map for all built-in document types.
	 *
	 * @return array<string,\Challan\Documents\AbstractRenderer>
	 */
	public function renderers() {
		if ( null === $this->renderers ) {
			$renderers = array(
				'invoice'          => $this->invoice_renderer(),
				'packing_slip'     => new PackingSlipRenderer( $this->repository(), $this->invoice_number() ),
				'delivery_address' => new DeliveryAddressRenderer( $this->repository() ),
			);

			/**
			 * Filter the renderer map. Challan Pro adds its document types
			 * (shipping_label, credit_note) here. Every value must be an
			 * AbstractRenderer built on the GIVEN repository so the same
			 * filter also serves the live preview with overlaid settings.
			 *
			 * @param array<string,\Challan\Documents\AbstractRenderer> $renderers Renderers by type id.
			 * @param \Challan\Settings\Repository                      $repository Settings source.
			 * @param InvoiceNumber                                     $numbers    Invoice-number service.
			 */
			$this->renderers = apply_filters( 'challan_document_renderers', $renderers, $this->repository(), $this->invoice_number() );
		}
		return $this->renderers;
	}

	/**
	 * Shared invoice-number service.
	 *
	 * @var InvoiceNumber|null
	 */
	private $invoice_number;

	/**
	 * Lazily built invoice-number service.
	 *
	 * @return InvoiceNumber
	 */
	public function invoice_number() {
		if ( null === $this->invoice_number ) {
			$this->invoice_number = new InvoiceNumber( $this->repository() );
		}
		return $this->invoice_number;
	}

	/**
	 * Lazily built invoice renderer.
	 *
	 * @return InvoiceRenderer
	 */
	public function invoice_renderer() {
		if ( null === $this->invoice_renderer ) {
			$this->invoice_renderer = new InvoiceRenderer( $this->repository(), $this->invoice_number() );
		}
		return $this->invoice_renderer;
	}

	/**
	 * Lazily built PDF engine.
	 *
	 * @return PdfEngine
	 */
	public function engine() {
		if ( null === $this->engine ) {
			$this->engine = new PdfEngine( $this->repository() );
		}
		return $this->engine;
	}

	/**
	 * Lazily built settings registry (built after plugins_loaded so pro
	 * can attach its `challan_settings_fields` filter first).
	 *
	 * @return Registry
	 */
	public function registry() {
		if ( null === $this->registry ) {
			$this->registry = new Registry();
		}
		return $this->registry;
	}

	/**
	 * Settings repository.
	 *
	 * @return Repository
	 */
	public function repository() {
		if ( null === $this->repository ) {
			$this->repository = new Repository( $this->registry() );
		}
		return $this->repository;
	}

	/**
	 * Register all challan/v1 REST controllers.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$repository = $this->repository();

		$controllers = array(
			new BootstrapController( $this->registry(), $repository ),
			new SettingsController( $repository, $this->registry() ),
			new SystemStatusController(),
			new DocumentsController( $this->renderers(), $this->engine(), $repository ),
			new FontDownloaderController(),
			new PreviewController( $this->registry() ),
			new UpdatesController(),
			new \Challan\Rest\CustomersController(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
