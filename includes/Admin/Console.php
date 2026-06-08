<?php

namespace GRN\GateQris\Admin;

use GRN\GateQris\Domain\WalletAdjustmentService;
use GRN\GateQris\Domain\WalletService;
use GRN\GateQris\Domain\TransactionService;
use GRN\GateQris\Repository\LedgerRepository;
use GRN\GateQris\Repository\SettlementRepository;
use GRN\GateQris\Repository\TransactionRepository;
use GRN\GateQris\Repository\WalletRepository;
use GRN\GateQris\Repository\WebhookEventRepository;

defined( 'ABSPATH' ) || exit;

final class Console {
	public function __construct(
		private readonly TransactionRepository $transactions,
		private readonly WalletRepository $wallets,
		private readonly SettlementRepository $settlements,
		private readonly WebhookEventRepository $webhooks,
		private readonly LedgerRepository $ledger,
		private readonly TransactionService $transaction_service,
		private readonly WalletAdjustmentService $wallet_adjustments,
		private readonly WalletService $wallet_service
	) {}

	public function register(): void {
		add_submenu_page( 'gateqris-payments', __( 'Transactions', 'gateqris-payments' ), __( 'Transactions', 'gateqris-payments' ), 'manage_options', 'gateqris-payments-transactions', array( $this, 'render_transactions' ) );
		add_submenu_page( 'gateqris-payments', __( 'User Summary', 'gateqris-payments' ), __( 'User Summary', 'gateqris-payments' ), 'manage_options', 'gateqris-payments-user-summary', array( $this, 'render_user_summary' ) );
		add_submenu_page( 'gateqris-payments', __( 'Wallets', 'gateqris-payments' ), __( 'Wallets', 'gateqris-payments' ), 'manage_options', 'gateqris-payments-wallets', array( $this, 'render_wallets' ) );
		add_submenu_page( 'gateqris-payments', __( 'Settlements', 'gateqris-payments' ), __( 'Settlements', 'gateqris-payments' ), 'manage_options', 'gateqris-payments-settlements', array( $this, 'render_settlements' ) );
		add_submenu_page( 'gateqris-payments', __( 'Webhook Logs', 'gateqris-payments' ), __( 'Webhook Logs', 'gateqris-payments' ), 'manage_options', 'gateqris-payments-webhooks', array( $this, 'render_webhooks' ) );
		add_submenu_page( 'gateqris-payments', __( 'Tools', 'gateqris-payments' ), __( 'Tools', 'gateqris-payments' ), 'manage_options', 'gateqris-payments-tools', array( $this, 'render_tools' ) );
	}

	public function register_post_actions(): void {
		add_action( 'admin_post_gateqris_create_invoice', array( $this, 'handle_create_invoice' ) );
		add_action( 'admin_post_gateqris_refresh_transaction', array( $this, 'handle_refresh_transaction' ) );
		add_action( 'admin_post_gateqris_run_reconcile', array( $this, 'handle_run_reconcile' ) );
		add_action( 'admin_post_gateqris_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_gateqris_create_test_invoice', array( $this, 'handle_create_test_invoice' ) );
		add_action( 'admin_post_gateqris_export_transactions', array( $this, 'handle_export_transactions' ) );
		add_action( 'admin_post_gateqris_simulate_webhook', array( $this, 'handle_simulate_webhook' ) );
		add_action( 'admin_post_gateqris_adjust_wallet', array( $this, 'handle_adjust_wallet' ) );
		add_action( 'admin_post_gateqris_create_user_wallet', array( $this, 'handle_create_user_wallet' ) );
		add_action( 'admin_post_gateqris_run_cleanup', array( $this, 'handle_run_cleanup' ) );
	}

	public function handle_create_invoice(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_create_invoice' );

		$wallet_owner_type = in_array( $_POST['wallet_owner_type'] ?? 'site', array( 'site', 'user' ), true ) ? sanitize_text_field( (string) $_POST['wallet_owner_type'] ) : 'site'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$wallet_owner_id   = 'user' === $wallet_owner_type ? absint( $_POST['wallet_owner_id'] ?? 0 ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = $this->transaction_service->create_invoice(
			array(
				'amount'            => absint( $_POST['amount'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'customer_name'     => sanitize_text_field( (string) ( $_POST['customer_name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'customer_email'    => sanitize_email( (string) ( $_POST['customer_email'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'reference'         => sanitize_text_field( (string) ( $_POST['reference'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'idempotency_key'   => wp_generate_password( 20, false, false ),
				'wallet_owner_type' => $wallet_owner_type,
				'wallet_owner_id'   => $wallet_owner_id,
			),
			'admin_invoice'
		);

		$redirect = admin_url( 'admin.php?page=gateqris-payments-transactions' );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'gateqris_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg(
				array(
					'gateqris_created' => 1,
					'transaction_uuid' => $result['id'],
				),
				$redirect
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_refresh_transaction(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_refresh_transaction' );
		$uuid        = sanitize_text_field( (string) ( $_POST['uuid'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$transaction = $this->transactions->get_by_uuid( $uuid );
		if ( $transaction ) {
			$this->transaction_service->refresh_transaction( $transaction );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=gateqris-payments-transactions' ) );
		exit;
	}

	public function handle_run_reconcile(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_run_reconcile' );

		$processed = 0;
		foreach ( $this->transactions->find_pending_for_polling( 50 ) as $transaction ) {
			$result = $this->transaction_service->refresh_transaction( $transaction );
			if ( ! is_wp_error( $result ) ) {
				++$processed;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => 'gateqris-payments-tools',
					'gateqris_reconciled' => $processed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_test_connection(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_test_connection' );

		$result = $this->transaction_service->create_invoice(
			array(
				'amount'            => 1000,
				'customer_name'     => 'GateQRIS Connection Test',
				'customer_email'    => 'connection-test@example.invalid',
				'reference'         => 'CONNECTION-TEST-' . gmdate( 'YmdHis' ),
				'idempotency_key'   => 'conn-' . wp_generate_password( 16, false, false ),
				'wallet_owner_type' => 'site',
				'wallet_owner_id'   => 0,
			),
			'admin_connection_test'
		);

		$this->redirect_after_invoice_attempt( $result, 'gateqris-payments-tools', 'gateqris_connection_ok' );
	}

	public function handle_create_test_invoice(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_create_test_invoice' );

		$result = $this->transaction_service->create_invoice(
			array(
				'amount'            => 1000,
				'customer_name'     => 'GateQRIS Test Invoice',
				'customer_email'    => 'test-invoice@example.invalid',
				'reference'         => 'TEST-INVOICE-' . gmdate( 'YmdHis' ),
				'idempotency_key'   => 'test-' . wp_generate_password( 16, false, false ),
				'wallet_owner_type' => 'site',
				'wallet_owner_id'   => 0,
			),
			'admin_test_invoice'
		);

		$this->redirect_after_invoice_attempt( $result, 'gateqris-payments-transactions', 'gateqris_test_created' );
	}

	public function handle_export_transactions(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_export_transactions' );

		$rows = $this->transactions->export_rows( 1000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=gateqris-transactions-' . gmdate( 'Ymd-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Unable to open export stream.', 'gateqris-payments' ) );
		}

		fputcsv(
			$output,
			array(
				'uuid',
				'customer_ref',
				'public_reference',
				'source_type',
				'base_amount',
				'unique_code',
				'total_amount',
				'currency',
				'gateway_invoice_id',
				'gateway_status',
				'internal_status',
				'payment_confirmation_type',
				'wallet_owner_type',
				'wallet_owner_id',
				'created_at_gmt',
				'paid_at_gmt',
				'settled_at_gmt',
			)
		);

		foreach ( $rows as $row ) {
			fputcsv( $output, array_map( array( $this, 'sanitize_csv_cell' ), $row ) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Prevent CSV formula injection by prefixing cells that start with a
	 * formula trigger character with a single quote.
	 */
	private function sanitize_csv_cell( mixed $value ): string {
		$str = (string) $value;
		if ( '' !== $str && in_array( $str[0], array( '=', '+', '-', '@', '\t', '\r' ), true ) ) {
			return "'" . $str;
		}
		return $str;
	}

	public function handle_simulate_webhook(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_simulate_webhook' );

		$uuid   = sanitize_text_field( (string) ( $_POST['uuid'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status = strtoupper( sanitize_text_field( (string) ( $_POST['simulate_status'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $status, array( 'PAID', 'MANUAL_ACC', 'EXPIRED', 'PENDING' ), true ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'gateqris-payments-tools',
						'gateqris_error' => rawurlencode( __( 'Invalid simulation status.', 'gateqris-payments' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$transaction = $this->transactions->get_by_uuid( $uuid );
		if ( ! $transaction ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'gateqris-payments-tools',
						'gateqris_error' => rawurlencode( __( 'Transaction not found.', 'gateqris-payments' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$payload = array(
			'id'          => (string) $transaction['gateway_invoice_id'],
			'status'      => $status,
			'totalAmount' => (int) $transaction['total_amount'],
			'customerRef' => (string) $transaction['customer_ref'],
		);

		$result = $this->transaction_service->apply_gateway_update( $transaction, $payload, 'admin_simulation' );
		$redirect_args = array(
			'page'                    => 'gateqris-payments-tools',
			'gateqris_simulated'      => 1,
			'gateqris_simulated_uuid' => $uuid,
			'gateqris_simulated_status' => $status,
		);

		if ( is_wp_error( $result ) ) {
			$redirect_args['gateqris_error'] = rawurlencode( $result->get_error_message() );
			unset( $redirect_args['gateqris_simulated'] );
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_adjust_wallet(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_adjust_wallet' );

		$wallet_id  = absint( $_POST['wallet_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$direction  = sanitize_text_field( (string) ( $_POST['direction'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$amount     = absint( $_POST['amount'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$reason     = sanitize_textarea_field( (string) ( $_POST['reason'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect   = add_query_arg(
			array(
				'page'        => 'gateqris-payments-wallets',
				'view_wallet' => $wallet_id,
			),
			admin_url( 'admin.php' )
		);

		if ( '' === $reason ) {
			wp_safe_redirect( add_query_arg( 'gateqris_error', rawurlencode( __( 'Adjustment reason is required.', 'gateqris-payments' ) ), $redirect ) );
			exit;
		}

		$result = $this->wallet_adjustments->adjust( $wallet_id, $direction, $amount, $reason, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'gateqris_error', rawurlencode( $result->get_error_message() ), $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'gateqris_wallet_adjusted' => 1,
					'gateqris_adjustment_type' => $direction,
					'gateqris_adjustment_amount' => $amount,
				),
				$redirect
			)
		);
		exit;
	}

	public function handle_create_user_wallet(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_create_user_wallet' );

		$user_id = absint( $_POST['wallet_user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect = admin_url( 'admin.php?page=gateqris-payments-wallets' );

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'gateqris_error', rawurlencode( __( 'Please select a valid WordPress user.', 'gateqris-payments' ) ), $redirect ) );
			exit;
		}

		$wallet = $this->wallet_service->resolve_wallet( 'user', $user_id );
		if ( ! $wallet ) {
			wp_safe_redirect( add_query_arg( 'gateqris_error', rawurlencode( __( 'User wallet could not be created.', 'gateqris-payments' ) ), $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => 'gateqris-payments-wallets',
					'view_wallet'           => (int) $wallet['id'],
					'gateqris_wallet_created' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_run_cleanup(): void {
		$this->assert_admin();
		check_admin_referer( 'gateqris_run_cleanup' );

		do_action( 'gateqris_payments_cleanup' );

		global $wpdb;
		$cutoff_i = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$cutoff_w = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$del_i    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}gq_idempotency_keys WHERE created_at_gmt < %s LIMIT 1000", $cutoff_i ) );
		$del_w    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}gq_webhook_events WHERE created_at_gmt < %s LIMIT 1000", $cutoff_w ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                        => 'gateqris-payments-tools',
					'gateqris_cleaned'            => 1,
					'gateqris_cleaned_idempotency' => $del_i,
					'gateqris_cleaned_webhooks'   => $del_w,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_transactions(): void {
		$this->assert_admin();
		$per_page         = 25;
		$current_page     = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset           = ( $current_page - 1 ) * $per_page;
		$search_term      = ! empty( $_GET['gateqris_search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['gateqris_search'] ) ) : '';
		$gateway_filter   = ! empty( $_GET['gateqris_gateway_status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['gateqris_gateway_status'] ) ) : '';
		$internal_filter  = ! empty( $_GET['gateqris_internal_status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['gateqris_internal_status'] ) ) : '';
		$source_filter    = ! empty( $_GET['gateqris_update_source'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['gateqris_update_source'] ) ) : '';
		$wallet_filter    = ! empty( $_GET['gateqris_wallet_filter'] ) ? absint( $_GET['gateqris_wallet_filter'] ) : 0;
		$total_items      = $this->transactions->count_search( $search_term, $gateway_filter, $internal_filter, $source_filter, $wallet_filter );
		$total_pages      = max( 1, (int) ceil( $total_items / $per_page ) );
		$current_page     = min( $current_page, $total_pages );
		$transactions     = $this->transactions->search( $search_term, $gateway_filter, $internal_filter, $source_filter, $wallet_filter, $per_page, $offset );
		$users            = get_users( array( 'number' => 50, 'fields' => array( 'ID', 'display_name' ) ) );
		$selected_uuid = ! empty( $_GET['view_transaction'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['view_transaction'] ) ) : '';
		$selected      = $selected_uuid ? $this->transactions->get_by_uuid( $selected_uuid ) : null;
		$gateway_options = array( '', 'PENDING', 'PAID', 'MANUAL_ACC', 'EXPIRED' );
		$internal_options = array( '', 'draft', 'pending_payment', 'paid_unsettled', 'settled', 'expired', 'reconciled', 'error' );
		$source_options = array( '', 'create_invoice', 'create_invoice_error', 'polling', 'webhook', 'admin_simulation' );

		// Get all wallet accounts for filter dropdown. Batch-load user display
		// names to avoid an N+1 query per wallet row.
		$wallet_accounts = $this->wallets->all( 100 );
		$wallet_user_ids = array_filter( array_column( $wallet_accounts, 'owner_id' ) );
		$wallet_users    = array();
		if ( ! empty( $wallet_user_ids ) ) {
			foreach ( get_users( array( 'include' => $wallet_user_ids, 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
				$wallet_users[ (int) $u->ID ] = $u->display_name;
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GateQRIS Transactions', 'gateqris-payments' ); ?></h1>
			<?php if ( ! empty( $_GET['gateqris_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( wp_unslash( (string) $_GET['gateqris_error'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['gateqris_created'] ) && ! empty( $_GET['transaction_uuid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $created = $this->transactions->get_by_uuid( sanitize_text_field( wp_unslash( (string) $_GET['transaction_uuid'] ) ) ); ?>
				<?php if ( $created ) : ?>
					<div class="notice notice-success"><p><?php esc_html_e( 'Invoice created successfully.', 'gateqris-payments' ); ?> <a href="<?php echo esc_url( $this->transaction_service->public_payload( $created )['hostedUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open hosted payment page', 'gateqris-payments' ); ?></a></p></div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['gateqris_test_created'] ) && ! empty( $_GET['transaction_uuid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $created_test = $this->transactions->get_by_uuid( sanitize_text_field( wp_unslash( (string) $_GET['transaction_uuid'] ) ) ); ?>
				<?php if ( $created_test ) : ?>
					<div class="notice notice-success"><p><?php esc_html_e( 'Test invoice created successfully.', 'gateqris-payments' ); ?> <a href="<?php echo esc_url( $this->transaction_service->public_payload( $created_test )['hostedUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open hosted payment page', 'gateqris-payments' ); ?></a></p></div>
				<?php endif; ?>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Create Admin Invoice', 'gateqris-payments' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gateqris_create_invoice" />
				<?php wp_nonce_field( 'gateqris_create_invoice' ); ?>
				<table class="form-table">
					<tr><th><label for="gq-amount"><?php esc_html_e( 'Amount', 'gateqris-payments' ); ?></label></th><td><input id="gq-amount" name="amount" type="number" min="1" required /></td></tr>
					<tr><th><label for="gq-name"><?php esc_html_e( 'Customer Name', 'gateqris-payments' ); ?></label></th><td><input id="gq-name" name="customer_name" type="text" /></td></tr>
					<tr><th><label for="gq-email"><?php esc_html_e( 'Customer Email', 'gateqris-payments' ); ?></label></th><td><input id="gq-email" name="customer_email" type="email" /></td></tr>
					<tr><th><label for="gq-reference"><?php esc_html_e( 'Reference', 'gateqris-payments' ); ?></label></th><td><input id="gq-reference" name="reference" type="text" /></td></tr>
					<tr><th><?php esc_html_e( 'Wallet Owner Type', 'gateqris-payments' ); ?></th><td><select name="wallet_owner_type"><option value="site"><?php esc_html_e( 'Site', 'gateqris-payments' ); ?></option><option value="user"><?php esc_html_e( 'User', 'gateqris-payments' ); ?></option></select></td></tr>
					<tr><th><?php esc_html_e( 'Wallet User', 'gateqris-payments' ); ?></th><td><select name="wallet_owner_id"><option value="0"><?php esc_html_e( 'Select user', 'gateqris-payments' ); ?></option><?php foreach ( $users as $user ) : ?><option value="<?php echo esc_attr( (string) $user->ID ); ?>"><?php echo esc_html( $user->display_name ); ?></option><?php endforeach; ?></select></td></tr>
				</table>
				<?php submit_button( __( 'Create Invoice', 'gateqris-payments' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="gateqris_create_test_invoice" />
				<?php wp_nonce_field( 'gateqris_create_test_invoice' ); ?>
				<?php submit_button( __( 'Create Test Invoice (Rp 1.000)', 'gateqris-payments' ), 'secondary', '', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent Transactions', 'gateqris-payments' ); ?></h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:0 0 16px">
				<input type="hidden" name="page" value="gateqris-payments-transactions" />
				<p class="search-box" style="float:none;display:flex;gap:8px;flex-wrap:wrap;align-items:center;max-width:none">
					<label class="screen-reader-text" for="gateqris-search-input"><?php esc_html_e( 'Search transactions', 'gateqris-payments' ); ?></label>
					<input id="gateqris-search-input" type="search" name="gateqris_search" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php esc_attr_e( 'Search UUID, reference, invoice ID', 'gateqris-payments' ); ?>" />
					<select name="gateqris_gateway_status">
						<?php foreach ( $gateway_options as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $gateway_filter, $option ); ?>><?php echo esc_html( '' === $option ? __( 'All gateway statuses', 'gateqris-payments' ) : $option ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="gateqris_internal_status">
						<?php foreach ( $internal_options as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $internal_filter, $option ); ?>><?php echo esc_html( '' === $option ? __( 'All internal statuses', 'gateqris-payments' ) : $option ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="gateqris_update_source">
						<?php foreach ( $source_options as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $source_filter, $option ); ?>><?php echo esc_html( '' === $option ? __( 'All update sources', 'gateqris-payments' ) : $option ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="gateqris_wallet_filter">
						<option value="0" <?php selected( $wallet_filter, 0 ); ?>><?php esc_html_e( 'All wallets', 'gateqris-payments' ); ?></option>
						<?php foreach ( $wallet_accounts as $wallet ) : ?>
							<?php
							$wallet_label = 'site' === $wallet['owner_type']
								? __( 'Site Wallet', 'gateqris-payments' )
								: sprintf( __( 'User: %s', 'gateqris-payments' ), $wallet_users[ (int) $wallet['owner_id'] ] ?? 'Unknown' );
							?>
							<option value="<?php echo esc_attr( (string) $wallet['id'] ); ?>" <?php selected( $wallet_filter, (int) $wallet['id'] ); ?>><?php echo esc_html( $wallet_label ); ?></option>
						<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Filter', 'gateqris-payments' ), 'secondary', '', false ); ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gateqris-payments-transactions' ) ); ?>"><?php esc_html_e( 'Reset', 'gateqris-payments' ); ?></a>
					</p>
				</form>
				<p class="tablenav">
					<?php echo esc_html( sprintf( _n( '%s item', '%s items', $total_items, 'gateqris-payments' ), number_format_i18n( $total_items ) ) ); ?>
					&nbsp;&mdash;&nbsp;
					<?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'gateqris-payments' ), $current_page, $total_pages ) ); ?>
					<?php if ( $current_page > 1 ) : ?>
						&nbsp;<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'gateqris-payments' ); ?></a>
					<?php endif; ?>
					<?php if ( $current_page < $total_pages ) : ?>
						&nbsp;<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'gateqris-payments' ); ?> &raquo;</a>
					<?php endif; ?>
				</p>
				<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'UUID', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Reference', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Gateway', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Internal', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Update Source', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Total', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Hosted Link', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Actions', 'gateqris-payments' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $transactions as $transaction ) : ?>
					<?php $public = $this->transaction_service->public_payload( $transaction ); ?>
					<tr>
						<td><code><?php echo esc_html( (string) $transaction['uuid'] ); ?></code></td>
						<td><?php echo esc_html( (string) $transaction['customer_ref'] ); ?></td>
						<td><?php echo esc_html( (string) $transaction['gateway_status'] ); ?></td>
						<td><?php echo esc_html( (string) $transaction['internal_status'] ); ?></td>
						<td><?php echo esc_html( (string) ( $transaction['last_update_source'] ?: '-' ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $transaction['total_amount'] ) ); ?></td>
						<td><a href="<?php echo esc_url( $public['hostedUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'gateqris-payments' ); ?></a></td>
						<td>
							<p><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'gateqris-payments-transactions', 'view_transaction' => $transaction['uuid'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View', 'gateqris-payments' ); ?></a></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="gateqris_refresh_transaction" />
								<input type="hidden" name="uuid" value="<?php echo esc_attr( (string) $transaction['uuid'] ); ?>" />
								<?php wp_nonce_field( 'gateqris_refresh_transaction' ); ?>
								<?php submit_button( __( 'Refresh', 'gateqris-payments' ), 'secondary small', '', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $selected ) : ?>
				<?php
				$public      = $this->transaction_service->public_payload( $selected );
				$settlement  = $this->settlements->get_by_transaction_id( (int) $selected['id'] );
				$wallet      = ! empty( $selected['wallet_account_id'] ) ? $this->wallets->get_by_id( (int) $selected['wallet_account_id'] ) : null;
				$webhook_rows = ! empty( $selected['gateway_invoice_id'] ) ? $this->webhooks->for_invoice( (string) $selected['gateway_invoice_id'], 10 ) : array();
				$meta = json_decode( (string) ( $selected['meta_json'] ?? '' ), true );
				?>
				<h2><?php esc_html_e( 'Transaction Detail', 'gateqris-payments' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr><th><?php esc_html_e( 'UUID', 'gateqris-payments' ); ?></th><td><code><?php echo esc_html( (string) $selected['uuid'] ); ?></code></td></tr>
						<tr><th><?php esc_html_e( 'Hosted URL', 'gateqris-payments' ); ?></th><td><a href="<?php echo esc_url( $public['hostedUrl'] ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $public['hostedUrl'] ); ?></code></a></td></tr>
						<tr><th><?php esc_html_e( 'Customer Ref', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $selected['customer_ref'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Gateway Invoice ID', 'gateqris-payments' ); ?></th><td><code><?php echo esc_html( (string) $selected['gateway_invoice_id'] ); ?></code></td></tr>
						<tr><th><?php esc_html_e( 'Gateway Status', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $selected['gateway_status'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Internal Status', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $selected['internal_status'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Last Update Source', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) ( $selected['last_update_source'] ?: '-' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Confirmation Type', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $selected['payment_confirmation_type'] ?: '-' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Amount', 'gateqris-payments' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $selected['base_amount'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Total Amount', 'gateqris-payments' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $selected['total_amount'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Customer', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) ( $meta['customer_name'] ?? '-' ) . ' / ' . (string) ( $meta['customer_email'] ?? '-' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'QRIS String', 'gateqris-payments' ); ?></th><td><textarea readonly rows="5" style="width:100%"><?php echo esc_textarea( (string) $selected['qris_string'] ); ?></textarea></td></tr>
					</tbody>
				</table>
				<?php if ( $settlement ) : ?>
					<h3><?php esc_html_e( 'Settlement', 'gateqris-payments' ); ?></h3>
					<table class="widefat striped">
						<tbody>
							<tr><th><?php esc_html_e( 'Settlement UUID', 'gateqris-payments' ); ?></th><td><code><?php echo esc_html( (string) $settlement['settlement_uuid'] ); ?></code></td></tr>
							<tr><th><?php esc_html_e( 'Net Amount', 'gateqris-payments' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $settlement['net_amount'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Wallet', 'gateqris-payments' ); ?></th><td><?php echo esc_html( $wallet ? (string) $wallet['account_code'] : '-' ); ?></td></tr>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( $webhook_rows ) : ?>
					<h3><?php esc_html_e( 'Recent Webhook Events', 'gateqris-payments' ); ?></h3>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Event', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Result', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Processed', 'gateqris-payments' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $webhook_rows as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $row['event_uid'] ); ?></code></td>
								<td><?php echo esc_html( (string) $row['result'] ); ?></td>
								<td><?php echo esc_html( (string) $row['processed_at_gmt'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_wallets(): void {
		$this->assert_admin();
		$wallets = $this->wallets->all();
		$selected_wallet_id = ! empty( $_GET['view_wallet'] ) ? absint( $_GET['view_wallet'] ) : 0;
		$selected_wallet    = $selected_wallet_id > 0 ? $this->wallets->get_by_id( $selected_wallet_id ) : null;
		$users              = get_users( array( 'number' => 100, 'fields' => array( 'ID', 'display_name' ) ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wallets', 'gateqris-payments' ); ?></h1>
			<?php if ( ! empty( $_GET['gateqris_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( wp_unslash( (string) $_GET['gateqris_error'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['gateqris_wallet_adjusted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Wallet adjusted successfully: %1$s %2$s.', 'gateqris-payments' ), sanitize_text_field( wp_unslash( (string) $_GET['gateqris_adjustment_type'] ) ), number_format_i18n( absint( $_GET['gateqris_adjustment_amount'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['gateqris_wallet_created'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'User wallet created successfully.', 'gateqris-payments' ); ?></p></div>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Create User Wallet', 'gateqris-payments' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gateqris_create_user_wallet" />
				<?php wp_nonce_field( 'gateqris_create_user_wallet' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="gq-wallet-user-id"><?php esc_html_e( 'WordPress User', 'gateqris-payments' ); ?></label></th>
						<td>
							<select id="gq-wallet-user-id" name="wallet_user_id" required>
								<option value="0"><?php esc_html_e( 'Select user', 'gateqris-payments' ); ?></option>
								<?php foreach ( $users as $user ) : ?>
									<option value="<?php echo esc_attr( (string) $user->ID ); ?>"><?php echo esc_html( $user->display_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Create User Wallet', 'gateqris-payments' ), 'secondary' ); ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Code', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Owner', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Balance', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Entries', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Actions', 'gateqris-payments' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $wallets as $wallet ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) $wallet['account_code'] ); ?></code></td>
						<td><?php echo esc_html( $this->wallet_owner_label( $wallet ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $wallet['available_balance'] ) ); ?></td>
						<td><?php echo esc_html( count( $this->ledger->for_wallet( (int) $wallet['id'], 20 ) ) ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'gateqris-payments-wallets', 'view_wallet' => (int) $wallet['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View', 'gateqris-payments' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $selected_wallet ) : ?>
				<?php
				$owner_user          = 'user' === (string) $selected_wallet['owner_type'] ? get_userdata( (int) $selected_wallet['owner_id'] ) : null;
				$wallet_transactions = $this->transactions->for_wallet_owner( (string) $selected_wallet['owner_type'], (int) $selected_wallet['owner_id'], 20 );
				$wallet_settlements  = $this->settlements->for_wallet( (int) $selected_wallet['id'], 20 );
				$wallet_ledger       = $this->ledger->for_wallet( (int) $selected_wallet['id'], 20 );
				?>
				<h2><?php esc_html_e( 'Wallet Detail', 'gateqris-payments' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr><th><?php esc_html_e( 'Wallet ID', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $selected_wallet['id'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Account Code', 'gateqris-payments' ); ?></th><td><code><?php echo esc_html( (string) $selected_wallet['account_code'] ); ?></code></td></tr>
						<tr><th><?php esc_html_e( 'Owner', 'gateqris-payments' ); ?></th><td><?php echo esc_html( $this->wallet_owner_label( $selected_wallet ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Currency', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $selected_wallet['currency'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Available Balance', 'gateqris-payments' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $selected_wallet['available_balance'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Pending Balance', 'gateqris-payments' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $selected_wallet['pending_balance'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Reserved Balance', 'gateqris-payments' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $selected_wallet['reserved_balance'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Created At (GMT)', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $selected_wallet['created_at_gmt'] ); ?></td></tr>
					</tbody>
				</table>

				<?php if ( $owner_user ) : ?>
					<h3><?php esc_html_e( 'User Detail', 'gateqris-payments' ); ?></h3>
					<table class="widefat striped">
						<tbody>
							<tr><th><?php esc_html_e( 'User ID', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $owner_user->ID ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Username', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $owner_user->user_login ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Display Name', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $owner_user->display_name ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Email', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $owner_user->user_email ); ?></td></tr>
						</tbody>
					</table>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Recent Transactions For This Wallet Owner', 'gateqris-payments' ); ?></h3>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'UUID', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Reference', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Gateway', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Internal', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Total', 'gateqris-payments' ); ?></th></tr></thead>
					<tbody>
					<?php if ( $wallet_transactions ) : ?>
						<?php foreach ( $wallet_transactions as $transaction ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'gateqris-payments-transactions', 'view_transaction' => (string) $transaction['uuid'] ), admin_url( 'admin.php' ) ) ); ?>"><code><?php echo esc_html( (string) $transaction['uuid'] ); ?></code></a></td>
								<td><?php echo esc_html( (string) $transaction['customer_ref'] ); ?></td>
								<td><?php echo esc_html( (string) $transaction['gateway_status'] ); ?></td>
								<td><?php echo esc_html( (string) $transaction['internal_status'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $transaction['total_amount'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No transactions found for this wallet owner yet.', 'gateqris-payments' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Recent Settlements', 'gateqris-payments' ); ?></h3>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Settlement UUID', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Transaction ID', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Net Amount', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Status', 'gateqris-payments' ); ?></th></tr></thead>
					<tbody>
					<?php if ( $wallet_settlements ) : ?>
						<?php foreach ( $wallet_settlements as $settlement ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $settlement['settlement_uuid'] ); ?></code></td>
								<td><?php echo esc_html( (string) $settlement['transaction_id'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $settlement['net_amount'] ) ); ?></td>
								<td><?php echo esc_html( (string) $settlement['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No settlements found for this wallet yet.', 'gateqris-payments' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Recent Ledger Entries', 'gateqris-payments' ); ?></h3>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Entry UUID', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Type', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Amount', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Balance After', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Created At', 'gateqris-payments' ); ?></th></tr></thead>
					<tbody>
					<?php if ( $wallet_ledger ) : ?>
						<?php foreach ( $wallet_ledger as $entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $entry['entry_uuid'] ); ?></code></td>
								<td><?php echo esc_html( (string) $entry['entry_type'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $entry['amount'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $entry['balance_after'] ) ); ?></td>
								<td><?php echo esc_html( (string) $entry['created_at_gmt'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No ledger entries found for this wallet yet.', 'gateqris-payments' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Adjust Wallet Balance', 'gateqris-payments' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="gateqris_adjust_wallet" />
					<input type="hidden" name="wallet_id" value="<?php echo esc_attr( (string) $selected_wallet['id'] ); ?>" />
					<?php wp_nonce_field( 'gateqris_adjust_wallet' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="gq-wallet-direction"><?php esc_html_e( 'Direction', 'gateqris-payments' ); ?></label></th>
							<td>
								<select id="gq-wallet-direction" name="direction">
									<option value="credit"><?php esc_html_e( 'Credit', 'gateqris-payments' ); ?></option>
									<option value="debit"><?php esc_html_e( 'Debit', 'gateqris-payments' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="gq-wallet-amount"><?php esc_html_e( 'Amount', 'gateqris-payments' ); ?></label></th>
							<td><input id="gq-wallet-amount" name="amount" type="number" min="1" required /></td>
						</tr>
						<tr>
							<th><label for="gq-wallet-reason"><?php esc_html_e( 'Reason', 'gateqris-payments' ); ?></label></th>
							<td><textarea id="gq-wallet-reason" name="reason" rows="4" class="large-text" required></textarea></td>
						</tr>
					</table>
					<?php submit_button( __( 'Apply Wallet Adjustment', 'gateqris-payments' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_settlements(): void {
		$this->assert_admin();
		$wallet_filter    = ! empty( $_GET['gateqris_wallet_filter'] ) ? absint( $_GET['gateqris_wallet_filter'] ) : 0;
		$rows             = $this->settlements->all( $wallet_filter );
		$wallet_accounts  = $this->wallets->all( 100 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settlements', 'gateqris-payments' ); ?></h1>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:0 0 16px">
				<input type="hidden" name="page" value="gateqris-payments-settlements" />
				<p class="search-box" style="float:none;display:flex;gap:8px;flex-wrap:wrap;align-items:center;max-width:none">
					<select name="gateqris_wallet_filter">
						<option value="0" <?php selected( $wallet_filter, 0 ); ?>><?php esc_html_e( 'All wallets', 'gateqris-payments' ); ?></option>
						<?php foreach ( $wallet_accounts as $wallet ) : ?>
							<?php
							$wallet_label = 'site' === $wallet['owner_type']
								? __( 'Site Wallet', 'gateqris-payments' )
								: sprintf( __( 'User: %s', 'gateqris-payments' ), get_user_by( 'ID', (int) $wallet['owner_id'] )->display_name ?? 'Unknown' );
							?>
							<option value="<?php echo esc_attr( (string) $wallet['id'] ); ?>" <?php selected( $wallet_filter, (int) $wallet['id'] ); ?>><?php echo esc_html( $wallet_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'gateqris-payments' ), 'secondary', '', false ); ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gateqris-payments-settlements' ) ); ?>"><?php esc_html_e( 'Reset', 'gateqris-payments' ); ?></a>
				</p>
			</form>

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Settlement UUID', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Wallet', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Transaction', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Net', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Status', 'gateqris-payments' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $wallet_info = $this->wallets->get_by_id( (int) $row['wallet_account_id'] ); ?>
					<?php
					$wallet_label = $wallet_info && 'site' === $wallet_info['owner_type']
						? __( 'Site Wallet', 'gateqris-payments' )
						: ($wallet_info ? sprintf( __( 'User: %s', 'gateqris-payments' ), get_user_by( 'ID', (int) $wallet_info['owner_id'] )->display_name ?? 'Unknown' ) : 'Unknown');
					?>
					<tr>
						<td><code><?php echo esc_html( (string) $row['settlement_uuid'] ); ?></code></td>
						<td><?php echo esc_html( $wallet_label ); ?></td>
						<td><?php echo esc_html( (string) $row['transaction_id'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['net_amount'] ) ); ?></td>
						<td><?php echo esc_html( (string) $row['status'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function render_webhooks(): void {
		$this->assert_admin();
		$rows = $this->webhooks->recent();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Webhook Logs', 'gateqris-payments' ); ?></h1>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Event', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Invoice', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Result', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Processed At', 'gateqris-payments' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) $row['event_uid'] ); ?></code></td>
						<td><?php echo esc_html( (string) $row['gateway_invoice_id'] ); ?></td>
						<td><?php echo esc_html( (string) $row['result'] ); ?></td>
						<td><?php echo esc_html( (string) $row['processed_at_gmt'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function render_tools(): void {
		$this->assert_admin();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GateQRIS Tools', 'gateqris-payments' ); ?></h1>
			<p><?php esc_html_e( 'Use the transaction refresh action to reconcile pending invoices. Polling also runs automatically via WP-Cron.', 'gateqris-payments' ); ?></p>
			<?php if ( ! empty( $_GET['gateqris_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( wp_unslash( (string) $_GET['gateqris_error'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['gateqris_reconciled'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Reconciled %d pending transactions.', 'gateqris-payments' ), absint( $_GET['gateqris_reconciled'] ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['gateqris_simulated'] ) && ! empty( $_GET['gateqris_simulated_uuid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Simulated %1$s for transaction %2$s.', 'gateqris-payments' ), sanitize_text_field( wp_unslash( (string) $_GET['gateqris_simulated_status'] ) ), sanitize_text_field( wp_unslash( (string) $_GET['gateqris_simulated_uuid'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['gateqris_connection_ok'] ) && ! empty( $_GET['transaction_uuid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $connection_tx = $this->transactions->get_by_uuid( sanitize_text_field( wp_unslash( (string) $_GET['transaction_uuid'] ) ) ); ?>
				<?php if ( $connection_tx ) : ?>
					<div class="notice notice-success"><p><?php esc_html_e( 'Connection test succeeded. GateQRIS returned a live invoice response.', 'gateqris-payments' ); ?> <a href="<?php echo esc_url( $this->transaction_service->public_payload( $connection_tx )['hostedUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open hosted payment page', 'gateqris-payments' ); ?></a></p></div>
				<?php endif; ?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gateqris_run_reconcile" />
				<?php wp_nonce_field( 'gateqris_run_reconcile' ); ?>
				<?php submit_button( __( 'Run Reconcile Now', 'gateqris-payments' ), 'primary' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="gateqris_test_connection" />
				<?php wp_nonce_field( 'gateqris_test_connection' ); ?>
				<?php submit_button( __( 'Test Connection', 'gateqris-payments' ), 'secondary', '', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="gateqris_export_transactions" />
				<?php wp_nonce_field( 'gateqris_export_transactions' ); ?>
				<?php submit_button( __( 'Export Transactions CSV', 'gateqris-payments' ), 'secondary', '', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="gateqris_run_cleanup" />
				<?php wp_nonce_field( 'gateqris_run_cleanup' ); ?>
				<?php submit_button( __( 'Run Table Cleanup Now', 'gateqris-payments' ), 'secondary', '', false ); ?>
				<p class="description"><?php esc_html_e( 'Deletes idempotency keys older than 30 days and webhook event logs older than 90 days. Also runs automatically every week via WP-Cron.', 'gateqris-payments' ); ?></p>
			</form>
			<?php if ( isset( $_GET['gateqris_cleaned'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( __( 'Cleanup done: %1$d idempotency keys and %2$d webhook events deleted.', 'gateqris-payments' ), absint( $_GET['gateqris_cleaned_idempotency'] ?? 0 ), absint( $_GET['gateqris_cleaned_webhooks'] ?? 0 ) ) ); ?></p></div>
			<?php endif; ?>
			<h2 style="margin-top:24px"><?php esc_html_e( 'Webhook Simulator', 'gateqris-payments' ); ?></h2>
			<p><?php esc_html_e( 'Simulate a provider status update for one transaction without requiring a public webhook endpoint.', 'gateqris-payments' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gateqris_simulate_webhook" />
				<?php wp_nonce_field( 'gateqris_simulate_webhook' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="gq-sim-uuid"><?php esc_html_e( 'Transaction UUID', 'gateqris-payments' ); ?></label></th>
						<td><input id="gq-sim-uuid" name="uuid" type="text" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="gq-sim-status"><?php esc_html_e( 'Simulated Status', 'gateqris-payments' ); ?></label></th>
						<td>
							<select id="gq-sim-status" name="simulate_status">
								<option value="PAID"><?php esc_html_e( 'PAID', 'gateqris-payments' ); ?></option>
								<option value="MANUAL_ACC"><?php esc_html_e( 'MANUAL_ACC', 'gateqris-payments' ); ?></option>
								<option value="EXPIRED"><?php esc_html_e( 'EXPIRED', 'gateqris-payments' ); ?></option>
								<option value="PENDING"><?php esc_html_e( 'PENDING', 'gateqris-payments' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Simulate Status Update', 'gateqris-payments' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	private function redirect_after_invoice_attempt( array|\WP_Error $result, string $page, string $success_flag ): void {
		$redirect = admin_url( 'admin.php?page=' . rawurlencode( $page ) );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'gateqris_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg(
				array(
					$success_flag       => 1,
					'transaction_uuid'  => $result['id'],
				),
				$redirect
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	private function assert_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gateqris-payments' ) );
		}
	}

	private function wallet_owner_label( array $wallet ): string {
		if ( 'site' === (string) $wallet['owner_type'] ) {
			return __( 'Site Wallet', 'gateqris-payments' );
		}

		$user = get_userdata( (int) $wallet['owner_id'] );
		if ( $user ) {
			return sprintf(
				/* translators: 1: user display name, 2: user ID */
				__( '%1$s (User #%2$d)', 'gateqris-payments' ),
				$user->display_name,
				$user->ID
			);
		}

		return sprintf(
			/* translators: %d: user ID */
			__( 'User #%d', 'gateqris-payments' ),
			(int) $wallet['owner_id']
		);
	}

	public function render_user_summary(): void {
		$this->assert_admin();
		$user_id = ! empty( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$users   = get_users( array( 'number' => 100, 'fields' => array( 'ID', 'display_name' ) ) );

		if ( ! $user_id ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'User Payment Summary', 'gateqris-payments' ); ?></h1>
				<p><?php esc_html_e( 'Select a user to view their payment activity and wallet information.', 'gateqris-payments' ); ?></p>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="gateqris-payments-user-summary" />
					<p>
						<select name="user_id" required>
							<option value=""><?php esc_html_e( 'Select a user...', 'gateqris-payments' ); ?></option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( (string) $user->ID ); ?>"><?php echo esc_html( $user->display_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'View Summary', 'gateqris-payments' ), 'primary', '', false ); ?>
					</p>
				</form>
			</div>
			<?php
			return;
		}

		$wallet = $this->wallets->get_by_owner( 'user', $user_id );
		if ( ! $wallet ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'User Payment Summary', 'gateqris-payments' ); ?></h1>
				<div class="notice notice-warning"><p><?php esc_html_e( 'This user does not have a wallet yet.', 'gateqris-payments' ); ?></p></div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gateqris-payments-user-summary' ) ); ?>"><?php esc_html_e( 'Back', 'gateqris-payments' ); ?></a>
			</div>
			<?php
			return;
		}

		$user = get_userdata( $user_id );
		$transactions = $this->transactions->search( '', '', '', '', (int) $wallet['id'], 100 );
		$settlements = $this->settlements->all( (int) $wallet['id'], 100 );
		$ledger = $this->ledger->for_wallet( (int) $wallet['id'], 50 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( __( 'Payment Summary: %s', 'gateqris-payments' ), $user->display_name ) ); ?></h1>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gateqris-payments-user-summary' ) ); ?>"><?php esc_html_e( 'Back to User List', 'gateqris-payments' ); ?></a>

			<h2><?php esc_html_e( 'Wallet Overview', 'gateqris-payments' ); ?></h2>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Wallet ID', 'gateqris-payments' ); ?></th><td><code><?php echo esc_html( (string) $wallet['account_uuid'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Status', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $wallet['status'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Available Balance', 'gateqris-payments' ); ?></th><td><strong><?php echo esc_html( number_format_i18n( (int) $wallet['available_balance'] ) ); ?></strong></td></tr>
				<tr><th><?php esc_html_e( 'Pending Balance', 'gateqris-payments' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $wallet['pending_balance'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Reserved Balance', 'gateqris-payments' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $wallet['reserved_balance'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Created', 'gateqris-payments' ); ?></th><td><?php echo esc_html( (string) $wallet['created_at_gmt'] ); ?></td></tr>
			</table>

			<h2><?php esc_html_e( 'Recent Transactions', 'gateqris-payments' ); ?> (<?php echo esc_html( (string) count( $transactions ) ); ?>)</h2>
			<?php if ( empty( $transactions ) ) : ?>
				<p><?php esc_html_e( 'No transactions yet.', 'gateqris-payments' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'UUID', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Amount', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Status', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Created', 'gateqris-payments' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $transactions, 0, 10 ) as $tx ) : ?>
						<tr>
							<td><code><?php echo esc_html( substr( (string) $tx['uuid'], 0, 8 ) ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) $tx['total_amount'] ) ); ?></td>
							<td><?php echo esc_html( (string) $tx['internal_status'] ); ?></td>
							<td><?php echo esc_html( (string) $tx['created_at_gmt'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent Settlements', 'gateqris-payments' ); ?> (<?php echo esc_html( (string) count( $settlements ) ); ?>)</h2>
			<?php if ( empty( $settlements ) ) : ?>
				<p><?php esc_html_e( 'No settlements yet.', 'gateqris-payments' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'UUID', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Net Amount', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Status', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Settled', 'gateqris-payments' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $settlements, 0, 10 ) as $settlement ) : ?>
						<tr>
							<td><code><?php echo esc_html( substr( (string) $settlement['settlement_uuid'], 0, 8 ) ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) $settlement['net_amount'] ) ); ?></td>
							<td><?php echo esc_html( (string) $settlement['status'] ); ?></td>
							<td><?php echo esc_html( (string) ( $settlement['settled_at_gmt'] ?: '-' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Ledger History', 'gateqris-payments' ); ?></h2>
			<?php if ( empty( $ledger ) ) : ?>
				<p><?php esc_html_e( 'No ledger entries yet.', 'gateqris-payments' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Type', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Direction', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Amount', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Balance', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Note', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Date', 'gateqris-payments' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $ledger, 0, 20 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $entry['entry_type'] ); ?></td>
							<td><?php echo esc_html( (string) $entry['direction'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $entry['amount'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $entry['balance_after'] ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['note'] ?: '-' ) ); ?></td>
							<td><?php echo esc_html( (string) $entry['created_at_gmt'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
