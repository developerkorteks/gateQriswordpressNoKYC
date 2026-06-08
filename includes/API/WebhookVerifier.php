<?php

namespace GRN\GateQris\API;

defined( 'ABSPATH' ) || exit;

final class WebhookVerifier {
	public function __construct(
		private readonly Signer $signer
	) {}

	public function verify( string $secret_key, string $raw_body, string $signature, ?string $timestamp, int $tolerance ): bool {
		if ( '' === $signature ) {
			return false;
		}

		if ( null !== $timestamp && '' !== $timestamp ) {
			if ( ! ctype_digit( $timestamp ) ) {
				return false;
			}

			if ( abs( time() - (int) $timestamp ) > $tolerance ) {
				return false;
			}
		}

		$expected = $this->signer->sign( $secret_key, (string) $timestamp, $raw_body );

		return hash_equals( $expected, $signature );
	}
}
