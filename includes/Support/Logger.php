<?php

namespace GRN\GateQris\Support;

defined( 'ABSPATH' ) || exit;

final class Logger {
	private string $source = 'gateqris-payments';

	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->write( 'warning', $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	public function debug( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$this->write( 'debug', $message, $context );
	}

	private function write( string $level, string $message, array $context ): void {
		if ( class_exists( 'WC_Logger' ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $this->sanitize_message( $message, $context ), array( 'source' => $this->source ) );
			return;
		}

		error_log( sprintf( '[%s] %s', strtoupper( $level ), $this->sanitize_message( $message, $context ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private function sanitize_message( string $message, array $context ): string {
		if ( empty( $context ) ) {
			return $message;
		}

		$redacted = array();
		foreach ( $context as $key => $value ) {
			if ( str_contains( strtolower( (string) $key ), 'secret' ) || str_contains( strtolower( (string) $key ), 'signature' ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			$redacted[ $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}

		return $message . ' ' . wp_json_encode( $redacted );
	}
}
