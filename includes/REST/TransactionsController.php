<?php

namespace GRN\GateQris\REST;

use GRN\GateQris\Domain\TransactionService;
use GRN\GateQris\Repository\TransactionRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class TransactionsController {
	public function __construct(
		private readonly TransactionService $transactions,
		private readonly TransactionRepository $repository
	) {}

	public function register(): void {
		register_rest_route(
			'gateqris/v1',
			'/transactions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'amount' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			'gateqris/v1',
			'/transactions/(?P<uuid>[a-f0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'gateqris/v1',
			'/transactions/(?P<uuid>[a-f0-9-]+)/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'gateqris/v1',
			'/transactions/(?P<uuid>[a-f0-9-]+)/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$honeypot = trim( (string) $request->get_param( 'website' ) );
		if ( '' !== $honeypot ) {
			return new WP_Error( 'gateqris_spam_detected', __( 'Spam protection triggered.', 'gateqris-payments' ), array( 'status' => 400 ) );
		}

		$token = (string) $request->get_param( 'form_token' );
		$woo_order_id = (int) $request->get_param( 'woo_order_id' );

		// The form declares its settlement target; the nonce is bound to that target
		// so the value cannot be tampered to redirect funds.
		$claimed      = 'user' === $request->get_param( 'wallet_target' ) ? 'user' : 'site';
		$nonce_action = 'user' === $claimed ? 'gateqris_public_form_user' : 'gateqris_public_form';

		// Verify nonce OR verify WooCommerce order exists and is pending
		$nonce_valid = (bool) wp_verify_nonce( $token, $nonce_action );
		$order_valid = $woo_order_id > 0 && function_exists( 'wc_get_order' )
			? wc_get_order( $woo_order_id ) instanceof \WC_Order
			: false;

		if ( ! $nonce_valid && ! $order_valid ) {
			return new WP_Error( 'gateqris_invalid_token', __( 'Invalid form token.', 'gateqris-payments' ), array( 'status' => 403 ) );
		}

		if ( $this->is_rate_limited() ) {
			return new WP_Error( 'gateqris_rate_limited', __( 'Too many payment attempts. Please wait a moment and try again.', 'gateqris-payments' ), array( 'status' => 429 ) );
		}

		// Prevent payment duplication - reject if order already paid/processing
		if ( $woo_order_id > 0 ) {
			$order = wc_get_order( $woo_order_id );
			if ( $order && in_array( $order->get_status(), array( 'processing', 'completed', 'failed' ), true ) ) {
				return new WP_Error( 'gateqris_order_already_paid', __( 'This order has already been paid or processed.', 'gateqris-payments' ), array( 'status' => 400 ) );
			}
		}

		// Route to the payer's own wallet (top-up) only when the form explicitly
		// claimed the user target, that claim is nonce-verified, and the request is
		// authenticated; otherwise settle to the site wallet.
		$wallet_owner_type = 'site';
		$wallet_owner_id   = 0;
		if ( 'user' === $claimed && $nonce_valid && is_user_logged_in() ) {
			$wallet_owner_type = 'user';
			$wallet_owner_id   = get_current_user_id();
		}

		$customer_name  = sanitize_text_field( (string) $request->get_param( 'customer_name' ) );
		$customer_email = sanitize_email( (string) $request->get_param( 'customer_email' ) );
		$reference      = sanitize_text_field( (string) $request->get_param( 'reference' ) );

		// Top-up to a user's own wallet: the customer is known, so derive the
		// contact details from their account instead of asking again.
		if ( 'user' === $wallet_owner_type && $wallet_owner_id > 0 ) {
			$user = get_userdata( $wallet_owner_id );
			if ( $user ) {
				if ( '' === $customer_name ) {
					$customer_name = $user->display_name;
				}
				if ( '' === $customer_email ) {
					$customer_email = $user->user_email;
				}
				if ( '' === $reference ) {
					$reference = __( 'Isi saldo', 'gateqris-payments' );
				}
			}
		}

		$result = $this->transactions->create_invoice(
			array(
				'amount'            => absint( $request->get_param( 'amount' ) ),
				'customer_name'     => $customer_name,
				'customer_email'    => $customer_email,
				'reference'         => $reference,
				'idempotency_key'   => wp_generate_password( 20, false, false ),
				'woo_order_id'      => absint( $request->get_param( 'woo_order_id' ) ),
				'wallet_owner_type' => $wallet_owner_type,
				'wallet_owner_id'   => $wallet_owner_id,
			),
			'public_form'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_rate_limit();
		return new WP_REST_Response( $result, 201 );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction = $this->resolve_public_transaction( $request );
		if ( ! $transaction ) {
			return new WP_Error( 'gateqris_transaction_not_found', __( 'Transaction not found.', 'gateqris-payments' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->transactions->public_payload( $transaction ) );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->show( $request );
	}

	public function refresh( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transaction = $this->resolve_public_transaction( $request );
		if ( ! $transaction ) {
			return new WP_Error( 'gateqris_transaction_not_found', __( 'Transaction not found.', 'gateqris-payments' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'manage_options' ) && $this->is_refresh_rate_limited( (string) $transaction['uuid'], (string) $transaction['access_token'] ) ) {
			return new WP_Error( 'gateqris_refresh_rate_limited', __( 'Too many refresh attempts. Please wait a few seconds and try again.', 'gateqris-payments' ), array( 'status' => 429 ) );
		}

		if ( in_array( (string) $transaction['internal_status'], array( 'settled', 'expired', 'reconciled' ), true ) ) {
			return new WP_REST_Response( $this->transactions->public_payload( $transaction ) );
		}

		$result = $this->transactions->refresh_transaction( $transaction );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->bump_refresh_rate_limit( (string) $transaction['uuid'], (string) $transaction['access_token'] );
		}

		return new WP_REST_Response( $result );
	}

	private function is_rate_limited(): bool {
		$key = 'gq_rate_' . md5( $this->request_fingerprint() );
		return (int) get_transient( $key ) >= 5;
	}

	private function bump_rate_limit(): void {
		$key   = 'gq_rate_' . md5( $this->request_fingerprint() );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
	}

	private function request_fingerprint(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
		return $ip . '|' . $ua;
	}

	private function is_refresh_rate_limited( string $uuid, string $access_token ): bool {
		$key = 'gq_refresh_' . md5( $uuid . '|' . $access_token . '|' . $this->request_fingerprint() );
		return (int) get_transient( $key ) >= 6;
	}

	private function bump_refresh_rate_limit( string $uuid, string $access_token ): void {
		$key   = 'gq_refresh_' . md5( $uuid . '|' . $access_token . '|' . $this->request_fingerprint() );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	}

	private function resolve_public_transaction( WP_REST_Request $request ): ?array {
		if ( current_user_can( 'manage_options' ) ) {
			return $this->repository->get_by_uuid( (string) $request['uuid'] );
		}

		$access_token = sanitize_text_field( (string) ( $request->get_param( 'access_token' ) ?: $request->get_header( 'x-gateqris-access-token' ) ) );
		if ( '' === $access_token ) {
			return null;
		}

		return $this->repository->get_by_uuid_and_token( (string) $request['uuid'], $access_token );
	}
}
