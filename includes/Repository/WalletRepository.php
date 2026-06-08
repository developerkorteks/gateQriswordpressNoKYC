<?php

namespace GRN\GateQris\Repository;

defined( 'ABSPATH' ) || exit;

final class WalletRepository {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'gq_wallet_accounts';
	}

	public function get_by_owner( string $owner_type, int $owner_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE owner_type = %s AND owner_id = %d AND currency = %s LIMIT 1",
				$owner_type,
				$owner_id,
				'IDR'
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public function get_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Read a wallet row with a row-level write lock.
	 *
	 * Must be called inside a transaction (START TRANSACTION). Concurrent callers
	 * locking the same wallet block until the holder commits, so the returned
	 * balance is authoritative for ledger balance_before/after calculations.
	 */
	public function get_for_update( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function create( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->table, $data );
		return (int) $wpdb->insert_id;
	}

	public function increment_balance( int $wallet_id, int $amount ): bool {
		global $wpdb;
		$query = $wpdb->prepare(
			"UPDATE {$this->table}
			SET available_balance = available_balance + %d, updated_at_gmt = %s
			WHERE id = %d",
			$amount,
			gmdate( 'Y-m-d H:i:s' ),
			$wallet_id
		);
		return false !== $wpdb->query( $query );
	}

	public function decrement_balance( int $wallet_id, int $amount ): bool {
		global $wpdb;

		$query = $wpdb->prepare(
			"UPDATE {$this->table}
			SET available_balance = available_balance - %d, updated_at_gmt = %s
			WHERE id = %d AND available_balance >= %d",
			$amount,
			gmdate( 'Y-m-d H:i:s' ),
			$wallet_id,
			$amount
		);

		return 1 === (int) $wpdb->query( $query );
	}

	public function all( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}
}
