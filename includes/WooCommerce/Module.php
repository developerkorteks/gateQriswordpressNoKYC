<?php
/**
 * WooCommerce module bootstrap.
 *
 * Wires the WooCommerce payment gateway into the GateQRIS Payments core.
 * Loaded conditionally by the core Bootstrap only when WooCommerce is active.
 *
 * @package GRN\GateQris\WooCommerce
 */

namespace GRN\GateQris\WooCommerce;

use GRN\GateQris\Domain\TransactionService;
use GRN\GateQris\Domain\WalletService;
use GRN\GateQris\Domain\WalletTransferService;
use GRN\GateQris\Repository\TransactionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Module — registers the gateway, form-meta filter and webhook handler.
 *
 * Unlike the previous standalone bridge plugin, this module receives the core
 * service instances by dependency injection (no cross-plugin REST calls and no
 * raw SQL against the gq_transactions table).
 */
final class Module {

	private static ?Module $instance = null;

	private TransactionService $transaction_service;

	private TransactionRepository $transactions;

	private WalletService $wallet_service;

	private WalletTransferService $wallet_transfer;

	private function __construct( TransactionService $transaction_service, TransactionRepository $transactions, WalletService $wallet_service, WalletTransferService $wallet_transfer ) {
		$this->transaction_service = $transaction_service;
		$this->transactions        = $transactions;
		$this->wallet_service      = $wallet_service;
		$this->wallet_transfer     = $wallet_transfer;
	}

	/**
	 * Initialise the WooCommerce module with injected core services.
	 */
	public static function init( TransactionService $transaction_service, TransactionRepository $transactions, WalletService $wallet_service, WalletTransferService $wallet_transfer ): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self( $transaction_service, $transactions, $wallet_service, $wallet_transfer );
		self::$instance->register();
	}

	public static function instance(): ?Module {
		return self::$instance;
	}

	public function transaction_service(): TransactionService {
		return $this->transaction_service;
	}

	public function transactions(): TransactionRepository {
		return $this->transactions;
	}

	public function wallet_service(): WalletService {
		return $this->wallet_service;
	}

	public function wallet_transfer(): WalletTransferService {
		return $this->wallet_transfer;
	}

	private function register(): void {
		// Register the WooCommerce payment gateways (QRIS + pay-with-balance).
		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = Gateway::class;
				$gateways[] = BalanceGateway::class;
				return $gateways;
			}
		);

		// Inject the WooCommerce order id (+ success redirect URL) into the
		// transaction metadata when an invoice is created from checkout.
		FormFilter::init();

		// Mark the WooCommerce order complete when its transaction is paid.
		WebhookHandler::init( $this->transactions );

		// Return wallet balance if a balance-paid order is cancelled/failed/refunded.
		BalanceRefundHandler::init( $this->wallet_service, $this->wallet_transfer );

		// Load the gateway translations alongside the core text domain.
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'gateqris-payments',
			false,
			dirname( plugin_basename( GATEQRIS_PAYMENTS_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
