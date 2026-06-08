<?php

namespace GRN\GateQris\Database;

use GRN\GateQris\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class Migrations {
	private const SCHEMA_OPTION = 'gateqris_payments_schema_version';

	private const SCHEMA_VERSION = '4';

	public function __construct(
		private readonly Logger $logger
	) {}

	public function needs_migration(): bool {
		return self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '0' );
	}

	public function migrate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$statements = array(
			"CREATE TABLE {$prefix}gq_transactions (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				access_token varchar(64) NULL,
				customer_ref varchar(100) NOT NULL,
				idempotency_key varchar(100) NOT NULL,
				public_reference varchar(100) DEFAULT '' NOT NULL,
				source_type varchar(50) DEFAULT 'hosted_form' NOT NULL,
				source_id varchar(100) DEFAULT '' NOT NULL,
				payment_type varchar(30) DEFAULT 'qris' NOT NULL,
				base_amount bigint unsigned NOT NULL,
				unique_code int unsigned DEFAULT 0 NOT NULL,
				total_amount bigint unsigned DEFAULT 0 NOT NULL,
				currency varchar(10) DEFAULT 'IDR' NOT NULL,
				gateway_invoice_id varchar(100) NULL,
				gateway_status varchar(30) DEFAULT 'PENDING' NOT NULL,
				internal_status varchar(30) DEFAULT 'draft' NOT NULL,
				payment_confirmation_type varchar(20) DEFAULT '' NOT NULL,
				last_update_source varchar(30) DEFAULT '' NOT NULL,
				wallet_owner_type varchar(20) DEFAULT 'site' NOT NULL,
				wallet_owner_id bigint unsigned DEFAULT 0 NOT NULL,
				wallet_account_id bigint unsigned DEFAULT 0 NOT NULL,
				qris_string longtext NULL,
				meta_json longtext NULL,
				expires_at_gmt datetime NULL,
				paid_at_gmt datetime NULL,
				settled_at_gmt datetime NULL,
				last_polled_at_gmt datetime NULL,
				next_poll_at_gmt datetime NULL,
				poll_attempts int unsigned DEFAULT 0 NOT NULL,
				processing_lock_token varchar(100) DEFAULT '' NOT NULL,
				processing_locked_until_gmt datetime NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY access_token (access_token),
				UNIQUE KEY customer_ref (customer_ref),
				UNIQUE KEY idempotency_key (idempotency_key),
				UNIQUE KEY gateway_invoice_id (gateway_invoice_id),
				KEY internal_status (internal_status),
				KEY wallet_account_id (wallet_account_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}gq_webhook_events (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				event_uid varchar(150) NOT NULL,
				gateway_invoice_id varchar(100) DEFAULT '' NOT NULL,
				signature varchar(255) DEFAULT '' NOT NULL,
				payload_hash varchar(64) DEFAULT '' NOT NULL,
				payload_json longtext NOT NULL,
				status_before varchar(30) DEFAULT '' NOT NULL,
				status_after varchar(30) DEFAULT '' NOT NULL,
				result varchar(30) DEFAULT 'received' NOT NULL,
				error_message text NULL,
				created_at_gmt datetime NOT NULL,
				processed_at_gmt datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_uid (event_uid),
				KEY gateway_invoice_id (gateway_invoice_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}gq_wallet_accounts (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				account_uuid char(36) NOT NULL,
				owner_type varchar(20) NOT NULL,
				owner_id bigint unsigned DEFAULT 0 NOT NULL,
				account_code varchar(50) NOT NULL,
				currency varchar(10) DEFAULT 'IDR' NOT NULL,
				status varchar(20) DEFAULT 'active' NOT NULL,
				available_balance bigint DEFAULT 0 NOT NULL,
				pending_balance bigint DEFAULT 0 NOT NULL,
				reserved_balance bigint DEFAULT 0 NOT NULL,
				meta_json longtext NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY account_uuid (account_uuid),
				UNIQUE KEY account_code (account_code),
				UNIQUE KEY owner_lookup (owner_type, owner_id, currency)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}gq_ledger_entries (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				entry_uuid char(36) NOT NULL,
				transaction_id bigint unsigned DEFAULT 0 NOT NULL,
				wallet_account_id bigint unsigned NOT NULL,
				actor_user_id bigint unsigned DEFAULT 0 NOT NULL,
				entry_type varchar(50) NOT NULL,
				direction varchar(10) NOT NULL,
				amount bigint unsigned NOT NULL,
				currency varchar(10) DEFAULT 'IDR' NOT NULL,
				balance_before bigint NOT NULL,
				balance_after bigint NOT NULL,
				reference_type varchar(50) DEFAULT '' NOT NULL,
				reference_id varchar(100) DEFAULT '' NOT NULL,
				note text NULL,
				created_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY entry_uuid (entry_uuid),
				KEY wallet_account_id (wallet_account_id),
				KEY transaction_id (transaction_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}gq_settlements (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				settlement_uuid char(36) NOT NULL,
				transaction_id bigint unsigned NOT NULL,
				wallet_account_id bigint unsigned NOT NULL,
				gross_amount bigint unsigned NOT NULL,
				fee_amount bigint unsigned DEFAULT 0 NOT NULL,
				net_amount bigint unsigned NOT NULL,
				status varchar(20) DEFAULT 'pending' NOT NULL,
				confirmation_type varchar(20) DEFAULT '' NOT NULL,
				created_at_gmt datetime NOT NULL,
				settled_at_gmt datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY settlement_uuid (settlement_uuid),
				UNIQUE KEY transaction_id (transaction_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}gq_idempotency_keys (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				scope varchar(50) NOT NULL,
				idempotency_key varchar(100) NOT NULL,
				request_hash varchar(64) NOT NULL,
				response_snapshot longtext NULL,
				status varchar(20) DEFAULT 'pending' NOT NULL,
				expires_at_gmt datetime NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY scope_key (scope, idempotency_key)
			) {$charset_collate};",
		);

		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}

		$wpdb->query( "ALTER TABLE {$prefix}gq_transactions MODIFY gateway_invoice_id varchar(100) NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE {$prefix}gq_transactions MODIFY access_token varchar(64) NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$this->maybe_add_transaction_column( 'last_update_source', "ALTER TABLE {$prefix}gq_transactions ADD COLUMN last_update_source varchar(30) DEFAULT '' NOT NULL AFTER payment_confirmation_type" );
		$this->maybe_add_ledger_column( 'actor_user_id', "ALTER TABLE {$prefix}gq_ledger_entries ADD COLUMN actor_user_id bigint unsigned DEFAULT 0 NOT NULL AFTER wallet_account_id" );

		$this->backfill_access_tokens();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		$this->ensure_site_wallet();
		$this->logger->info( 'GateQRIS schema migrated', array( 'version' => self::SCHEMA_VERSION ) );
	}

	private function ensure_site_wallet(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'gq_wallet_accounts';
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE owner_type = %s AND owner_id = %d LIMIT 1", 'site', 0 ) );

		if ( $found ) {
			return;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$table,
			array(
				'account_uuid'       => wp_generate_uuid4(),
				'owner_type'         => 'site',
				'owner_id'           => 0,
				'account_code'       => 'site-main',
				'currency'           => 'IDR',
				'status'             => 'active',
				'available_balance'  => 0,
				'pending_balance'    => 0,
				'reserved_balance'   => 0,
				'created_at_gmt'     => $now,
				'updated_at_gmt'     => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	private function backfill_access_tokens(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'gq_transactions';
		$rows  = $wpdb->get_results( "SELECT id FROM {$table} WHERE access_token IS NULL OR access_token = ''", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$wpdb->update(
				$table,
				array(
					'access_token'   => bin2hex( random_bytes( 24 ) ),
					'updated_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	private function maybe_add_transaction_column( string $column, string $sql ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'gq_transactions';
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		if ( $found ) {
			return;
		}

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	private function maybe_add_ledger_column( string $column, string $sql ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'gq_ledger_entries';
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		if ( $found ) {
			return;
		}

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
	}
}
