<?php

namespace GRN\GateQris\Domain;

use GRN\GateQris\API\Client;
use GRN\GateQris\Config\Settings;
use GRN\GateQris\Repository\TransactionRepository;
use GRN\GateQris\Support\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class TransactionService {
	public function __construct(
		private readonly TransactionRepository $transactions,
		private readonly IdempotencyService $idempotency,
		private readonly Client $client,
		private readonly Settings $settings,
		private readonly StatusMachine $status_machine,
		private readonly SettlementService $settlements,
		private readonly Logger $logger
	) {}

	/**
	 * Create a new invoice transaction.
	 *
	 * Creates transaction record, calls GateQRIS API, and stores invoice data.
	 * Supports idempotency via customer_ref + idempotency_key.
	 *
	 * @param array $payload {
	 *     Transaction payload
	 *     @type int $amount Amount in IDR minor units
	 *     @type string $customer_ref Unique customer reference (max 100 chars)
	 *     @type string $customer_name Customer name (max 100 chars)
	 *     @type string $customer_email Customer email
	 *     @type string $reference Public reference (optional)
	 *     @type string $idempotency_key Idempotency key to prevent duplicates
	 *     @type string $wallet_owner_type 'site' or 'user' (default: 'site')
	 *     @type int $wallet_owner_id WordPress user ID if owner_type is 'user'
	 * }
	 * @param string $scope Scope for logging/tracking: 'public_form', 'admin_invoice', 'admin_test_invoice', 'admin_connection_test'
	 *
	 * @return array|WP_Error Transaction record on success, WP_Error on failure.
	 */
	public function create_invoice( array $payload, string $scope = 'public_form' ): array|WP_Error {
		$amount = absint( $payload['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'gateqris_invalid_amount', __( 'Amount must be a positive integer.', 'gateqris-payments' ) );
		}

		$wallet_owner_type = in_array( $payload['wallet_owner_type'] ?? 'site', array( 'site', 'user' ), true ) ? $payload['wallet_owner_type'] : 'site';
		$wallet_owner_id   = absint( $payload['wallet_owner_id'] ?? 0 );
		if ( 'site' === $wallet_owner_type ) {
			$wallet_owner_id = 0;
		} elseif ( $wallet_owner_id <= 0 ) {
			return new WP_Error( 'gateqris_invalid_wallet_owner', __( 'User wallet target must reference a valid user.', 'gateqris-payments' ) );
		}

		$request_hash    = hash( 'sha256', wp_json_encode( array( $amount, $wallet_owner_type, $wallet_owner_id, $scope ) ) );
		$idempotency_key = sanitize_text_field( $payload['idempotency_key'] ?? wp_generate_password( 20, false, false ) );
		$reserved        = $this->idempotency->reserve( 'create_invoice', $idempotency_key, $request_hash );
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}

		$existing_transaction = $this->transactions->get_by_idempotency_key( $idempotency_key );
		if ( $existing_transaction && ! empty( $existing_transaction['uuid'] ) ) {
			return $this->public_payload( $existing_transaction );
		}

		if ( 'completed' === ( $reserved['status'] ?? '' ) && ! empty( $reserved['response_snapshot'] ) ) {
			$cached = json_decode( (string) $reserved['response_snapshot'], true );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$uuid         = wp_generate_uuid4();
		$access_token = wp_generate_password( 48, false, false );
		$customer_ref = strtoupper( 'GQ-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 1000, 9999 ) );
		$now          = gmdate( 'Y-m-d H:i:s' );
		$metadata     = apply_filters(
			'gateqris_payments_transaction_meta',
			array(
				'customer_name'  => sanitize_text_field( $payload['customer_name'] ?? '' ),
				'customer_email' => sanitize_email( $payload['customer_email'] ?? '' ),
			),
			$payload,
			$scope
		);

		$transaction_id = $this->transactions->create(
			array(
				'uuid'                      => $uuid,
				'access_token'              => $access_token,
				'customer_ref'              => $customer_ref,
				'idempotency_key'           => $idempotency_key,
				'public_reference'          => sanitize_text_field( $payload['reference'] ?? $customer_ref ),
				'source_type'               => $scope,
				'source_id'                 => (string) ( $payload['source_id'] ?? '' ),
				'payment_type'              => 'qris',
				'base_amount'               => $amount,
				'unique_code'               => 0,
				'total_amount'              => 0,
				'currency'                  => (string) $this->settings->get( 'currency', 'IDR' ),
				'gateway_status'            => 'PENDING',
				'internal_status'           => 'draft',
				'payment_confirmation_type' => '',
				'last_update_source'        => 'create_invoice',
				'wallet_owner_type'         => apply_filters( 'gateqris_payments_resolve_wallet_owner_type', $wallet_owner_type, $payload ),
				'wallet_owner_id'           => apply_filters( 'gateqris_payments_resolve_wallet_owner_id', $wallet_owner_id, $payload ),
				'meta_json'                 => wp_json_encode( $metadata ),
				'created_at_gmt'            => $now,
				'updated_at_gmt'            => $now,
			)
		);

		$api_result = $this->client->create_invoice( $amount, $customer_ref, $idempotency_key );
		if ( is_wp_error( $api_result ) ) {
			$this->transactions->update(
				$transaction_id,
				array(
					'internal_status' => 'error',
					'last_update_source' => 'create_invoice_error',
					'updated_at_gmt'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			return $api_result;
		}

		$gateway_status     = strtoupper( (string) ( $api_result['status'] ?? 'PENDING' ) );
		$gateway_invoice_id = (string) ( $api_result['id'] ?? '' );
		$existing_invoice   = '' !== $gateway_invoice_id ? $this->transactions->get_by_gateway_invoice_id( $gateway_invoice_id ) : null;
		if ( $existing_invoice && (int) $existing_invoice['id'] !== $transaction_id ) {
			$this->transactions->update(
				$transaction_id,
				array(
					'internal_status' => 'error',
					'last_update_source' => 'create_invoice_error',
					'updated_at_gmt'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);

			return new WP_Error( 'gateqris_duplicate_invoice', __( 'Provider returned a duplicate invoice ID.', 'gateqris-payments' ) );
		}

		$this->transactions->update(
			$transaction_id,
			array(
				'gateway_invoice_id' => $gateway_invoice_id,
				'unique_code'        => absint( $api_result['uniqueCode'] ?? 0 ),
				'total_amount'       => absint( $api_result['totalAmount'] ?? $amount ),
				'gateway_status'     => $gateway_status,
				'internal_status'    => $this->status_machine->map_gateway_to_internal( $gateway_status ),
				'last_update_source' => 'create_invoice',
				'qris_string'        => (string) ( $api_result['qrisString'] ?? '' ),
				'expires_at_gmt'     => $this->normalize_gmt( (string) ( $api_result['expiresAt'] ?? '' ) ),
				'next_poll_at_gmt'   => gmdate( 'Y-m-d H:i:s', time() + ( (int) $this->settings->get( 'poll_interval_minutes', 5 ) * MINUTE_IN_SECONDS ) ),
				'updated_at_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$transaction = $this->transactions->get_by_id( $transaction_id );
		$response    = $this->public_payload( $transaction ?? array() );
		$this->idempotency->complete( (int) $reserved['id'], $response );

		return $response;
	}

	/**
	 * Refresh transaction status from GateQRIS API.
	 *
	 * Polls GateQRIS for current invoice status and updates transaction record.
	 * Called automatically via polling job or manually via admin action.
	 *
	 * @param array $transaction Transaction record from database
	 *
	 * @return array|WP_Error Updated transaction record on success, WP_Error on failure.
	 */
	public function refresh_transaction( array $transaction ): array|WP_Error {
		if ( empty( $transaction['gateway_invoice_id'] ) ) {
			return new WP_Error( 'gateqris_missing_invoice_id', __( 'Transaction is missing provider invoice ID.', 'gateqris-payments' ) );
		}

		$result = $this->client->check_invoice_status( (string) $transaction['gateway_invoice_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->apply_gateway_update( $transaction, $result, 'polling' );
	}

	public function apply_gateway_update( array $transaction, array $gateway_payload, string $source ): array|WP_Error {
		$gateway_status = strtoupper( (string) ( $gateway_payload['status'] ?? 'PENDING' ) );
		$next_status    = $this->status_machine->map_gateway_to_internal( $gateway_status );
		$current_status = (string) $transaction['internal_status'];

		if ( ! $this->status_machine->should_transition( $current_status, $next_status ) ) {
			$this->logger->warning( 'Ignoring status downgrade', array( 'transaction_id' => $transaction['id'], 'from' => $current_status, 'to' => $next_status, 'source' => $source ) );
			return $this->public_payload( $transaction );
		}

		$updates = array(
			'gateway_status'      => $gateway_status,
			'internal_status'     => $next_status,
			'last_update_source'  => $source,
			'total_amount'        => absint( $gateway_payload['totalAmount'] ?? $transaction['total_amount'] ),
			'updated_at_gmt'      => gmdate( 'Y-m-d H:i:s' ),
			'last_polled_at_gmt'  => 'polling' === $source ? gmdate( 'Y-m-d H:i:s' ) : ( $transaction['last_polled_at_gmt'] ?? null ),
			'next_poll_at_gmt'    => $this->calculate_next_poll( (int) $transaction['poll_attempts'] ),
			'poll_attempts'       => 'polling' === $source ? ( (int) $transaction['poll_attempts'] + 1 ) : (int) $transaction['poll_attempts'],
		);

		if ( in_array( $gateway_status, array( 'PAID', 'MANUAL_ACC' ), true ) ) {
			$updates['payment_confirmation_type'] = 'MANUAL_ACC' === $gateway_status ? 'manual' : 'automatic';
			$updates['paid_at_gmt']               = gmdate( 'Y-m-d H:i:s' );
		}

		if ( 'EXPIRED' === $gateway_status ) {
			$updates['next_poll_at_gmt'] = null;
		}

		$this->transactions->update( (int) $transaction['id'], $updates );
		$updated = $this->transactions->get_by_id( (int) $transaction['id'] ) ?? array_merge( $transaction, $updates );

		if ( 'paid_unsettled' === $updated['internal_status'] ) {
			$this->settlements->settle( $updated );
			$updated = $this->transactions->get_by_id( (int) $transaction['id'] ) ?? $updated;
		}

		do_action( 'gateqris_payments_gateway_update_applied', $updated, $gateway_payload, $source );

		return $this->public_payload( $updated );
	}

	/**
	 * Get public-safe transaction payload for frontend.
	 *
	 * Extracts QRIS string, hosted URL, and status for display.
	 * Removes sensitive data like API keys, secrets, and internal IDs.
	 *
	 * @param array $transaction Transaction record from database
	 *
	 * @return array Public-safe payload with hostedUrl, qrisString, status, etc.
	 */
	public function public_payload( array $transaction ): array {
		$access_token = (string) ( $transaction['access_token'] ?? '' );
		$uuid         = (string) ( $transaction['uuid'] ?? '' );

		return array(
			'id'               => $uuid,
			'accessToken'      => $access_token,
			'customerRef'      => $transaction['customer_ref'] ?? '',
			'gatewayInvoiceId' => $transaction['gateway_invoice_id'] ?? '',
			'status'           => $transaction['gateway_status'] ?? '',
			'internalStatus'   => $transaction['internal_status'] ?? '',
			'amount'           => isset( $transaction['base_amount'] ) ? (int) $transaction['base_amount'] : 0,
			'totalAmount'      => isset( $transaction['total_amount'] ) ? (int) $transaction['total_amount'] : 0,
			'uniqueCode'       => isset( $transaction['unique_code'] ) ? (int) $transaction['unique_code'] : 0,
			'qrisString'       => $transaction['qris_string'] ?? '',
			'expiresAt'        => $transaction['expires_at_gmt'] ?? null,
			'walletOwnerType'  => $transaction['wallet_owner_type'] ?? 'site',
			'walletOwnerId'    => isset( $transaction['wallet_owner_id'] ) ? (int) $transaction['wallet_owner_id'] : 0,
			'lastUpdateSource' => $transaction['last_update_source'] ?? '',
			'hostedUrl'        => $this->hosted_url( $uuid, $access_token ),
		);
	}

	private function calculate_next_poll( int $attempts ): string {
		$base    = (int) $this->settings->get( 'poll_interval_minutes', 5 ) * MINUTE_IN_SECONDS;
		$backoff = min( HOUR_IN_SECONDS, $base * max( 1, $attempts + 1 ) );
		return gmdate( 'Y-m-d H:i:s', time() + $backoff );
	}

	private function normalize_gmt( string $value ): ?string {
		if ( '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private function hosted_url( string $uuid, string $access_token ): string {
		return $this->settings->public_url(
			add_query_arg(
				array(
					'action'               => 'gateqris_hosted_payment',
					'gateqris_transaction' => $uuid,
					'gateqris_token'       => $access_token,
				),
				'/wp-admin/admin-post.php'
			)
		);
	}
}
