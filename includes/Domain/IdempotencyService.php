<?php

namespace GRN\GateQris\Domain;

use GRN\GateQris\Repository\IdempotencyRepository;
use GRN\GateQris\Support\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class IdempotencyService {
	public function __construct(
		private readonly IdempotencyRepository $repository,
		private readonly Logger $logger
	) {}

	public function reserve( string $scope, string $key, string $request_hash ): array|WP_Error {
		$current = $this->repository->get( $scope, $key );
		if ( $current ) {
			if ( $current['request_hash'] !== $request_hash ) {
				return new WP_Error( 'gateqris_idempotency_conflict', __( 'Idempotency key reused with different payload.', 'gateqris-payments' ) );
			}

			return $current;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$this->repository->create(
			array(
				'scope'             => $scope,
				'idempotency_key'   => $key,
				'request_hash'      => $request_hash,
				'response_snapshot' => '',
				'status'            => 'pending',
				'created_at_gmt'    => $now,
				'updated_at_gmt'    => $now,
			)
		);

		$this->logger->debug( 'Reserved idempotency key', array( 'scope' => $scope ) );

		return $this->repository->get( $scope, $key ) ?? new WP_Error( 'gateqris_idempotency_missing', __( 'Idempotency record missing after reserve.', 'gateqris-payments' ) );
	}

	public function complete( int $id, array $response ): void {
		$this->repository->update(
			$id,
			array(
				'status'            => 'completed',
				'response_snapshot' => wp_json_encode( $response ),
				'updated_at_gmt'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
