<?php
/**
 * Plugin singleton.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit;

use LicenseKit\Admin\Menu as AdminMenu;
use LicenseKit\EDD\Bridge as EddBridge;
use LicenseKit\EDD\CustomerDashboard as EddCustomerDashboard;
use LicenseKit\EDD\DownloadMetaBox as EddDownloadMetaBox;
use LicenseKit\EDD\ReceiptIntegration as EddReceiptIntegration;
use LicenseKit\EDD\Subscriptions as EddSubscriptions;
use LicenseKit\WooCommerce\Bridge as WcBridge;
use LicenseKit\WooCommerce\EmailIntegration as WcEmailIntegration;
use LicenseKit\WooCommerce\MyAccountIntegration as WcMyAccountIntegration;
use LicenseKit\WooCommerce\ProductSettings as WcProductSettings;
use LicenseKit\WooCommerce\Subscriptions as WcSubscriptions;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\REST\RouteRegistrar;
use LicenseKit\Schema\Migrator;
use LicenseKit\Services\LicenseService;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\WebhookDispatcher;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Support\Cron;
use LicenseKit\Support\I18n;
use LicenseKit\Support\Privacy;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Migrator::maybe_upgrade();
		// Self-heal missing secrets (e.g. when a new secret slot was added in a
		// version bump that already advanced DB_VERSION on a prior page load).
		Activator::bootstrap_secrets();
		I18n::register();
		( new RouteRegistrar() )->register();
		( new AdminMenu() )->register();
		WebhookDispatcher::make()->register();
		( new Privacy() )->register();
		( new Cron() )->register();

		// E-commerce bridges — only attach when their host plugin is active.
		add_action( 'plugins_loaded', [ $this, 'maybe_register_edd' ], 20 );
		add_action( 'plugins_loaded', [ $this, 'maybe_register_woocommerce' ], 20 );

		// Customer dashboard works regardless of which e-commerce backend is active.
		add_action( 'plugins_loaded', [ $this, 'register_customer_dashboard' ], 25 );
	}

	public function maybe_register_edd(): void {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			return;
		}

		$bridge = EddBridge::make();
		$bridge->register();

		( new EddDownloadMetaBox() )->register();
		( new EddReceiptIntegration( $bridge ) )->register();

		$audit       = new AuditLogger( new LogRepository() );
		$licenses    = new LicenseRepository();
		$products    = new ProductRepository();
		$activations = new ActivationRepository();
		$svc         = new LicenseService( $licenses, $products, $activations, $audit );

		( new EddSubscriptions( $svc, $licenses ) )->register();
	}

	public function maybe_register_woocommerce(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$bridge = WcBridge::make();
		$bridge->register();

		( new WcProductSettings() )->register();
		( new WcEmailIntegration( $bridge ) )->register();
		( new WcMyAccountIntegration() )->register();

		$audit    = new AuditLogger( new LogRepository() );
		$licenses = new LicenseRepository();
		$products = new ProductRepository();
		$activs   = new ActivationRepository();
		$svc      = new LicenseService( $licenses, $products, $activs, $audit );

		( new WcSubscriptions( $svc, $licenses ) )->register();
	}

	public function register_customer_dashboard(): void {
		// The dashboard renders for any logged-in user with licenses; it falls
		// through gracefully when neither EDD nor WC is installed.
		$licenses    = new LicenseRepository();
		$products    = new ProductRepository();
		$activations = new ActivationRepository();
		( new EddCustomerDashboard( $licenses, $products, $activations ) )->register();
	}
}
