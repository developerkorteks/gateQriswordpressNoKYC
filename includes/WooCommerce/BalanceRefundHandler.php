<?php
/**
 * Returns wallet balance to the customer when a balance-paid order is voided.
 *
 * @package GRN\GateQris\WooCommerce
 */

namespace GRN\GateQris\WooCommerce;

use GRN\GateQris\Domain\WalletService;
use GRN\GateQris\Domain\WalletTransferService;

defined( 'ABSPATH' ) || exit;

/**
 * BalanceRefundHandler — when an order that was paid with wallet balance moves to
 * cancelled / failed / refunded, transfers the full amount back from the site
 * wallet to the customer's wallet. Idempotent via an order meta guard; the site
 * wallet is never allowed to go negative (a failed refund is flagged for the admin).
 */
final class BalanceRefundHandler {

	private static ?WalletService $wallet_service = null;

	private static ?WalletTransferService $wallet_transfer = null;

	public static function init( WalletService $wallet_service, WalletTransferService $wallet_transfer ): void {
		self::$wallet_service  = $wallet_service;
		self::$wallet_transfer = $wallet_transfer;

		foreach ( array( 'cancelled', 'failed', 'refunded' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( self::class, 'maybe_refund' ), 10, 1 );
		}
	}

	public static function maybe_refund( int $order_id ): void {
		if ( null === self::$wallet_transfer || null === self::$wallet_service ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$amount = (int) $order->get_meta( '_gateqris_balance_paid' );
		if ( $amount <= 0 ) {
			return; // Not paid with wallet balance.
		}

		// Idempotent: never refund the same order twice, even if several status
		// hooks fire in sequence (e.g. failed then cancelled).
		if ( $order->get_meta( '_gateqris_balance_refunded' ) ) {
			return;
		}

		$user_id = (int) $order->get_meta( '_gateqris_balance_payer' );
		if ( $user_id <= 0 ) {
			$user_id = (int) $order->get_user_id();
		}
		if ( $user_id <= 0 ) {
			return;
		}

		$user_wallet = self::$wallet_service->resolve_wallet( 'user', $user_id );
		$site_wallet = self::$wallet_service->resolve_wallet( 'site', 0 );
		if ( ! $user_wallet || ! $site_wallet ) {
			return;
		}

		$result = self::$wallet_transfer->transfer(
			(int) $site_wallet['id'],
			(int) $user_wallet['id'],
			$amount,
			'refund_debit',
			'refund_credit',
			'wc_order_refund',
			(string) $order_id,
			sprintf(
				/* translators: 1: order id, 2: order status */
				__( 'Refund saldo order #%1$d (status %2$s)', 'gateqris-payments' ),
				$order_id,
				$order->get_status()
			),
			$user_id
		);

		if ( is_wp_error( $result ) ) {
			// Do NOT set the guard, so the refund can still be retried/handled manually.
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Refund saldo GAGAL (%s). Saldo site mungkin tidak cukup — tangani manual lewat Wallets.', 'gateqris-payments' ),
					$result->get_error_message()
				)
			);
			return;
		}

		$order->update_meta_data( '_gateqris_balance_refunded', 1 );
		$order->add_order_note(
			sprintf(
				/* translators: %s: formatted amount */
				__( 'Saldo Rp %s dikembalikan ke wallet pelanggan.', 'gateqris-payments' ),
				number_format_i18n( $amount )
			)
		);
		$order->save();
	}
}
