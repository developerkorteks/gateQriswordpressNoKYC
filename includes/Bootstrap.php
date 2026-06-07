<?php

namespace GRN\GateQris;

use GRN\GateQris\Admin\Console;
use GRN\GateQris\Admin\Menu;
use GRN\GateQris\API\Client;
use GRN\GateQris\API\Signer;
use GRN\GateQris\API\WebhookVerifier;
use GRN\GateQris\Config\Settings;
use GRN\GateQris\Database\Migrations;
use GRN\GateQris\Domain\IdempotencyService;
use GRN\GateQris\Domain\LedgerService;
use GRN\GateQris\Domain\SettlementService;
use GRN\GateQris\Domain\StatusMachine;
use GRN\GateQris\Domain\TransactionService;
use GRN\GateQris\Domain\WalletAdjustmentService;
use GRN\GateQris\Domain\WalletService;
use GRN\GateQris\Domain\WalletTransferService;
use GRN\GateQris\Frontend\Shortcodes;
use GRN\GateQris\Jobs\PollPendingTransactionsJob;
use GRN\GateQris\Repository\IdempotencyRepository;
use GRN\GateQris\Repository\LedgerRepository;
use GRN\GateQris\Repository\SettlementRepository;
use GRN\GateQris\Repository\TransactionRepository;
use GRN\GateQris\Repository\WalletRepository;
use GRN\GateQris\Repository\WebhookEventRepository;
use GRN\GateQris\REST\TransactionsController;
use GRN\GateQris\REST\WebhookController;
use GRN\GateQris\Support\Logger;
use GRN\GateQris\WooCommerce\Module;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	private static ?Bootstrap $instance = null;

	private Logger $logger;

	private Settings $settings;

	private Migrations $migrations;

	private TransactionService $transaction_service;

	private TransactionRepository $transactions;

	private WalletService $wallet_service;

	private WalletTransferService $wallet_transfer;

	private Menu $admin_menu;

	private Console $admin_console;

	private function __construct() {
		$this->logger     = new Logger();
		$this->settings   = new Settings();
		$this->migrations = new Migrations( $this->logger );

		$this->transactions = new TransactionRepository();
		$transactions       = $this->transactions;
		$idempotency  = new IdempotencyRepository();
		$wallets      = new WalletRepository();
		$settlements  = new SettlementRepository();
		$ledger       = new LedgerRepository();
		$webhooks     = new WebhookEventRepository();
		$signer       = new Signer();

		$wallet_service     = new WalletService( $wallets, $this->settings );
		$this->wallet_service = $wallet_service;
		$ledger_service     = new LedgerService( $ledger, $wallets );
		$this->wallet_transfer = new WalletTransferService( $wallets, $ledger_service, $this->logger );
		$wallet_adjustments = new WalletAdjustmentService( $wallets, $ledger_service );
		$settlement_service = new SettlementService( $settlements, $transactions, $wallet_service, $ledger_service, $this->logger );
		$this->transaction_service = new TransactionService(
			$transactions,
			new IdempotencyService( $idempotency, $this->logger ),
			new Client( $this->settings, $signer, $this->logger ),
			$this->settings,
			new StatusMachine(),
			$settlement_service,
			$this->logger
		);

		$this->admin_menu    = new Menu( $this->settings );
		$this->admin_console = new Console( $transactions, $wallets, $settlements, $webhooks, $ledger, $this->transaction_service, $wallet_adjustments, $wallet_service );

		add_action( 'rest_api_init', array( new TransactionsController( $this->transaction_service, $transactions ), 'register' ) );
		add_action( 'rest_api_init', array( new WebhookController( $this->settings, new WebhookVerifier( $signer ), $webhooks, $transactions, $this->transaction_service ), 'register' ) );
		add_action( 'init', array( new Shortcodes( $transactions, $this->settings ), 'register' ) );
		add_action( 'init', array( new PollPendingTransactionsJob( $transactions, $this->transaction_service, $this->logger ), 'register' ) );
		add_action( 'user_register', array( $this, 'maybe_provision_user_wallet' ), 20 );
		$this->admin_console->register_post_actions();
	}

	public static function instance(): Bootstrap {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->migrations->needs_migration() ) {
			$this->migrations->migrate();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this->admin_menu, 'register' ), 9 );
		add_action( 'admin_menu', array( $this->admin_console, 'register' ), 10 );
		add_action( 'admin_notices', array( $this, 'maybe_render_missing_credentials_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GATEQRIS_PAYMENTS_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );

		// Declare HPOS compatibility before WooCommerce initialises.
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		// Boot the WooCommerce gateway module once all plugins are loaded.
		add_action( 'plugins_loaded', array( $this, 'boot_woocommerce' ), 20 );

		// Weekly cleanup of old idempotency keys and webhook events.
		add_action( 'gateqris_payments_cleanup', array( $this, 'run_cleanup' ) );
		if ( ! wp_next_scheduled( 'gateqris_payments_cleanup' ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', 'gateqris_payments_cleanup' );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'gateqris-payments', false, dirname( plugin_basename( GATEQRIS_PAYMENTS_PLUGIN_FILE ) ) . '/languages' );
	}

	public function maybe_render_missing_credentials_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->settings->has_credentials() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! str_starts_with( $screen->id, 'toplevel_page_gateqris-payments' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'GateQRIS Payments is active, but API credentials are not configured yet.', 'gateqris-payments' )
		);
	}

	public static function activate(): void {
		$instance = self::instance();
		$instance->migrations->migrate();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'gateqris_payments_poll_pending' );
		wp_clear_scheduled_hook( 'gateqris_payments_cleanup' );
	}

	/**
	 * Delete idempotency keys older than 30 days and webhook events older than 90 days.
	 * Runs weekly via WP-Cron and can also be triggered manually from the Tools page.
	 */
	public function run_cleanup(): void {
		global $wpdb;

		$cutoff_idempotency = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$deleted_idempotency = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}gq_idempotency_keys WHERE created_at_gmt < %s LIMIT 1000",
				$cutoff_idempotency
			)
		);

		$cutoff_webhooks = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$deleted_webhooks = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}gq_webhook_events WHERE created_at_gmt < %s LIMIT 1000",
				$cutoff_webhooks
			)
		);

		$this->logger->info(
			'GateQRIS cleanup completed',
			array(
				'deleted_idempotency_keys' => $deleted_idempotency,
				'deleted_webhook_events'   => $deleted_webhooks,
			)
		);
	}

	public function plugin_action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=gateqris-payments' ) ),
				esc_html__( 'Settings', 'gateqris-payments' )
			)
		);

		return $links;
	}

	public function declare_hpos_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				GATEQRIS_PAYMENTS_PLUGIN_FILE,
				true
			);
		}
	}

	public function boot_woocommerce(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		Module::init( $this->transaction_service, $this->transactions, $this->wallet_service, $this->wallet_transfer );
	}

	public function maybe_provision_user_wallet( int $user_id ): void {
		if ( 'yes' !== (string) $this->settings->get( 'allow_user_wallets', 'yes' ) ) {
			return;
		}

		if ( 'yes' !== (string) $this->settings->get( 'auto_create_user_wallets', 'yes' ) ) {
			return;
		}

		$result = $this->wallet_service->provision_user_wallet( $user_id );
		if ( is_wp_error( $result ) ) {
			$this->logger->warning(
				'User wallet auto-provision failed',
				array(
					'user_id' => $user_id,
					'message' => $result->get_error_message(),
				)
			);
			return;
		}

		$this->logger->info(
			'User wallet auto-provisioned',
			array(
				'user_id'   => $user_id,
				'wallet_id' => (int) ( $result['id'] ?? 0 ),
			)
		);
	}
}
