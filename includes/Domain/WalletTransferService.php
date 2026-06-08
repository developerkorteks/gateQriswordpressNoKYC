<?php

namespace GRN\GateQris\Domain;

use GRN\GateQris\Repository\WalletRepository;
use GRN\GateQris\Support\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * WalletTransferService — moves balance between two wallets atomically.
 *
 * Used to spend a user's wallet balance on a purchase: the buyer's wallet is
 * debited and the site wallet is credited in a single DB transaction, with a
 * ledger entry written on both sides. Reuses the row-lock + atomic-mutation
 * pattern established by SettlementService.
 */
final class WalletTransferService {
	public function __construct(
		private readonly WalletRepository $wallets,
		private readonly LedgerService $ledger,
		private readonly Logger $logger
	) {}

	/**
	 * Transfer $amount from one wallet to another.
	 *
	 * @return true|WP_Error True on success, WP_Error on validation/insufficient funds.
	 */
	public function transfer(
		int $from_wallet_id,
		int $to_wallet_id,
		int $amount,
		string $entry_type_out,
		string $entry_type_in,
		string $reference_type,
		string $reference_id,
		string $note = '',
		int $actor_user_id = 0
	): bool|WP_Error {
		if ( $amount <= 0 ) {
			return new WP_Error( 'gateqris_transfer_invalid_amount', __( 'Transfer amount must be a positive integer.', 'gateqris-payments' ) );
		}
		if ( $from_wallet_id === $to_wallet_id ) {
			return new WP_Error( 'gateqris_transfer_same_wallet', __( 'Source and destination wallet must differ.', 'gateqris-payments' ) );
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			// Lock both rows in a deterministic (ascending id) order to avoid deadlocks.
			foreach ( array( min( $from_wallet_id, $to_wallet_id ), max( $from_wallet_id, $to_wallet_id ) ) as $lock_id ) {
				$this->wallets->get_for_update( $lock_id );
			}

			$from = $this->wallets->get_by_id( $from_wallet_id );
			$to   = $this->wallets->get_by_id( $to_wallet_id );
			if ( ! $from || ! $to ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'gateqris_transfer_wallet_missing', __( 'A wallet involved in the transfer was not found.', 'gateqris-payments' ) );
			}

			if ( (int) $from['available_balance'] < $amount ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'gateqris_transfer_insufficient', __( 'Insufficient wallet balance.', 'gateqris-payments' ) );
			}

			// Mutate first; the locked pre-mutation snapshots ($from/$to) give the
			// ledger correct balance_before/after values.
			if ( ! $this->wallets->decrement_balance( $from_wallet_id, $amount ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'gateqris_transfer_debit_failed', __( 'Wallet could not be debited.', 'gateqris-payments' ) );
			}
			$this->wallets->increment_balance( $to_wallet_id, $amount );

			$this->ledger->record( $from, 0, $amount, $entry_type_out, 'debit', $reference_type, $reference_id, $note, $actor_user_id );
			$this->ledger->record( $to, 0, $amount, $entry_type_in, 'credit', $reference_type, $reference_id, $note, $actor_user_id );

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$this->logger->error( 'Wallet transfer failed', array( 'message' => $e->getMessage(), 'from' => $from_wallet_id, 'to' => $to_wallet_id ) );
			return new WP_Error( 'gateqris_transfer_failed', __( 'Wallet transfer could not be completed.', 'gateqris-payments' ) );
		}

		do_action( 'gateqris_payments_wallet_transferred', $from_wallet_id, $to_wallet_id, $amount, $reference_type, $reference_id );
		$this->logger->info( 'Wallet transfer completed', array( 'from' => $from_wallet_id, 'to' => $to_wallet_id, 'amount' => $amount, 'ref' => $reference_type . ':' . $reference_id ) );

		return true;
	}
}
