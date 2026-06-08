<?php

namespace GRN\GateQris\Domain;

use GRN\GateQris\Config\Settings;
use GRN\GateQris\Repository\WalletRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WalletService {
	public function __construct(
		private readonly WalletRepository $wallets,
		private readonly Settings $settings
	) {}

	/**
	 * Read a wallet row with a row-level write lock, delegating to the repository.
	 *
	 * Must be called inside a DB transaction. SettlementService uses this to lock
	 * the wallet for the duration of a settlement.
	 */
	public function get_for_update( int $id ): ?array {
		return $this->wallets->get_for_update( $id );
	}

	/**
	 * Look up a wallet without creating one. Safe for read paths such as a
	 * gateway's is_available() check, where lazy-provisioning is undesirable.
	 */
	public function find_wallet( string $owner_type, int $owner_id ): ?array {
		return $this->wallets->get_by_owner( $owner_type, $owner_id );
	}

	public function resolve_wallet( string $owner_type, int $owner_id ): ?array {
		if ( 'user' === $owner_type && 'yes' !== $this->settings->get( 'allow_user_wallets', 'yes' ) ) {
			$owner_type = 'site';
			$owner_id   = 0;
		}

		$wallet = $this->wallets->get_by_owner( $owner_type, $owner_id );
		if ( $wallet ) {
			return $wallet;
		}

		if ( 'user' !== $owner_type ) {
			return null;
		}

		// Respect the auto_create_user_wallets setting: if disabled, don't
		// silently create a wallet — return null so the caller can handle it.
		if ( 'yes' !== $this->settings->get( 'auto_create_user_wallets', 'yes' ) ) {
			return null;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$id  = $this->wallets->create(
			array(
				'account_uuid'      => wp_generate_uuid4(),
				'owner_type'        => 'user',
				'owner_id'          => $owner_id,
				'account_code'      => 'user-' . $owner_id,
				'currency'          => 'IDR',
				'status'            => 'active',
				'available_balance' => 0,
				'pending_balance'   => 0,
				'reserved_balance'  => 0,
				'created_at_gmt'    => $now,
				'updated_at_gmt'    => $now,
			)
		);

		return $this->wallets->get_by_id( $id );
	}

	public function provision_user_wallet( int $user_id ): array|WP_Error {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'gateqris_invalid_user_wallet_owner', __( 'A valid WordPress user is required to provision a wallet.', 'gateqris-payments' ) );
		}

		if ( 'yes' !== $this->settings->get( 'allow_user_wallets', 'yes' ) ) {
			return new WP_Error( 'gateqris_user_wallets_disabled', __( 'User wallets are disabled in plugin settings.', 'gateqris-payments' ) );
		}

		$wallet = $this->resolve_wallet( 'user', $user_id );

		return $wallet ?: new WP_Error( 'gateqris_wallet_provision_failed', __( 'User wallet could not be provisioned.', 'gateqris-payments' ) );
	}
}
