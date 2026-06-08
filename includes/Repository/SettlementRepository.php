<?php

namespace GRN\GateQris\Repository;

defined( 'ABSPATH' ) || exit;

final class SettlementRepository {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'gq_settlements';
	}

	public function get_by_transaction_id( int $transaction_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE transaction_id = %d", $transaction_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function create( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->table, $data );
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	public function all( int $wallet_account_id = 0, int $limit = 50 ): array {
		global $wpdb;

		if ( $wallet_account_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE wallet_account_id = %d ORDER BY id DESC LIMIT %d",
					$wallet_account_id,
					$limit
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}

	public function for_wallet( int $wallet_account_id, int $limit = 20 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE wallet_account_id = %d
				ORDER BY id DESC
				LIMIT %d",
				$wallet_account_id,
				$limit
			),
			ARRAY_A
		);
	}
}
