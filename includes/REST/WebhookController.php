<?php

namespace GRN\GateQris\REST;

use GRN\GateQris\API\WebhookVerifier;
use GRN\GateQris\Config\Settings;
use GRN\GateQris\Domain\TransactionService;
use GRN\GateQris\Repository\TransactionRepository;
use GRN\GateQris\Repository\WebhookEventRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class WebhookController {
	public function __construct(
		private readonly Settings $settings,
		private readonly WebhookVerifier $verifier,
		private readonly WebhookEventRepository $events,
		private readonly TransactionRepository $transactions,
		private readonly TransactionService $transaction_service
	) {}

	public function register(): void {
		register_rest_route(
			'gateqris/v1',
			'/webhook/(?P<token>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( (string) $request['token'] !== (string) $this->settings->get( 'webhook_token' ) ) {
			return new WP_Error( 'gateqris_invalid_webhook_token', __( 'Invalid webhook token.', 'gateqris-payments' ), array( 'status' => 403 ) );
		}

		$raw_body  = (string) $request->get_body();
		$signature = (string) $request->get_header( 'x-signature' );
		$timestamp = $request->get_header( 'x-timestamp' );

		$secret = (string) $this->settings->get( 'secret_key', '' );
		if ( '' === $secret || ! $this->verifier->verify( $secret, $raw_body, $signature, $timestamp ?: null, (int) $this->settings->get( 'timestamp_tolerance', 300 ) ) ) {
			return new WP_Error( 'gateqris_invalid_signature', __( 'Webhook signature verification failed.', 'gateqris-payments' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'gateqris_invalid_webhook_payload', __( 'Webhook payload is invalid JSON.', 'gateqris-payments' ), array( 'status' => 400 ) );
		}

		$event_uid = $this->build_event_uid( $payload, $raw_body );
		$existing  = $this->events->get_by_event_uid( $event_uid );
		if ( $existing ) {
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}

		$transaction = null;
		if ( ! empty( $payload['id'] ) ) {
			$transaction = $this->transactions->get_by_gateway_invoice_id( (string) $payload['id'] );
		}
		if ( ! $transaction && ! empty( $payload['customerRef'] ) ) {
			$transaction = $this->transactions->get_by_customer_ref( (string) $payload['customerRef'] );
		}
		if ( ! $transaction ) {
			return new WP_Error( 'gateqris_transaction_not_found', __( 'No transaction matches this webhook.', 'gateqris-payments' ), array( 'status' => 404 ) );
		}

		$event_id = $this->events->create(
			array(
				'event_uid'          => $event_uid,
				'gateway_invoice_id' => (string) ( $payload['id'] ?? '' ),
				'signature'          => $signature,
				'payload_hash'       => hash( 'sha256', $raw_body ),
				'payload_json'       => $raw_body,
				'status_before'      => (string) $transaction['internal_status'],
				'status_after'       => '',
				'result'             => 'received',
				'created_at_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$result = $this->transaction_service->apply_gateway_update( $transaction, $payload, 'webhook' );
		if ( is_wp_error( $result ) ) {
			$this->events->update(
				$event_id,
				array(
					'result'           => 'failed',
					'error_message'    => $result->get_error_message(),
					'processed_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			return $result;
		}

		$this->events->update(
			$event_id,
			array(
				'status_after'      => (string) ( $result['internalStatus'] ?? '' ),
				'result'            => 'processed',
				'processed_at_gmt'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		do_action( 'gateqris_payments_webhook_processed', $payload, $result );

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	private function build_event_uid( array $payload, string $raw_body ): string {
		$invoice_id = (string) ( $payload['id'] ?? 'unknown' );
		$status     = (string) ( $payload['status'] ?? 'unknown' );
		return $invoice_id . ':' . $status . ':' . hash( 'sha256', $raw_body );
	}
}
