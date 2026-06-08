<?php

namespace GRN\GateQris\Repository;

defined( 'ABSPATH' ) || exit;

final class WebhookEventRepository {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'gq_webhook_events';
	}

	public function get_by_event_uid( string $event_uid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE event_uid = %s", $event_uid ), ARRAY_A );
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

	public function recent( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}

	public function for_invoice( string $gateway_invoice_id, int $limit = 20 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE gateway_invoice_id = %s ORDER BY id DESC LIMIT %d",
				$gateway_invoice_id,
				$limit
			),
			ARRAY_A
		);
	}
}
