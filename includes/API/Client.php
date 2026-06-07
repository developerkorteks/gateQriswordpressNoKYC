<?php

namespace GRN\GateQris\API;

use GRN\GateQris\Config\Settings;
use GRN\GateQris\Support\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Client {
	public function __construct(
		private readonly Settings $settings,
		private readonly Signer $signer,
		private readonly Logger $logger
	) {}

	public function create_invoice( int $amount, string $customer_ref, string $idempotency_key ): array|WP_Error {
		$payload = array(
			'amount'      => $amount,
			'customerRef' => $customer_ref,
		);

		$raw_body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $raw_body ) {
			return new WP_Error( 'gateqris_encode_failed', __( 'Unable to encode invoice payload.', 'gateqris-payments' ) );
		}

		return $this->request(
			'POST',
			'/api/v1/invoice/create',
			$raw_body,
			array(
				'X-Idempotency-Key' => $idempotency_key,
			)
		);
	}

	public function check_invoice_status( string $invoice_id ): array|WP_Error {
		return $this->request( 'GET', '/api/v1/invoice/status/' . rawurlencode( $invoice_id ), '' );
	}

	private function request( string $method, string $path, string $raw_body = '', array $extra_headers = array() ): array|WP_Error {
		$timestamp  = (string) time();
		$secret_key = (string) $this->settings->get( 'secret_key', '' );
		$public_key = (string) $this->settings->get( 'public_key', '' );

		if ( '' === $secret_key || '' === $public_key ) {
			return new WP_Error( 'gateqris_missing_credentials', __( 'GateQRIS credentials are not configured.', 'gateqris-payments' ) );
		}

		$headers = array_merge(
			array(
				'Content-Type' => 'application/json',
				'X-Public-Key' => $public_key,
				'X-Timestamp'  => $timestamp,
				'X-Signature'  => $this->signer->sign( $secret_key, $timestamp, $raw_body ),
			),
			$extra_headers
		);

		$base_url = untrailingslashit( (string) $this->settings->get( 'api_base_url', '' ) );
		$url      = $base_url . $path;

		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'headers'     => $headers,
			'body'        => 'POST' === $method ? $raw_body : null,
			'data_format' => 'body',
		);

		$this->logger->debug( 'GateQRIS API request', array( 'method' => $method, 'url' => $url ) );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'GateQRIS API transport failure', array( 'message' => $response->get_error_message() ) );
			return new WP_Error( 'gateqris_transport_failed', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = '' !== $body ? json_decode( $body, true ) : array();

		if ( $code < 200 || $code >= 300 ) {
			$this->logger->warning( 'GateQRIS API returned non-success status', array( 'code' => $code, 'body' => $body ) );
			return new WP_Error(
				'gateqris_api_error',
				sprintf( 'GateQRIS API returned HTTP %d', $code ),
				array(
					'status_code' => $code,
					'body'        => $data,
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'gateqris_api_invalid_json', __( 'GateQRIS API response is not valid JSON.', 'gateqris-payments' ) );
		}

		return $data;
	}
}
