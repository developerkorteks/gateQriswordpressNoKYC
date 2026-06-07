<?php
/**
 * "Pay with Balance" WooCommerce gateway for GateQRIS wallets.
 *
 * @package GRN\GateQris\WooCommerce
 */

namespace GRN\GateQris\WooCommerce;

use GRN\GateQris\Config\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * BalanceGateway — lets a logged-in customer pay an order using their GateQRIS
 * wallet balance. On checkout it transfers the order total from the customer's
 * wallet to the site wallet (a pure, instant DB transaction — no QRIS, no
 * external API call) and completes the order.
 *
 * Disabled by default; merchants enable it to build a store-credit / saldo flow.
 */
final class BalanceGateway extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'gateqris_balance';
		$this->method_title       = __( 'GateQRIS Saldo', 'gateqris-payments' );
		$this->method_description = __( 'Bayar memakai saldo wallet GateQRIS pelanggan (potong saldo, tanpa QRIS).', 'gateqris-payments' );
		$this->title              = __( 'Bayar pakai Saldo', 'gateqris-payments' );
		$this->description        = __( 'Saldo wallet Anda akan dipotong untuk pembayaran ini.', 'gateqris-payments' );
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Bayar dengan Saldo', 'gateqris-payments' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

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

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'gateqris-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable paying with GateQRIS wallet balance', 'gateqris-payments' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'    => __( 'Title', 'gateqris-payments' ),
				'type'     => 'text',
				'default'  => __( 'Bayar pakai Saldo', 'gateqris-payments' ),
				'desc_tip' => true,
			),
			'description' => array(
				'title'    => __( 'Description', 'gateqris-payments' ),
				'type'     => 'textarea',
				'default'  => __( 'Saldo wallet Anda akan dipotong untuk pembayaran ini.', 'gateqris-payments' ),
				'desc_tip' => true,
			),
		);
	}

	public function is_available(): bool {
		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return false;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$module = Module::instance();
		if ( null === $module ) {
			return false;
		}

		if ( 'yes' !== (string) ( new Settings() )->get( 'allow_user_wallets', 'yes' ) ) {
			return false;
		}

		if ( function_exists( 'get_woocommerce_currency' ) && 'IDR' !== get_woocommerce_currency() ) {
			return false;
		}

		// Hide the method when the customer's balance cannot cover the current cart.
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$total  = (int) round( floatval( WC()->cart->get_total( 'edit' ) ) );
			$wallet = $module->wallet_service()->find_wallet( 'user', get_current_user_id() );
			if ( ! $wallet || (int) $wallet['available_balance'] < $total ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Pay the order by debiting the customer's wallet into the site wallet.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return array
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

		if ( 'IDR' !== $order->get_currency() ) {
			wc_add_notice( __( 'GateQRIS only supports payments in IDR.', 'gateqris-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$user_id = $order->get_user_id();
		if ( $user_id <= 0 ) {
			wc_add_notice( __( 'Anda harus masuk (login) untuk membayar dengan saldo.', 'gateqris-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$user_wallet = $module->wallet_service()->resolve_wallet( 'user', $user_id );
		$site_wallet = $module->wallet_service()->resolve_wallet( 'site', 0 );
		if ( ! $user_wallet || ! $site_wallet ) {
			wc_add_notice( __( 'Wallet tidak ditemukan untuk pembayaran ini.', 'gateqris-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// IDR has no minor units; the order total is the integer amount to debit.
		$amount = (int) round( floatval( $order->get_total() ) );
		if ( $amount <= 0 ) {
			wc_add_notice( __( 'Order total is invalid.', 'gateqris-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$result = $module->wallet_transfer()->transfer(
			(int) $user_wallet['id'],
			(int) $site_wallet['id'],
			$amount,
			'purchase_debit',
			'purchase_credit',
			'wc_order',
			(string) $order_id,
			sprintf(
				/* translators: %d: WooCommerce order id */
				__( 'Pembayaran WooCommerce order #%d dengan saldo', 'gateqris-payments' ),
				$order_id
			),
			$user_id
		);

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->payment_complete();
		// Tag the order so a later cancellation/failure/refund can return the balance.
		$order->update_meta_data( '_gateqris_balance_paid', $amount );
		$order->update_meta_data( '_gateqris_balance_payer', $user_id );
		$order->add_order_note(
			sprintf(
				/* translators: %s: formatted amount */
				__( 'Dibayar memakai saldo wallet GateQRIS (Rp %s).', 'gateqris-payments' ),
				number_format_i18n( $amount )
			)
		);
		$order->save();
		wc_reduce_stock_levels( $order );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}
}
