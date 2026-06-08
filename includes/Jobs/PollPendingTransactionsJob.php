<?php

namespace GRN\GateQris\Jobs;

use GRN\GateQris\Domain\TransactionService;
use GRN\GateQris\Repository\TransactionRepository;
use GRN\GateQris\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class PollPendingTransactionsJob {
	public function __construct(
		private readonly TransactionRepository $transactions,
		private readonly TransactionService $service,
		private readonly Logger $logger
	) {}

	public function register(): void {
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['minute'] = array(
					'interval' => MINUTE_IN_SECONDS,
					'display'  => __( 'Every Minute', 'gateqris-payments' ),
				);
				return $schedules;
			}
		);

		add_action( 'gateqris_payments_poll_pending', array( $this, 'handle' ) );

		if ( ! wp_next_scheduled( 'gateqris_payments_poll_pending' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'minute', 'gateqris_payments_poll_pending' );
		}
	}

	public function handle(): void {
		$pending = $this->transactions->find_pending_for_polling( 20 );
		foreach ( $pending as $transaction ) {
			$result = $this->service->refresh_transaction( $transaction );
			if ( is_wp_error( $result ) ) {
				$this->logger->warning( 'Polling refresh failed', array( 'transaction_id' => $transaction['id'], 'message' => $result->get_error_message() ) );
			}
		}
	}
}
