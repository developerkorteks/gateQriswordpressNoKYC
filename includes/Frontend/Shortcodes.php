<?php

namespace GRN\GateQris\Frontend;

use GRN\GateQris\Config\Settings;
use GRN\GateQris\Repository\TransactionRepository;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {
	public function __construct(
		private readonly TransactionRepository $transactions,
		private readonly Settings $settings
	) {}

	public function register(): void {
		add_shortcode( 'gateqris_payment_form', array( $this, 'render_form' ) );
		add_shortcode( 'gateqris_payment_status', array( $this, 'render_status' ) );
		add_action( 'admin_post_nopriv_gateqris_hosted_payment', array( $this, 'render_hosted_status_page' ) );
		add_action( 'admin_post_gateqris_hosted_payment', array( $this, 'render_hosted_status_page' ) );
	}

	public function render_form( array $atts = array() ): string {
		$defaults = apply_filters(
			'gateqris_payments_form_defaults',
			array(
				'amount' => '',
			),
			$atts
		);

		$atts = shortcode_atts(
			array(
				'amount' => $defaults['amount'] ?? '',
				'wallet' => '',
			),
			$atts,
			'gateqris_payment_form'
		);

		// Resolve the settlement target for this form instance. An explicit
		// wallet="user|site" attribute overrides the global default. "user" means
		// the payment tops up the logged-in customer's own wallet.
		$wallet = in_array( $atts['wallet'], array( 'user', 'site' ), true ) ? $atts['wallet'] : '';
		$target = '' !== $wallet ? $wallet : (string) $this->settings->get( 'public_form_wallet_owner', 'site' );
		$target = 'user' === $target ? 'user' : 'site';

		// A user-wallet (top-up) form requires a logged-in customer to credit.
		if ( 'user' === $target && ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Silakan masuk (login) terlebih dahulu untuk mengisi saldo.', 'gateqris-payments' ) . '</p>';
		}

		// Bind the nonce to the target so the client cannot flip a site payment
		// into a self-credit (or vice versa) by editing the hidden field.
		$nonce_action = 'user' === $target ? 'gateqris_public_form_user' : 'gateqris_public_form';

		ob_start();
		?>
		<form class="gateqris-payments-form" method="post" action="<?php echo esc_url( $this->public_rest_url( 'gateqris/v1/transactions' ) ); ?>">
			<input type="hidden" name="form_token" value="<?php echo esc_attr( wp_create_nonce( $nonce_action ) ); ?>" />
			<input type="hidden" name="wallet_target" value="<?php echo esc_attr( $target ); ?>" />
			<p style="display:none">
				<label><?php esc_html_e( 'Website', 'gateqris-payments' ); ?><input type="text" name="website" value="" autocomplete="off" /></label>
			</p>
			<p>
				<label><?php esc_html_e( 'Amount (IDR)', 'gateqris-payments' ); ?><br />
					<input type="number" min="1" name="amount" value="<?php echo esc_attr( (string) $atts['amount'] ); ?>" required />
				</label>
			</p>
			<p>
				<label><?php esc_html_e( 'Name', 'gateqris-payments' ); ?><br />
					<input type="text" name="customer_name" value="" />
				</label>
			</p>
			<p>
				<label><?php esc_html_e( 'Email', 'gateqris-payments' ); ?><br />
					<input type="email" name="customer_email" value="" />
				</label>
			</p>
			<p>
				<label><?php esc_html_e( 'Reference', 'gateqris-payments' ); ?><br />
					<input type="text" name="reference" value="" />
				</label>
			</p>
			<p><button type="submit"><?php esc_html_e( 'Create QRIS Payment', 'gateqris-payments' ); ?></button></p>
		</form>
		<script>
		document.addEventListener('submit', async function (event) {
			const form = event.target.closest('.gateqris-payments-form');
			if (!form || event.target !== form) {
				return;
			}
			event.preventDefault();
			const body = new URLSearchParams(new FormData(form));
			const response = await fetch(form.action, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body
			});
			const data = await response.json();
			if (!response.ok) {
				alert(data.message || 'Request failed');
				return;
			}
			window.location.href = data.hostedUrl;
		});
		</script>
		<?php
		return (string) ob_get_clean();
	}

	public function render_status( ?string $uuid = null, ?string $token = null ): string {
		$uuid  = null !== $uuid ? sanitize_text_field( $uuid ) : sanitize_text_field( (string) ( $_GET['gateqris_transaction'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = null !== $token ? sanitize_text_field( $token ) : sanitize_text_field( (string) ( $_GET['gateqris_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $uuid ) {
			return '<p>' . esc_html__( 'No GateQRIS transaction selected.', 'gateqris-payments' ) . '</p>';
		}

		$transaction = '' !== $token ? $this->transactions->get_by_uuid_and_token( $uuid, $token ) : null;
		if ( ! $transaction ) {
			return '<p>' . esc_html__( 'Transaction not found or access token is invalid.', 'gateqris-payments' ) . '</p>';
		}

		$qris_raw         = (string) $transaction['qris_string'];
		$status           = (string) $transaction['gateway_status'];
		$status_class     = $this->status_class( $status );
		$status_label     = $this->status_label( $status );
		$human_amount     = number_format_i18n( (int) $transaction['total_amount'] );
		$expires_at       = (string) $transaction['expires_at_gmt'];
		$countdown_target = $expires_at ? gmdate( DATE_ATOM, strtotime( $expires_at . ' UTC' ) ) : '';
		$qr_script_url    = $this->settings->public_asset_url( 'assets/js/vendor/qrcode-generator.js' );
		$logo_url         = $this->settings->public_asset_url( 'assets/img/gateqris-logo-dark.svg' );
		$store_name       = (string) get_bloginfo( 'name' );

		$meta             = json_decode( (string) ( $transaction['meta_json'] ?? '' ), true );
		$success_redirect = is_array( $meta ) ? (string) ( $meta['success_redirect_url'] ?? '' ) : '';

		ob_start();
		?>
		<style>
		.gateqris-pay{--gq-bg:#faf9f5;--gq-surface:#fff;--gq-border:#e8e6dc;--gq-border-strong:#d8d5ca;--gq-text:#141413;--gq-muted:#77736a;--gq-accent:#d97757;--gq-teal:#00bfa5;--gq-font-head:"Poppins","Helvetica Neue",Arial,sans-serif;--gq-font-body:"Lora",Georgia,"Times New Roman",serif;max-width:480px;margin:0 auto;display:grid;gap:0;color:var(--gq-text);font-family:var(--gq-font-body)}
		.gateqris-pay *{box-sizing:border-box}
		.gateqris-brand{display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center;padding:0 0 28px}
		.gateqris-logo{height:34px;width:auto;display:block}
		.gateqris-merchant{margin:0;font-size:15px;color:var(--gq-muted)}
		.gateqris-merchant strong{color:var(--gq-text);font-weight:600}
		.gateqris-block{padding:28px 0;border-top:1px solid var(--gq-border-strong)}
		.gateqris-amount-block{text-align:center;padding-top:4px;border-top:0}
		.gateqris-amount-label{margin:0 0 6px;font-family:var(--gq-font-head);font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--gq-muted)}
		.gateqris-amount{margin:0 0 16px;font-family:var(--gq-font-head);font-size:clamp(40px,11vw,56px);font-weight:600;letter-spacing:-.04em;line-height:.95;color:var(--gq-text)}
		.gateqris-statusline{display:flex;justify-content:center;margin:0 0 12px}
		.gateqris-badge{display:inline-flex;align-items:center;gap:8px;font-family:var(--gq-font-head);font-size:13px;font-weight:500;letter-spacing:.01em}
		.gateqris-badge:before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor}
		.gateqris-badge.is-pending{color:var(--gq-accent)}
		.gateqris-badge.is-paid{color:var(--gq-teal)}
		.gateqris-badge.is-expired{color:#b4534a}
		.gateqris-timer{margin:0;font-family:var(--gq-font-head);font-size:14px;color:var(--gq-muted)}
		.gateqris-countdown{font-weight:600;color:var(--gq-text);font-variant-numeric:tabular-nums}
		.gateqris-qr-block{text-align:center}
		.gateqris-qr-frame{display:grid;place-items:center;background:#fff;border:1px solid var(--gq-border);border-radius:14px;padding:18px;margin:0 auto 16px;width:min(100%,272px)}
		.gateqris-qr-render{display:grid;place-items:center;width:236px;min-height:236px}
		.gateqris-qr-render svg{display:block;width:100%;height:auto}
		.gateqris-scan{margin:0 0 14px;font-size:15px;color:var(--gq-muted);line-height:1.55}
		.gateqris-feedback{margin:0 0 16px;font-family:var(--gq-font-head);font-size:13px;color:var(--gq-accent)}
		.gateqris-refresh{appearance:none;border:0;background:var(--gq-text);color:var(--gq-bg);border-radius:999px;padding:12px 22px;font-family:var(--gq-font-head);font-size:14px;font-weight:500;cursor:pointer;transition:background .2s ease}
		.gateqris-refresh:hover{background:var(--gq-accent)}
		.gateqris-howto h2{margin:0 0 14px;font-family:var(--gq-font-head);font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--gq-muted)}
		.gateqris-howto ol{margin:0;padding-left:20px;color:var(--gq-text);font-size:15px;line-height:1.65}
		.gateqris-howto li{margin:0 0 8px}
		.gateqris-ref{margin:0;padding:20px 0 0;border-top:1px solid var(--gq-border);text-align:center;font-family:var(--gq-font-head);font-size:12px;color:var(--gq-muted);word-break:break-all}
		.gateqris-success{text-align:center;padding:48px 20px}
		.gateqris-check{width:64px;height:64px;margin:0 auto 20px;border-radius:50%;border:2px solid var(--gq-accent);color:var(--gq-accent);display:grid;place-items:center;font-size:32px}
		.gateqris-success h2{margin:0 0 8px;font-family:var(--gq-font-head);font-size:26px;font-weight:600;letter-spacing:-.02em;color:var(--gq-text)}
		.gateqris-success p{margin:0;color:var(--gq-muted);font-size:16px}
		.gateqris-redirect-note{margin-top:12px !important;color:var(--gq-accent) !important}
		</style>
		<div class="gateqris-pay" data-gateqris-redirect="<?php echo esc_url( $success_redirect ); ?>">
			<div class="gateqris-brand">
				<img class="gateqris-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="GateQRIS" height="34" />
				<?php if ( '' !== $store_name ) : ?>
					<p class="gateqris-merchant">
						<?php
						printf(
							/* translators: %s: store name */
							esc_html__( 'Pembayaran ke %s', 'gateqris-payments' ),
							'<strong>' . esc_html( $store_name ) . '</strong>'
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="gateqris-block gateqris-amount-block">
				<p class="gateqris-amount-label"><?php esc_html_e( 'Total Bayar', 'gateqris-payments' ); ?></p>
				<p class="gateqris-amount">Rp <?php echo esc_html( $human_amount ); ?></p>
				<div class="gateqris-statusline">
					<span class="gateqris-badge <?php echo esc_attr( $status_class ); ?>" data-gateqris-status data-gateqris-status-code="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></span>
				</div>
				<p class="gateqris-timer" data-gateqris-timer><?php esc_html_e( 'Sisa waktu', 'gateqris-payments' ); ?> <span class="gateqris-countdown" data-gateqris-countdown="<?php echo esc_attr( $countdown_target ); ?>">--:--</span></p>
			</div>

			<div class="gateqris-block gateqris-qr-block" data-gateqris-qr-card>
				<?php if ( '' !== $qris_raw ) : ?>
					<div class="gateqris-qr-frame">
						<div
							class="gateqris-qr-render"
							data-gateqris-qr-mount
							data-gateqris-qr-payload="<?php echo esc_attr( $qris_raw ); ?>"
							aria-label="<?php esc_attr_e( 'Kode QRIS untuk pembayaran ini', 'gateqris-payments' ); ?>"
						></div>
					</div>
					<p class="gateqris-scan"><?php esc_html_e( 'Scan dengan GoPay, OVO, DANA, ShopeePay, atau m-banking apa pun yang mendukung QRIS.', 'gateqris-payments' ); ?></p>
					<p class="gateqris-feedback" data-gateqris-feedback><?php esc_html_e( 'Menunggu konfirmasi pembayaran…', 'gateqris-payments' ); ?></p>
					<button type="button" class="gateqris-refresh" data-gateqris-refresh><?php esc_html_e( 'Muat ulang status', 'gateqris-payments' ); ?></button>
				<?php else : ?>
					<p class="gateqris-scan"><?php esc_html_e( 'Kode QRIS belum tersedia. Silakan muat ulang halaman.', 'gateqris-payments' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="gateqris-block gateqris-howto" data-gateqris-howto>
				<h2><?php esc_html_e( 'Cara bayar', 'gateqris-payments' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Buka aplikasi m-banking atau e-wallet yang mendukung QRIS.', 'gateqris-payments' ); ?></li>
					<li><?php esc_html_e( 'Pilih menu bayar/scan QRIS, lalu arahkan ke kode di atas.', 'gateqris-payments' ); ?></li>
					<li><?php esc_html_e( 'Selesaikan sebelum waktu habis. Halaman ini diperbarui otomatis saat pembayaran terkonfirmasi.', 'gateqris-payments' ); ?></li>
				</ol>
			</div>

			<p class="gateqris-ref"><?php esc_html_e( 'Ref', 'gateqris-payments' ); ?>: <span><?php echo esc_html( (string) $transaction['customer_ref'] ); ?></span></p>

			<div class="gateqris-success" data-gateqris-success hidden>
				<div class="gateqris-check" aria-hidden="true">&#10003;</div>
				<h2><?php esc_html_e( 'Pembayaran Berhasil', 'gateqris-payments' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: formatted amount */
						esc_html__( 'Rp %s diterima.', 'gateqris-payments' ),
						esc_html( $human_amount )
					);
					?>
				</p>
				<p class="gateqris-redirect-note" data-gateqris-redirect-note hidden><?php esc_html_e( 'Mengarahkan kembali ke pesanan…', 'gateqris-payments' ); ?></p>
			</div>
		</div>
		<script src="<?php echo esc_url( $qr_script_url ); ?>"></script>
		<script>
		(function () {
			var root = document.querySelector('.gateqris-pay');
			if (!root) { return; }

			(function renderLocalQr() {
				var mount = root.querySelector('[data-gateqris-qr-mount]');
				if (!mount) { return; }
				var payload = mount.getAttribute('data-gateqris-qr-payload') || '';
				var fail = <?php echo wp_json_encode( __( 'Gagal memuat QR. Silakan muat ulang halaman.', 'gateqris-payments' ) ); ?>;
				if (!payload || typeof window.qrcode !== 'function') { mount.textContent = fail; return; }
				try {
					var qr = window.qrcode(0, 'M');
					qr.addData(payload, 'Byte');
					qr.make();
					mount.innerHTML = qr.createSvgTag(6, 2, 'QRIS', 'GateQRIS QRIS');
				} catch (e) {
					mount.textContent = fail;
				}
			}());

			(function initCountdown() {
				var countdown = root.querySelector('[data-gateqris-countdown]');
				if (!countdown) { return; }
				var target = countdown.getAttribute('data-gateqris-countdown');
				if (!target) { countdown.textContent = 'N/A'; return; }
				var tick = function () {
					var diff = new Date(target).getTime() - Date.now();
					if (Number.isNaN(diff)) { countdown.textContent = 'N/A'; return; }
					if (diff <= 0) { countdown.textContent = '00:00'; return; }
					var s = Math.floor(diff / 1000);
					var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
					var parts = (h > 0 ? [h, m, sec] : [m, sec]).map(function (v) { return String(v).padStart(2, '0'); });
					countdown.textContent = parts.join(':');
					window.setTimeout(tick, 1000);
				};
				tick();
			}());

			(function pollStatus() {
				var statusEl = root.querySelector('[data-gateqris-status]');
				if (!statusEl) { return; }
				var feedbackEl = root.querySelector('[data-gateqris-feedback]');
				var refreshButton = root.querySelector('[data-gateqris-refresh]');
				var redirectUrl = root.getAttribute('data-gateqris-redirect') || '';

				var statusEndpoint = new URL(<?php echo wp_json_encode( $this->public_rest_url( 'gateqris/v1/transactions/' . rawurlencode( $uuid ) . '/status' ) ); ?>);
				statusEndpoint.searchParams.set('access_token', <?php echo wp_json_encode( $token ); ?>);
				var refreshEndpoint = new URL(<?php echo wp_json_encode( $this->public_rest_url( 'gateqris/v1/transactions/' . rawurlencode( $uuid ) . '/refresh' ) ); ?>);
				refreshEndpoint.searchParams.set('access_token', <?php echo wp_json_encode( $token ); ?>);

				var labels = {
					pending: <?php echo wp_json_encode( $this->status_label( 'PENDING' ) ); ?>,
					paid: <?php echo wp_json_encode( $this->status_label( 'PAID' ) ); ?>,
					expired: <?php echo wp_json_encode( $this->status_label( 'EXPIRED' ) ); ?>
				};
				var msg = {
					waiting: <?php echo wp_json_encode( __( 'Menunggu konfirmasi pembayaran…', 'gateqris-payments' ) ); ?>,
					checking: <?php echo wp_json_encode( __( 'Memeriksa status pembayaran…', 'gateqris-payments' ) ); ?>,
					expired: <?php echo wp_json_encode( __( 'Invoice ini sudah kedaluwarsa.', 'gateqris-payments' ) ); ?>
				};
				var setFeedback = function (m) { if (feedbackEl) { feedbackEl.textContent = m; } };

				var showSuccess = function () {
					['[data-gateqris-qr-card]', '[data-gateqris-howto]', '[data-gateqris-timer]'].forEach(function (sel) {
						var el = root.querySelector(sel); if (el) { el.setAttribute('hidden', ''); }
					});
					var success = root.querySelector('[data-gateqris-success]');
					if (success) { success.removeAttribute('hidden'); }
					if (redirectUrl) {
						var note = root.querySelector('[data-gateqris-redirect-note]');
						if (note) { note.removeAttribute('hidden'); }
						window.setTimeout(function () { window.location.href = redirectUrl; }, 2500);
					}
				};

				var applyStatus = function (status) {
					var n = String(status || '').toUpperCase();
					statusEl.classList.remove('is-pending', 'is-paid', 'is-expired');
					if (n === 'PAID' || n === 'MANUAL_ACC') {
						statusEl.textContent = labels.paid;
						statusEl.classList.add('is-paid');
						showSuccess();
						return true;
					}
					if (n === 'EXPIRED') {
						statusEl.textContent = labels.expired;
						statusEl.classList.add('is-expired');
						setFeedback(msg.expired);
						return true;
					}
					statusEl.textContent = labels.pending;
					statusEl.classList.add('is-pending');
					setFeedback(msg.waiting);
					return false;
				};

				var readLocalStatus = function () {
					return fetch(statusEndpoint.toString(), { credentials: 'same-origin' }).then(function (r) {
						if (!r.ok) { return false; }
						return r.json().then(function (d) { return applyStatus(d.status || ''); });
					});
				};
				var refreshFromProvider = function () {
					setFeedback(msg.checking);
					return fetch(refreshEndpoint.toString(), { method: 'POST', credentials: 'same-origin' }).then(function (r) {
						if (!r.ok) { return readLocalStatus(); }
						return r.json().then(function (d) { return applyStatus(d.status || ''); });
					});
				};

				if (refreshButton) { refreshButton.addEventListener('click', function () { refreshFromProvider(); }); }

				if (applyStatus(statusEl.getAttribute('data-gateqris-status-code'))) { return; }
				var loop = function () {
					refreshFromProvider().then(function (done) { if (!done) { window.setTimeout(loop, 5000); } });
				};
				loop();
			}());
		}());
		</script>
		<?php
		return (string) ob_get_clean();
	}

	public function render_hosted_status_page(): void {
		$uuid  = sanitize_text_field( (string) ( $_GET['gateqris_transaction'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( (string) ( $_GET['gateqris_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $uuid || '' === $token ) {
			wp_die( esc_html__( 'Missing transaction reference.', 'gateqris-payments' ), esc_html__( 'GateQRIS Payment Status', 'gateqris-payments' ), array( 'response' => 400 ) );
		}

		$status_markup = $this->render_status( $uuid, $token );
		if ( headers_sent() ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		echo $this->render_hosted_document( $status_markup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function render_hosted_document( string $status_markup ): string {
		$title = sprintf(
			/* translators: %s: store name */
			__( 'Pembayaran QRIS — %s', 'gateqris-payments' ),
			get_bloginfo( 'name' )
		);

		return '<!DOCTYPE html><html lang="id"><head>'
			. '<meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1" />'
			. '<meta name="robots" content="noindex,nofollow" />'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<link rel="preconnect" href="https://fonts.googleapis.com" />'
			. '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />'
			. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Lora:wght@400;500&display=swap" />'
			. '<style>'
			. ':root{color-scheme:light}'
			. 'html,body{margin:0;padding:0}'
			. 'body{min-height:100vh;background:#faf9f5;'
			. 'font-family:"Lora",Georgia,"Times New Roman",serif;-webkit-font-smoothing:antialiased;'
			. 'padding:40px 18px 56px;box-sizing:border-box}'
			. '</style>'
			. '</head><body>' . $status_markup . '</body></html>';
	}

	private function public_rest_url( string $path ): string {
		return $this->settings->public_url( '/wp-json/' . ltrim( $path, '/' ) );
	}

	private function status_class( string $status ): string {
		return match ( strtoupper( $status ) ) {
			'PAID', 'MANUAL_ACC' => 'is-paid',
			'EXPIRED'            => 'is-expired',
			default              => 'is-pending',
		};
	}

	private function status_label( string $status ): string {
		return match ( strtoupper( $status ) ) {
			'PAID'       => __( 'Lunas', 'gateqris-payments' ),
			'MANUAL_ACC' => __( 'Dikonfirmasi Manual', 'gateqris-payments' ),
			'EXPIRED'    => __( 'Kedaluwarsa', 'gateqris-payments' ),
			default      => __( 'Menunggu Pembayaran', 'gateqris-payments' ),
		};
	}
}
