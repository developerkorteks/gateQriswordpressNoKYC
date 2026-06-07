<?php

namespace GRN\GateQris\Domain;

use GRN\GateQris\Repository\SettlementRepository;
use GRN\GateQris\Repository\TransactionRepository;
use GRN\GateQris\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class SettlementService {
	public function __construct(
		private readonly SettlementRepository $settlements,
		private readonly TransactionRepository $transactions,
		private readonly WalletService $wallets,
		private readonly LedgerService $ledger,
		private readonly Logger $logger
	) {}

	public function settle( array $transaction ): array {
		$lock_token = wp_generate_password( 32, false, false );
		$locked     = $this->transactions->acquire_processing_lock( (int) $transaction['id'], $lock_token, 45 );
		if ( ! $locked ) {
			$existing = $this->settlements->get_by_transaction_id( (int) $transaction['id'] );
			if ( $existing ) {
				return $existing;
			}

			throw new \RuntimeException( 'Settlement lock could not be acquired.' );
		}

		try {
			$transaction = $this->transactions->get_by_id( (int) $transaction['id'] ) ?? $transaction;
			$existing    = $this->settlements->get_by_transaction_id( (int) $transaction['id'] );
			if ( $existing && 'completed' === (string) $existing['status'] ) {
				return $existing;
			}

			$wallet = $this->wallets->resolve_wallet( (string) $transaction['wallet_owner_type'], (int) $transaction['wallet_owner_id'] );
			if ( ! $wallet ) {
				throw new \RuntimeException( 'Settlement wallet could not be resolved.' );
			}

			$amount      = (int) $transaction['base_amount'];
			$unique_code = max( 0, (int) $transaction['total_amount'] - (int) $transaction['base_amount'] );
			if ( $amount <= 0 ) {
				throw new \RuntimeException( 'Settlement amount must be positive.' );
			}

			// --- BEGIN atomic money mutation ---
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );
			try {
				// Re-read the wallet under a row lock so concurrent settlements to
				// the same wallet serialize and the ledger balance_before/after are
				// computed from an authoritative balance.
				$locked_wallet = $this->wallets->get_for_update( (int) $wallet['id'] );
				if ( $locked_wallet ) {
					$wallet = $locked_wallet;
				}

				$now = gmdate( 'Y-m-d H:i:s' );
				if ( ! $existing ) {
					$created = $this->settlements->create(
						array(
							'settlement_uuid'   => wp_generate_uuid4(),
							'transaction_id'    => (int) $transaction['id'],
							'wallet_account_id' => (int) $wallet['id'],
							'gross_amount'      => $amount,
							'fee_amount'        => 0,
							'net_amount'        => $amount,
							'status'            => 'pending',
							'confirmation_type' => (string) $transaction['payment_confirmation_type'],
							'created_at_gmt'    => $now,
							'settled_at_gmt'    => null,
						)
					);
					if ( $created <= 0 ) {
						$existing = $this->settlements->get_by_transaction_id( (int) $transaction['id'] );
						if ( ! $existing ) {
							throw new \RuntimeException( 'Settlement record could not be created.' );
						}
					} else {
						$existing = $this->settlements->get_by_transaction_id( (int) $transaction['id'] );
					}
				}

				$ledger_entry = $this->ledger->get_by_transaction_and_type( (int) $transaction['id'], 'payment_settled_credit' );
				if ( ! $ledger_entry ) {
					$this->ledger->credit_wallet(
						$wallet,
						(int) $transaction['id'],
						$amount,
						'payment_settled_credit',
						(string) $transaction['uuid'],
						$unique_code > 0
							? sprintf( 'GateQRIS settlement (base amount only, unique code excluded: %d)', $unique_code )
							: 'GateQRIS settlement'
					);
				}

				if ( $existing && 'completed' !== (string) $existing['status'] ) {
					$this->settlements->update(
						(int) $existing['id'],
						array(
							'status'         => 'completed',
							'settled_at_gmt' => $now,
						)
					);
				}

				$this->transactions->update(
					(int) $transaction['id'],
					array(
						'wallet_account_id' => (int) $wallet['id'],
						'internal_status'   => 'settled',
						'settled_at_gmt'    => $now,
						'updated_at_gmt'    => $now,
					)
				);

				$wpdb->query( 'COMMIT' );
			} catch ( \Throwable $db_error ) {
				$wpdb->query( 'ROLLBACK' );
				throw $db_error;
			}
			// --- END atomic money mutation ---

			do_action( 'gateqris_payments_transaction_settled', $transaction, $wallet );
			$this->logger->info( 'Transaction settled', array( 'transaction_id' => (int) $transaction['id'] ) );

			return $this->settlements->get_by_transaction_id( (int) $transaction['id'] ) ?? array();
		} finally {
			$this->transactions->release_processing_lock( (int) $transaction['id'], $lock_token );
		}
	}
}
