<?php

namespace GRN\GateQris\API;

defined( 'ABSPATH' ) || exit;

final class Signer {
	public function sign( string $secret_key, string $timestamp, string $raw_body ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret_key );
	}
}
