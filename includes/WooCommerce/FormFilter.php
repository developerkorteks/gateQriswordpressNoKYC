<?php
/**
 * Injects WooCommerce order context into GateQRIS transaction metadata.
 *
 * @package GRN\GateQris\WooCommerce
 */

namespace GRN\GateQris\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * FormFilter — adds woo_order_id and success_redirect_url to the transaction
 * metadata so the webhook handler can find the order and the hosted page can
 * return the customer to the order-received screen.
 */
final class FormFilter {

	public static function init(): void {
		add_filter(
			'gateqris_payments_transaction_meta',
			array( self::class, 'inject_order_context' ),
			10,
			3
		);
	}

	/**
	 * @param array  $meta    Existing transaction metadata.
	 * @param array  $payload create_invoice() payload.
	 * @param string $scope   Invoice creation scope.
	 * @return array
	 */
	public static function inject_order_context( array $meta, array $payload, string $scope ): array {
		if ( 'woocommerce_checkout' !== $scope ) {
			return $meta;
		}

		$order_id = isset( $payload['woo_order_id'] ) ? absint( $payload['woo_order_id'] ) : 0;
		if ( $order_id <= 0 ) {
			return $meta;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $meta;
		}

		$meta['woo_order_id'] = $order_id;

		if ( ! empty( $payload['success_redirect_url'] ) ) {
			$meta['success_redirect_url'] = esc_url_raw( (string) $payload['success_redirect_url'] );
		}

		return $meta;
	}
}
