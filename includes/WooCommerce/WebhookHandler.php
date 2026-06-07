<?php
/**
 * Completes WooCommerce orders when their GateQRIS transaction is paid.
 *
 * @package GRN\GateQris\WooCommerce
 */

namespace GRN\GateQris\WooCommerce;

use GRN\GateQris\Repository\TransactionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * WebhookHandler — listens for processed GateQRIS webhooks and marks the linked
 * WooCommerce order complete. Uses the injected repository (no raw SQL) and the
 * WC_Order CRUD API (HPOS-safe).
 */
final class WebhookHandler {

	private static ?TransactionRepository $transactions = null;

	public static function init( TransactionRepository $transactions ): void {
		self::$transactions = $transactions;
		add_action( 'gateqris_payments_webhook_processed', array( self::class, 'handle_webhook' ), 10, 2 );
	}

	/**
	 * @param array $webhook_payload Raw gateway payload (gateway invoice id under `id`).
	 * @param array $result          public_payload() of the transaction (internal uuid under `id`).
	 */
	public static function handle_webhook( array $webhook_payload, array $result ): void {
		if ( null === self::$transactions ) {
			return;
		}

		// The internal transaction UUID is in the result payload, not the raw webhook.
		$transaction_uuid = $result['id'] ?? null;
		if ( ! $transaction_uuid ) {
			return;
		}

		// Settlement runs before this action fires, so accept the settled states.
		$internal_status = $result['internalStatus'] ?? null;
		if ( ! in_array( $internal_status, array( 'paid_unsettled', 'settled', 'manual_acc', 'paid' ), true ) ) {
			return;
		}

		$transaction = self::$transactions->get_by_uuid( (string) $transaction_uuid );
		if ( ! $transaction ) {
			return;
		}

		$meta     = json_decode( (string) ( $transaction['meta_json'] ?? '' ), true );
		$order_id = is_array( $meta ) ? ( $meta['woo_order_id'] ?? null ) : null;
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Idempotent: only complete an order that is still awaiting payment.
		if ( $order->get_status() !== 'completed' && $order->get_status() !== 'processing' ) {
			$order->payment_complete( (string) $transaction_uuid );
			$order->add_order_note(
				sprintf(
					/* translators: %s: GateQRIS transaction UUID */
					__( 'Payment received via GateQRIS: %s', 'gateqris-payments' ),
					$transaction_uuid
				)
			);
		}
	}
}
