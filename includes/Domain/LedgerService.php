<?php

namespace GRN\GateQris\Domain;

use GRN\GateQris\Repository\LedgerRepository;
use GRN\GateQris\Repository\WalletRepository;

defined( 'ABSPATH' ) || exit;

final class LedgerService {
	public function __construct(
		private readonly LedgerRepository $ledger,
		private readonly WalletRepository $wallets
	) {}

	public function credit_wallet( array $wallet, int $transaction_id, int $amount, string $entry_type, string $reference_id, string $note = '' ): void {
		$this->record_entry( $wallet, $transaction_id, $amount, $entry_type, 'credit', 'transaction', $reference_id, $note, 0 );
		$this->wallets->increment_balance( (int) $wallet['id'], $amount );
	}

	public function adjust_wallet( array $wallet, int $amount, string $direction, string $reference_id, string $note, int $actor_user_id ): void {
		$reference_type = 'wallet_adjustment';
		$entry_type     = 'credit' === $direction ? 'manual_adjustment_credit' : 'manual_adjustment_debit';

		// Mutate the balance first; only record the ledger entry once the mutation
		// has succeeded. A failed debit therefore cannot leave an orphan ledger row.
		if ( 'credit' === $direction ) {
			$this->wallets->increment_balance( (int) $wallet['id'], $amount );
		} elseif ( ! $this->wallets->decrement_balance( (int) $wallet['id'], $amount ) ) {
			throw new \RuntimeException( 'Wallet balance could not be decremented.' );
		}

		$this->record_entry( $wallet, 0, $amount, $entry_type, $direction, $reference_type, $reference_id, $note, $actor_user_id );
	}

	public function get_by_transaction_and_type( int $transaction_id, string $entry_type ): ?array {
		return $this->ledger->get_by_transaction_and_type( $transaction_id, $entry_type );
	}

	/**
	 * Write a single ledger row without mutating any balance.
	 *
	 * Used by callers that already mutate the balance themselves inside a DB
	 * transaction (e.g. WalletTransferService). $wallet must be the row read just
	 * before the mutation, so balance_before/after are computed authoritatively.
	 */
	public function record( array $wallet, int $transaction_id, int $amount, string $entry_type, string $direction, string $reference_type, string $reference_id, string $note = '', int $actor_user_id = 0 ): void {
		$this->record_entry( $wallet, $transaction_id, $amount, $entry_type, $direction, $reference_type, $reference_id, $note, $actor_user_id );
	}

	private function record_entry( array $wallet, int $transaction_id, int $amount, string $entry_type, string $direction, string $reference_type, string $reference_id, string $note, int $actor_user_id ): void {
		$before = (int) $wallet['available_balance'];
		$after  = 'credit' === $direction ? $before + $amount : $before - $amount;
		$now    = gmdate( 'Y-m-d H:i:s' );

		$this->ledger->create(
			array(
				'entry_uuid'        => wp_generate_uuid4(),
				'transaction_id'    => $transaction_id,
				'wallet_account_id' => (int) $wallet['id'],
				'actor_user_id'     => $actor_user_id,
				'entry_type'        => $entry_type,
				'direction'         => $direction,
				'amount'            => $amount,
				'currency'          => 'IDR',
				'balance_before'    => $before,
				'balance_after'     => $after,
				'reference_type'    => $reference_type,
				'reference_id'      => $reference_id,
				'note'              => $note,
				'created_at_gmt'    => $now,
			)
		);
	}
}
