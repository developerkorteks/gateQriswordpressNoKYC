<?php
/**
 * Plugin uninstall bootstrap.
 *
 * @package GateQRISPayments
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'gateqris_payments_settings', array() );

if ( isset( $settings['retain_data_on_uninstall'] ) && 'yes' === $settings['retain_data_on_uninstall'] ) {
	return;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'gq_transactions',
	$wpdb->prefix . 'gq_webhook_events',
	$wpdb->prefix . 'gq_wallet_accounts',
	$wpdb->prefix . 'gq_ledger_entries',
	$wpdb->prefix . 'gq_settlements',
	$wpdb->prefix . 'gq_idempotency_keys',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'gateqris_payments_settings' );
delete_option( 'gateqris_payments_schema_version' );
