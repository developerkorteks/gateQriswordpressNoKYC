<?php

namespace GRN\GateQris\Repository;

defined( 'ABSPATH' ) || exit;

final class IdempotencyRepository {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'gq_idempotency_keys';
	}

	public function get( string $scope, string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE scope = %s AND idempotency_key = %s", $scope, $key ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function create( array $data ): bool {
		global $wpdb;
		// INSERT IGNORE: if a concurrent request already inserted the same
		// (scope, idempotency_key) pair the duplicate is silently discarded
		// instead of causing a race where two requests both think they are first.
		$columns      = implode( ', ', array_keys( $data ) );
		$placeholders = implode( ', ', array_fill( 0, count( $data ), '%s' ) );
		$sql          = $wpdb->prepare(
			"INSERT IGNORE INTO {$this->table} ({$columns}) VALUES ({$placeholders})",
			...array_values( $data )
		);
		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}
}
