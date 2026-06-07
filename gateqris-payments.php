<?php
/**
 * Plugin Name: GateQRIS Payments
 * Plugin URI:  https://gateqris.grnstore.my.id/
 * Description: Standalone GateQRIS payment plugin with hosted forms, webhooks, settlement, and wallet ledger.
 * Version:     0.2.0
 * Author:      grnstore
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 * WC tested up to: 9.9
 * Text Domain: gateqris-payments
 */

defined( 'ABSPATH' ) || exit;

define( 'GATEQRIS_PAYMENTS_VERSION', '0.2.0' );
define( 'GATEQRIS_PAYMENTS_PLUGIN_FILE', __FILE__ );
define( 'GATEQRIS_PAYMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GATEQRIS_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'GRN\\GateQris\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );
		$file     = GATEQRIS_PAYMENTS_PLUGIN_DIR . 'includes/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		GRN\GateQris\Bootstrap::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		GRN\GateQris\Bootstrap::deactivate();
	}
);

require_once GATEQRIS_PAYMENTS_PLUGIN_DIR . 'includes/Bootstrap.php';

GRN\GateQris\Bootstrap::instance()->boot();
