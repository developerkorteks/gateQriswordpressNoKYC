<?php

namespace GRN\GateQris\Repository;

defined( 'ABSPATH' ) || exit;

final class LedgerRepository {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'gq_ledger_entries';
	}

	public function create( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->table, $data );
		return (int) $wpdb->insert_id;
	}

	public function get_by_transaction_and_type( int $transaction_id, string $entry_type ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE transaction_id = %d AND entry_type = %s ORDER BY id DESC LIMIT 1",
				$transaction_id,
				$entry_type
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function for_wallet( int $wallet_id, int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE wallet_account_id = %d ORDER BY id DESC LIMIT %d", $wallet_id, $limit ),
			ARRAY_A
		);
	}
}
