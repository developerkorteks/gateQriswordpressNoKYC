<?php

namespace GRN\GateQris\Config;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION_KEY = 'gateqris_payments_settings';

	public function register(): void {
		register_setting(
			'gateqris_payments',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'gateqris_payments_api',
			__( 'API Settings', 'gateqris-payments' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure GateQRIS credentials and security controls.', 'gateqris-payments' ) . '</p>';
			},
			'gateqris_payments'
		);

		foreach ( $this->fields() as $field_key => $field ) {
			add_settings_field(
				$field_key,
				$field['label'],
				array( $this, 'render_field' ),
				'gateqris_payments',
				'gateqris_payments_api',
				array(
					'key'   => $field_key,
					'field' => $field,
				)
			);
		}
	}

	public function defaults(): array {
		return array(
			'enabled'                  => 'yes',
			'public_key'               => '',
			'secret_key'               => '',
			'api_base_url'             => '',
			'public_base_url'          => '',
			'webhook_token'            => '', // generated on first save via ensure_webhook_token()
			'debug_logging'            => 'no',
			'retain_data_on_uninstall' => 'yes',
			'poll_interval_minutes'    => 5,
			'timestamp_tolerance'      => 300,
			'currency'                 => 'IDR',
			'allow_user_wallets'       => 'yes',
			'auto_create_user_wallets' => 'yes',
			'public_form_wallet_owner' => 'site',
		);
	}

	public function get_all(): array {
		$saved = (array) get_option( self::OPTION_KEY, array() );
		$all   = wp_parse_args( $saved, $this->defaults() );

		// Ensure the webhook token is persistent: generate once and save so the
		// webhook URL never changes between requests.
		if ( '' === (string) $all['webhook_token'] ) {
			$all['webhook_token'] = wp_generate_password( 32, false, false );
			update_option( self::OPTION_KEY, $all );
		}

		return $all;
	}

	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->get_all();

		return $settings[ $key ] ?? $default;
	}

	public function has_credentials(): bool {
		return '' !== (string) $this->get( 'public_key', '' ) && '' !== (string) $this->get( 'secret_key', '' );
	}

	public function webhook_path(): string {
		return '/wp-json/gateqris/v1/webhook/' . rawurlencode( (string) $this->get( 'webhook_token' ) );
	}

	public function public_base_url(): string {
		$override = trim( (string) $this->get( 'public_base_url', '' ) );
		if ( '' !== $override && wp_http_validate_url( $override ) ) {
			return untrailingslashit( $override );
		}

		return untrailingslashit( home_url( '/' ) );
	}

	public function public_url( string $path = '/' ): string {
		$path = '/' . ltrim( $path, '/' );
		return $this->public_base_url() . $path;
	}

	public function public_asset_url( string $relative_path ): string {
		$plugin_path = (string) wp_parse_url( GATEQRIS_PAYMENTS_PLUGIN_URL, PHP_URL_PATH );
		$plugin_path = '' !== $plugin_path ? trailingslashit( $plugin_path ) : '/wp-content/plugins/gateqris-payments/';

		return $this->public_url( $plugin_path . ltrim( $relative_path, '/' ) );
	}

	public function webhook_url(): string {
		return $this->public_url( $this->webhook_path() );
	}

	public function sanitize( array $input ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( self::OPTION_KEY, 'gateqris_payments_capability', __( 'You are not allowed to manage GateQRIS settings.', 'gateqris-payments' ) );
			return $this->get_all();
		}

		$current = $this->get_all();
		$output  = $current;

		$output['enabled']               = isset( $input['enabled'] ) && 'yes' === $input['enabled'] ? 'yes' : 'no';
		$output['debug_logging']         = isset( $input['debug_logging'] ) && 'yes' === $input['debug_logging'] ? 'yes' : 'no';
		$output['retain_data_on_uninstall'] = isset( $input['retain_data_on_uninstall'] ) && 'yes' === $input['retain_data_on_uninstall'] ? 'yes' : 'no';
		$output['allow_user_wallets']    = isset( $input['allow_user_wallets'] ) && 'yes' === $input['allow_user_wallets'] ? 'yes' : 'no';
		$output['auto_create_user_wallets'] = isset( $input['auto_create_user_wallets'] ) && 'yes' === $input['auto_create_user_wallets'] ? 'yes' : 'no';
		$output['public_form_wallet_owner'] = in_array( $input['public_form_wallet_owner'] ?? 'site', array( 'site', 'user' ), true ) ? $input['public_form_wallet_owner'] : 'site';

		$public_key = sanitize_text_field( $input['public_key'] ?? '' );
		if ( '' !== $public_key ) {
			$output['public_key'] = $public_key;
		}

		$secret_key = trim( (string) ( $input['secret_key'] ?? '' ) );
		if ( '' !== $secret_key && ! str_contains( $secret_key, '*' ) ) {
			$output['secret_key'] = $secret_key;
		}

		$api_base_url = esc_url_raw( trim( (string) ( $input['api_base_url'] ?? '' ) ) );
		if ( '' === $api_base_url || ! wp_http_validate_url( $api_base_url ) ) {
			add_settings_error( self::OPTION_KEY, 'gateqris_payments_api_base_url', __( 'API Base URL must be a valid URL.', 'gateqris-payments' ) );
		} else {
			$output['api_base_url'] = untrailingslashit( $api_base_url );
		}

		$public_base_url = esc_url_raw( trim( (string) ( $input['public_base_url'] ?? '' ) ) );
		if ( '' !== $public_base_url && ! wp_http_validate_url( $public_base_url ) ) {
			add_settings_error( self::OPTION_KEY, 'gateqris_payments_public_base_url', __( 'Public Base URL must be a valid URL when provided.', 'gateqris-payments' ) );
		} else {
			$output['public_base_url'] = '' !== $public_base_url ? untrailingslashit( $public_base_url ) : '';
		}

		$poll = absint( $input['poll_interval_minutes'] ?? 5 );
		$output['poll_interval_minutes'] = max( 1, min( 60, $poll ) );

		$tolerance = absint( $input['timestamp_tolerance'] ?? 300 );
		$output['timestamp_tolerance'] = max( 60, min( 3600, $tolerance ) );

		$currency = strtoupper( sanitize_text_field( $input['currency'] ?? 'IDR' ) );
		$output['currency'] = 'IDR' === $currency ? 'IDR' : 'IDR';

		$token = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( $input['webhook_token'] ?? '' ) );
		$output['webhook_token'] = '' !== $token ? $token : $current['webhook_token'];

		return $output;
	}

	public function render_field( array $args ): void {
		$key   = $args['key'];
		$field = $args['field'];
		$value = $this->get( $key );

		if ( 'secret_key' === $key && '' !== $value ) {
			$value = str_repeat( '*', max( 8, strlen( (string) $value ) - 4 ) ) . substr( (string) $this->get( 'secret_key' ), -4 );
		}

		if ( 'checkbox' === $field['type'] ) {
			printf(
				'<label><input type="checkbox" name="%1$s[%2$s]" value="yes" %3$s /> %4$s</label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				checked( 'yes', $this->get( $key ), false ),
				esc_html( $field['description'] )
			);
			return;
		}

		if ( 'select' === $field['type'] ) {
			printf(
				'<select name="%1$s[%2$s]">',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key )
			);
			foreach ( $field['options'] as $option_value => $option_label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $option_value ),
					selected( $option_value, $this->get( $key ), false ),
					esc_html( $option_label )
				);
			}
			echo '</select>';
			if ( ! empty( $field['description'] ) ) {
				echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
			}
			return;
		}

		printf(
			'<input class="regular-text" type="%1$s" name="%2$s[%3$s]" value="%4$s" autocomplete="off" />',
			esc_attr( $field['type'] ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}
	}

	private function fields(): array {
		return array(
			'enabled'                  => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Plugin', 'gateqris-payments' ),
				'description' => __( 'Allow GateQRIS Payments to register routes and admin tools.', 'gateqris-payments' ),
			),
			'public_key'               => array(
				'type'        => 'text',
				'label'       => __( 'Public Key', 'gateqris-payments' ),
				'description' => __( 'GateQRIS public key. Do not hardcode production keys in plugin files.', 'gateqris-payments' ),
			),
			'secret_key'               => array(
				'type'        => 'password',
				'label'       => __( 'Secret Key', 'gateqris-payments' ),
				'description' => __( 'Secret key is masked after save and never logged.', 'gateqris-payments' ),
			),
			'api_base_url'             => array(
				'type'        => 'url',
				'label'       => __( 'API Base URL', 'gateqris-payments' ),
				'description' => __( 'Base URL for GateQRIS API requests.', 'gateqris-payments' ),
			),
			'public_base_url'          => array(
				'type'        => 'url',
				'label'       => __( 'Public Base URL', 'gateqris-payments' ),
				'description' => __( 'Optional public URL override for webhook and hosted payment links, useful when your WordPress site runs behind a tunnel or reverse proxy.', 'gateqris-payments' ),
			),
			'webhook_token'            => array(
				'type'        => 'text',
				'label'       => __( 'Webhook Token', 'gateqris-payments' ),
				'description' => __( 'Random token embedded in the webhook URL path.', 'gateqris-payments' ),
			),
			'poll_interval_minutes'    => array(
				'type'        => 'number',
				'label'       => __( 'Polling Interval (minutes)', 'gateqris-payments' ),
				'description' => __( 'Fallback polling interval for pending invoices.', 'gateqris-payments' ),
			),
			'timestamp_tolerance'      => array(
				'type'        => 'number',
				'label'       => __( 'Timestamp Tolerance (seconds)', 'gateqris-payments' ),
				'description' => __( 'Allowed drift when verifying webhook timestamps.', 'gateqris-payments' ),
			),
			'currency'                 => array(
				'type'        => 'text',
				'label'       => __( 'Currency', 'gateqris-payments' ),
				'description' => __( 'v1 supports IDR only.', 'gateqris-payments' ),
			),
			'allow_user_wallets'       => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable User Wallets', 'gateqris-payments' ),
				'description' => __( 'Allow settlement targets to resolve to per-user wallets.', 'gateqris-payments' ),
			),
			'auto_create_user_wallets' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Auto Create Wallet On User Registration', 'gateqris-payments' ),
				'description' => __( 'Automatically provision a wallet when a new WordPress user account is created.', 'gateqris-payments' ),
			),
			'public_form_wallet_owner' => array(
				'type'        => 'select',
				'label'       => __( 'Public Form Default Wallet', 'gateqris-payments' ),
				'description' => __( 'Default wallet owner used for public hosted payment forms.', 'gateqris-payments' ),
				'options'     => array(
					'site' => __( 'Site Wallet', 'gateqris-payments' ),
					'user' => __( 'User Wallet', 'gateqris-payments' ),
				),
			),
			'debug_logging'            => array(
				'type'        => 'checkbox',
				'label'       => __( 'Debug Logging', 'gateqris-payments' ),
				'description' => __( 'Write diagnostic logs without exposing secrets.', 'gateqris-payments' ),
			),
			'retain_data_on_uninstall' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Retain Data On Uninstall', 'gateqris-payments' ),
				'description' => __( 'Keep transactions, wallets, and plugin settings when the plugin is uninstalled.', 'gateqris-payments' ),
			),
		);
	}
}
