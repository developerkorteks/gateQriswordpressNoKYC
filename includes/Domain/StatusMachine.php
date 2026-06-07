<?php

namespace GRN\GateQris\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * StatusMachine — maps gateway statuses to internal statuses and enforces
 * allowed state transitions.
 *
 * Uses an explicit allowed-transitions map instead of a simple priority order
 * so that logically impossible paths (e.g. expired → paid) are always blocked.
 */
final class StatusMachine {

	/**
	 * Allowed transitions: current_status → list of permitted next_statuses.
	 * Terminal states have an empty list.
	 */
	private const ALLOWED = array(
		'draft'           => array( 'pending_payment', 'error' ),
		'pending_payment' => array( 'paid_unsettled', 'expired', 'error' ),
		'expired'         => array(), // terminal
		'paid_unsettled'  => array( 'settled', 'error' ),
		'settled'         => array( 'reconciled' ),
		'reconciled'      => array(), // terminal
		'error'           => array(), // terminal
	);

	public function map_gateway_to_internal( string $gateway_status ): string {
		return match ( strtoupper( $gateway_status ) ) {
			'PAID', 'MANUAL_ACC' => 'paid_unsettled',
			'EXPIRED'            => 'expired',
			default              => 'pending_payment',
		};
	}

	/**
	 * Returns true if transitioning from $current_status to $next_status is allowed.
	 *
	 * Same-status (idempotent) updates are always allowed.
	 */
	public function should_transition( string $current_status, string $next_status ): bool {
		if ( $current_status === $next_status ) {
			return true;
		}

		$allowed = self::ALLOWED[ $current_status ] ?? array();

		return in_array( $next_status, $allowed, true );
	}
}
