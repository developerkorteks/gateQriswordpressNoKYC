<?php

namespace GRN\GateQris\Admin;

use GRN\GateQris\Config\Settings;
defined( 'ABSPATH' ) || exit;

final class Menu {
	public function __construct(
		private readonly Settings $settings
	) {}

	public function register(): void {
		add_menu_page(
			__( 'GateQRIS Payments', 'gateqris-payments' ),
			__( 'GateQRIS Payments', 'gateqris-payments' ),
			'manage_options',
			'gateqris-payments',
			array( $this, 'render_settings_page' ),
			'dashicons-money-alt'
		);

		add_submenu_page(
			'gateqris-payments',
			__( 'Settings', 'gateqris-payments' ),
			__( 'Settings', 'gateqris-payments' ),
			'manage_options',
			'gateqris-payments',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'gateqris-payments',
			__( 'Health Check', 'gateqris-payments' ),
			__( 'Health Check', 'gateqris-payments' ),
			'manage_options',
			'gateqris-payments-health',
			array( $this, 'render_health_page' )
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gateqris-payments' ) );
		}

		$webhook_token   = (string) $this->settings->get( 'webhook_token', '' );
		$home_url        = home_url( '/' );
		$public_base_url = $this->settings->public_base_url() . '/';
		$api_base_url    = (string) $this->settings->get( 'api_base_url', '' );
		$public_key      = (string) $this->settings->get( 'public_key', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GateQRIS Payments', 'gateqris-payments' ); ?></h1>
			<p><?php esc_html_e( 'Standalone QRIS payments with hosted forms, webhooks, settlement, and wallet ledger.', 'gateqris-payments' ); ?></p>
			<?php if ( $this->is_local_home_url( $home_url ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site is using a localhost/private URL. GateQRIS webhooks cannot reach it directly from the internet. Use a public domain or tunnel before relying on live webhook updates.', 'gateqris-payments' ); ?></p></div>
			<?php endif; ?>
			<?php if ( untrailingslashit( $public_base_url ) !== untrailingslashit( $home_url ) ) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html( sprintf( __( 'Public Base URL override is active. Public webhook and hosted payment links use %s.', 'gateqris-payments' ), untrailingslashit( $public_base_url ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $this->looks_like_mock_configuration( $api_base_url, $public_key ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'GateQRIS is currently configured with mock/test host settings. Real invoice creation will fail until you save the real API Base URL and credentials.', 'gateqris-payments' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $this->is_weak_webhook_token( $webhook_token ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your webhook token looks weak or still uses a test value. Replace it with a long random token before production use.', 'gateqris-payments' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php
				settings_errors( Settings::OPTION_KEY );
				settings_fields( 'gateqris_payments' );
				do_settings_sections( 'gateqris_payments' );
				submit_button();
				?>
			</form>
			<hr />
			<p><strong><?php esc_html_e( 'Current Site URL:', 'gateqris-payments' ); ?></strong> <code><?php echo esc_html( $home_url ); ?></code></p>
			<p><strong><?php esc_html_e( 'Public Base URL:', 'gateqris-payments' ); ?></strong> <code><?php echo esc_html( $public_base_url ); ?></code></p>
			<p><strong><?php esc_html_e( 'Webhook URL:', 'gateqris-payments' ); ?></strong> <code><?php echo esc_html( $this->settings->webhook_url() ); ?></code></p>
			<h2><?php esc_html_e( 'Setup Checklist', 'gateqris-payments' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Enter your GateQRIS Public Key and Secret Key.', 'gateqris-payments' ); ?></li>
				<li><?php esc_html_e( 'Set a strong webhook token and copy the generated webhook URL into your GateQRIS dashboard.', 'gateqris-payments' ); ?></li>
				<li><?php esc_html_e( 'Make sure the site is reachable from the internet if you want live webhook delivery.', 'gateqris-payments' ); ?></li>
				<li><?php esc_html_e( 'Place [gateqris_payment_form] on a page to start taking payments.', 'gateqris-payments' ); ?></li>
				<li><?php esc_html_e( 'Use Transactions, Settlements, and Webhook Logs to monitor live activity.', 'gateqris-payments' ); ?></li>
			</ol>
		</div>
		<?php
	}

	public function render_health_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gateqris-payments' ) );
		}

		global $wpdb;
		$tables = array(
			'transactions'      => $wpdb->prefix . 'gq_transactions',
			'webhook_events'    => $wpdb->prefix . 'gq_webhook_events',
			'wallet_accounts'   => $wpdb->prefix . 'gq_wallet_accounts',
			'ledger_entries'    => $wpdb->prefix . 'gq_ledger_entries',
			'settlements'       => $wpdb->prefix . 'gq_settlements',
			'idempotency_keys'  => $wpdb->prefix . 'gq_idempotency_keys',
		);
		$home_url         = home_url( '/' );
		$public_base_url  = $this->settings->public_base_url() . '/';
		$webhook_token    = (string) $this->settings->get( 'webhook_token', '' );
		$next_poll        = wp_next_scheduled( 'gateqris_payments_poll_pending' );
		$qr_renderer_mode = file_exists( GATEQRIS_PAYMENTS_PLUGIN_DIR . 'assets/js/vendor/qrcode-generator.js' )
			? __( 'Bundled local JavaScript renderer', 'gateqris-payments' )
			: __( 'Raw payload fallback only', 'gateqris-payments' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GateQRIS Health Check', 'gateqris-payments' ); ?></h1>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Plugin Version', 'gateqris-payments' ); ?></th>
						<td><?php echo esc_html( GATEQRIS_PAYMENTS_VERSION ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Credentials Configured', 'gateqris-payments' ); ?></th>
						<td><?php echo esc_html( $this->settings->has_credentials() ? __( 'Yes', 'gateqris-payments' ) : __( 'No', 'gateqris-payments' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Webhook URL', 'gateqris-payments' ); ?></th>
						<td><code><?php echo esc_html( $this->settings->webhook_url() ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Public Base URL', 'gateqris-payments' ); ?></th>
						<td><?php echo esc_html( untrailingslashit( $public_base_url ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Site URL Mismatch', 'gateqris-payments' ); ?></th>
						<td><?php echo esc_html( untrailingslashit( $public_base_url ) !== untrailingslashit( $home_url ) ? __( 'Yes, public override is active', 'gateqris-payments' ) : __( 'No', 'gateqris-payments' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Webhook Token Strength', 'gateqris-payments' ); ?></th>
						<td><?php echo esc_html( $this->is_weak_webhook_token( $webhook_token ) ? __( 'Weak', 'gateqris-payments' ) : __( 'Strong', 'gateqris-payments' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Next Poll Schedule', 'gateqris-payments' ); ?></th>
						<td><?php echo esc_html( $next_poll ? gmdate( 'Y-m-d H:i:s', (int) $next_poll ) . ' GMT' : __( 'Not scheduled', 'gateqris-payments' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'QR Preview Renderer', 'gateqris-payments' ); ?></th>
						<td><?php echo esc_html( $qr_renderer_mode ); ?></td>
					</tr>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Database Tables', 'gateqris-payments' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Table', 'gateqris-payments' ); ?></th><th><?php esc_html_e( 'Status', 'gateqris-payments' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $tables as $label => $table ) : ?>
					<?php $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); ?>
					<tr>
						<td><code><?php echo esc_html( $table ); ?></code></td>
						<td><?php echo esc_html( $exists ? __( 'Present', 'gateqris-payments' ) : __( 'Missing', 'gateqris-payments' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function is_local_home_url( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || str_ends_with( $host, '.local' );
	}

	private function is_weak_webhook_token( string $token ): bool {
		if ( strlen( $token ) < 24 ) {
			return true;
		}

		$normalized = strtolower( $token );

		return in_array( $normalized, array( 'testtoken123', 'changeme', 'gateqris', 'webhook' ), true );
	}

	private function looks_like_mock_configuration( string $api_base_url, string $public_key ): bool {
		$api_base_url = strtolower( $api_base_url );
		$public_key   = strtolower( $public_key );

		return str_contains( $api_base_url, 'example.test' ) || str_starts_with( $public_key, 'pk_test_' );
	}
}
