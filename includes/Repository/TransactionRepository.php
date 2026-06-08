<?php

namespace GRN\GateQris\Repository;

defined( 'ABSPATH' ) || exit;

final class TransactionRepository {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'gq_transactions';
	}

	public function table(): string {
		return $this->table;
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

	public function acquire_processing_lock( int $id, string $token, int $ttl = 30 ): bool {
		global $wpdb;

		$now         = gmdate( 'Y-m-d H:i:s' );
		$expires_at  = gmdate( 'Y-m-d H:i:s', time() + max( 5, $ttl ) );
		$query       = $wpdb->prepare(
			"UPDATE {$this->table}
			SET processing_lock_token = %s, processing_locked_until_gmt = %s, updated_at_gmt = %s
			WHERE id = %d
			AND (
				processing_lock_token = ''
				OR processing_locked_until_gmt IS NULL
				OR processing_locked_until_gmt < %s
				OR processing_lock_token = %s
			)",
			$token,
			$expires_at,
			$now,
			$id,
			$now,
			$token
		);

		return 1 === (int) $wpdb->query( $query );
	}

	public function release_processing_lock( int $id, string $token ): bool {
		global $wpdb;

		$query = $wpdb->prepare(
			"UPDATE {$this->table}
			SET processing_lock_token = '', processing_locked_until_gmt = NULL, updated_at_gmt = %s
			WHERE id = %d AND processing_lock_token = %s",
			gmdate( 'Y-m-d H:i:s' ),
			$id,
			$token
		);

		return false !== $wpdb->query( $query );
	}

	public function get_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE uuid = %s", $uuid ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function get_by_uuid_and_token( string $uuid, string $access_token ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE uuid = %s AND access_token = %s", $uuid, $access_token ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public function get_by_customer_ref( string $customer_ref ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE customer_ref = %s", $customer_ref ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function get_by_idempotency_key( string $idempotency_key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE idempotency_key = %s", $idempotency_key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function get_by_gateway_invoice_id( string $gateway_invoice_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE gateway_invoice_id = %s", $gateway_invoice_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function find_pending_for_polling( int $limit = 20 ): array {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE internal_status = %s
				AND (next_poll_at_gmt IS NULL OR next_poll_at_gmt <= %s)
				ORDER BY created_at_gmt ASC
				LIMIT %d",
				'pending_payment',
				$now,
				$limit
			),
			ARRAY_A
		);
	}

	public function recent( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}

	public function for_wallet_owner( string $owner_type, int $owner_id, int $limit = 20 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE wallet_owner_type = %s AND wallet_owner_id = %d
				ORDER BY id DESC
				LIMIT %d",
				$owner_type,
				$owner_id,
				$limit
			),
			ARRAY_A
		);
	}

	public function count_search( string $term = '', string $gateway_status = '', string $internal_status = '', string $update_source = '', int $wallet_account_id = 0 ): int {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $term ) {
			$like     = '%' . $wpdb->esc_like( $term ) . '%';
			$where[]  = '(uuid LIKE %s OR customer_ref LIKE %s OR public_reference LIKE %s OR gateway_invoice_id LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $gateway_status ) { $where[] = 'gateway_status = %s'; $params[] = $gateway_status; }
		if ( '' !== $internal_status ) { $where[] = 'internal_status = %s'; $params[] = $internal_status; }
		if ( '' !== $update_source ) { $where[] = 'last_update_source = %s'; $params[] = $update_source; }
		if ( $wallet_account_id > 0 ) { $where[] = 'wallet_account_id = %d'; $params[] = $wallet_account_id; }

		$sql = 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE ' . implode( ' AND ', $where );

		return (int) ( empty( $params ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function search( string $term = '', string $gateway_status = '', string $internal_status = '', string $update_source = '', int $wallet_account_id = 0, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $term ) {
			$like      = '%' . $wpdb->esc_like( $term ) . '%';
			$where[]   = '(uuid LIKE %s OR customer_ref LIKE %s OR public_reference LIKE %s OR gateway_invoice_id LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		if ( '' !== $gateway_status ) {
			$where[]  = 'gateway_status = %s';
			$params[] = $gateway_status;
		}

		if ( '' !== $internal_status ) {
			$where[]  = 'internal_status = %s';
			$params[] = $internal_status;
		}

		if ( '' !== $update_source ) {
			$where[]  = 'last_update_source = %s';
			$params[] = $update_source;
		}

		if ( $wallet_account_id > 0 ) {
			$where[]  = 'wallet_account_id = %d';
			$params[] = $wallet_account_id;
		}

		$params[] = $limit;
		$params[] = $offset;
		$sql      = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
	}

	public function export_rows( int $limit = 500 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uuid, customer_ref, public_reference, source_type, base_amount, unique_code, total_amount, currency, gateway_invoice_id, gateway_status, internal_status, payment_confirmation_type, wallet_owner_type, wallet_owner_id, created_at_gmt, paid_at_gmt, settled_at_gmt
				FROM {$this->table}
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}
}
