<?php

namespace GRN\GateQris\Domain;

use GRN\GateQris\Repository\WalletRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WalletAdjustmentService {
	public function __construct(
		private readonly WalletRepository $wallets,
		private readonly LedgerService $ledger
	) {}

	public function adjust( int $wallet_id, string $direction, int $amount, string $reason, int $actor_user_id ): array|WP_Error {
		$direction = in_array( $direction, array( 'credit', 'debit' ), true ) ? $direction : '';
		if ( '' === $direction ) {
			return new WP_Error( 'gateqris_invalid_adjustment_direction', __( 'Adjustment direction is invalid.', 'gateqris-payments' ) );
		}

		if ( $amount <= 0 ) {
			return new WP_Error( 'gateqris_invalid_adjustment_amount', __( 'Adjustment amount must be a positive integer.', 'gateqris-payments' ) );
		}

		$note = sprintf(
			/* translators: 1: admin user ID, 2: adjustment reason */
			__( 'Manual wallet adjustment by admin #%1$d. Reason: %2$s', 'gateqris-payments' ),
			$actor_user_id,
			$reason
		);

		// Lock the wallet row and apply the ledger entry + balance mutation
		// atomically, so a concurrent adjustment cannot corrupt the balance or the
		// ledger audit trail.
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wallet = $this->wallets->get_for_update( $wallet_id );
			if ( ! $wallet ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'gateqris_wallet_not_found', __( 'Wallet not found.', 'gateqris-payments' ) );
			}

			if ( 'debit' === $direction && (int) $wallet['available_balance'] < $amount ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'gateqris_insufficient_wallet_balance', __( 'Wallet balance is insufficient for this debit adjustment.', 'gateqris-payments' ) );
			}

			$this->ledger->adjust_wallet(
				$wallet,
				$amount,
				$direction,
				wp_generate_uuid4(),
				$note,
				$actor_user_id
			);

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'gateqris_wallet_adjustment_failed', __( 'Wallet adjustment could not be completed.', 'gateqris-payments' ) );
		}

		$updated_wallet = $this->wallets->get_by_id( $wallet_id );
		if ( ! $updated_wallet ) {
			return new WP_Error( 'gateqris_wallet_refresh_failed', __( 'Adjusted wallet could not be reloaded.', 'gateqris-payments' ) );
		}

		do_action( 'gateqris_payments_wallet_adjusted', $updated_wallet, $direction, $amount, $reason, $actor_user_id );

		return $updated_wallet;
	}
}
