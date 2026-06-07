<?php
/**
 * WooCommerce payment gateway for GateQRIS.
 *
 * @package GRN\GateQris\WooCommerce
 */

namespace GRN\GateQris\WooCommerce;

use GRN\GateQris\Bootstrap;
use GRN\GateQris\Config\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway — integrates GateQRIS with WooCommerce using the standard
 * redirect-to-gateway flow.
 *
 * On checkout, process_payment() creates the GateQRIS invoice server-side
 * (authoritative amount taken from the order, never the client) and redirects
 * the customer to the hosted QRIS page. The order is completed asynchronously
 * by WebhookHandler when GateQRIS confirms payment.
 */
final class Gateway extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'gateqris';
		$this->method_title       = __( 'GateQRIS', 'gateqris-payments' );
		$this->method_description = __( 'QRIS Payment via GateQRIS', 'gateqris-payments' );
		$this->title              = __( 'GateQRIS - QRIS QR Code', 'gateqris-payments' );
		$this->description        = __( 'Secure payment with QRIS QR code. Scan and pay instantly.', 'gateqris-payments' );
		$this->icon               = GATEQRIS_PAYMENTS_PLUGIN_URL . 'assets/img/gateqris-logo-dark.svg';
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Proceed to Payment', 'gateqris-payments' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		// Admin-configured title/description override the defaults.
		$title = $this->get_option( 'title' );
		if ( '' !== (string) $title ) {
			$this->title = $title;
		}
		$description = $this->get_option( 'description' );
		if ( '' !== (string) $description ) {
			$this->description = $description;
		}

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	/**
	 * Render the checkout icon ourselves.
	 *
	 * WooCommerce's default get_icon() expects $this->icon to be a plain URL and wraps
	 * it in its own <img>. We override it to emit a single, size-controlled <img> using
	 * the logo URL so the brand mark shows at a sensible height next to the title.
	 */
	public function get_icon(): string {
		if ( '' === (string) $this->icon ) {
			return '';
		}

		$icon = sprintf(
			'<img src="%s" alt="%s" style="max-height:24px;width:auto;display:inline-block;vertical-align:middle;margin-left:6px" />',
			esc_url( (string) $this->icon ),
			esc_attr( $this->get_title() )
		);

		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'gateqris-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable GateQRIS Payment Method', 'gateqris-payments' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'gateqris-payments' ),
				'type'        => 'text',
				'description' => __( 'Title displayed at checkout.', 'gateqris-payments' ),
				'default'     => __( 'GateQRIS - QRIS QR Code', 'gateqris-payments' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'gateqris-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Description displayed at checkout.', 'gateqris-payments' ),
				'default'     => __( 'Secure payment with QRIS QR code. Scan and pay instantly.', 'gateqris-payments' ),
				'desc_tip'    => true,
			),
		);
	}

	public function is_available(): bool {
		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return false;
		}

		// The core must be loaded and credentials configured for payments to work.
		$module = Module::instance();
		if ( null === $module ) {
			return false;
		}

		// Without API credentials, invoice creation would fail at checkout — hide
		// the method instead of letting the customer hit an error mid-payment.
		if ( ! ( new Settings() )->has_credentials() ) {
			return false;
		}

		// GateQRIS settles in IDR only; don't offer it for other store currencies.
		if ( function_exists( 'get_woocommerce_currency' ) && 'IDR' !== get_woocommerce_currency() ) {
			return false;
		}

		return true;
	}

	/**
	 * Process checkout: create the GateQRIS invoice and redirect to the QRIS page.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return array WooCommerce process_payment result.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Could not process order. Please try again.', 'gateqris-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$module = Module::instance();
		if ( null === $module ) {
			wc_add_notice( __( 'Payment gateway is not available right now.', 'gateqris-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Authoritative currency guard: the amount is sent to GateQRIS as integer
		// IDR, so reject any order in another currency rather than mis-charging.
		if ( 'IDR' !== $order->get_currency() ) {
			wc_add_notice( __( 'GateQRIS only supports payments in IDR.', 'gateqris-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Reuse an existing, still-valid invoice for this order if one exists,
		// so a refresh or re-submit does not create a duplicate transaction.
		$existing_url = $this->maybe_reuse_existing_invoice( $order, $module );
		if ( null !== $existing_url ) {
			return array(
				'result'   => 'success',
				'redirect' => $existing_url,
			);
		}

		// Authoritative amount: integer rupiah from the order total. IDR has no
		// minor units, so no multiplication — never trust a client-supplied amount.
		$amount = (int) round( floatval( $order->get_total() ) );
		if ( $amount <= 0 ) {
			wc_add_notice( __( 'Order total is invalid for QRIS payment.', 'gateqris-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$attempt         = (int) $order->get_meta( '_gateqris_attempts' );
		$idempotency_key = 'wc-' . $order_id . ( $attempt > 0 ? '-' . $attempt : '' );

		$result = $module->transaction_service()->create_invoice(
			array(
				'amount'              => $amount,
				'customer_name'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_email'      => $order->get_billing_email(),
				'reference'           => 'Order #' . $order_id,
				'idempotency_key'     => $idempotency_key,
				'woo_order_id'        => $order_id,
				'success_redirect_url' => $order->get_checkout_order_received_url(),
				'wallet_owner_type'   => 'site',
				'wallet_owner_id'     => 0,
			),
			'woocommerce_checkout'
		);

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_gateqris_uuid', (string) ( $result['id'] ?? '' ) );
		$order->update_meta_data( '_gateqris_access_token', (string) ( $result['accessToken'] ?? '' ) );
		$order->set_status( 'pending', __( 'GateQRIS invoice created; awaiting QRIS payment.', 'gateqris-payments' ) );
		$order->save();

		wc_reduce_stock_levels( $order );

		return array(
			'result'   => 'success',
			'redirect' => (string) ( $result['hostedUrl'] ?? $order->get_checkout_order_received_url() ),
		);
	}

	/**
	 * Return the hosted URL of an existing pending, non-expired invoice for the
	 * order, or null if a fresh invoice should be created.
	 */
	private function maybe_reuse_existing_invoice( \WC_Order $order, Module $module ): ?string {
		$uuid = (string) $order->get_meta( '_gateqris_uuid' );
		if ( '' === $uuid ) {
			return null;
		}

		$transaction = $module->transactions()->get_by_uuid( $uuid );
		if ( ! $transaction ) {
			return null;
		}

		if ( 'pending_payment' !== (string) $transaction['internal_status'] ) {
			return null;
		}

		// Treat an expired invoice as unusable so a new one is generated.
		$expires_at = (string) ( $transaction['expires_at_gmt'] ?? '' );
		if ( '' !== $expires_at ) {
			$expiry_ts = strtotime( $expires_at . ' UTC' );
			if ( false !== $expiry_ts && $expiry_ts <= time() ) {
				$order->update_meta_data( '_gateqris_attempts', (int) $order->get_meta( '_gateqris_attempts' ) + 1 );
				$order->save();
				return null;
			}
		}

		$payload = $module->transaction_service()->public_payload( $transaction );
		return (string) ( $payload['hostedUrl'] ?? '' ) ?: null;
	}
}
