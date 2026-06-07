# Changelog

All notable changes to GateQRIS Payments plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-06-04

### Added

- **WooCommerce gateway built-in** — `GRN\GateQris\WooCommerce\Gateway` registers as a native WC payment method; no separate bridge plugin required
- **HPOS compatibility** — declared via `FeaturesUtil::declare_compatibility`; works with WooCommerce High-Performance Order Storage
- **Server-side invoice creation** — `process_payment()` creates the GateQRIS invoice before redirect; amount taken from `$order->get_total()` (never from client input)
- **WooCommerce order auto-complete** — `WebhookHandler` calls `payment_complete()` via WC CRUD when transaction is paid; fully HPOS-safe
- **Invoice reuse** — pending, non-expired invoice is reused on checkout refresh to avoid duplicate gateway calls
- `LICENSE` file (GPL v2)
- `WC requires at least` and `WC tested up to` plugin headers

### Changed

- `StatusMachine` now uses an explicit allowed-transitions map instead of a numeric priority order — blocks previously possible illegal transitions such as `expired → paid_unsettled`
- `IdempotencyRepository::create()` uses `INSERT IGNORE` — eliminates check-then-insert race condition under concurrent requests
- `SettlementService::settle()` wraps all money mutations (ledger insert + balance update + settlement row) in a `START TRANSACTION / COMMIT / ROLLBACK` block — prevents partial writes
- `Settings::get_all()` generates and persists `webhook_token` once on first call instead of regenerating every request — webhook URL is now stable
- `WalletService::resolve_wallet()` now respects the `auto_create_user_wallets` setting when auto-provisioning user wallets during settlement
- Admin transactions list is paginated (25 per page) — prevents unbounded queries on busy stores
- Wallet owner name lookup in transactions filter is batch-loaded (single query) instead of one query per wallet row
- CSV export sanitises cells starting with `=`, `+`, `-`, `@` to prevent formula injection in spreadsheet applications
- Plugin version constant and file header bumped to `0.2.0`

### Removed

- Dead code: duplicate `includes/TransactionsController.php` (root level) that was never loaded by the autoloader
- `gateqris-woocommerce-gateway` standalone bridge plugin is superseded; its main file now shows a deprecation notice only

### Fixed

- (C1) Payment amount is now authoritative from `$order->get_total()` — clients can no longer submit an arbitrary amount to underpay a WooCommerce order
- (C2) All WooCommerce order writes use the WC CRUD API — no direct SQL to `wp_posts` or `wp_postmeta`
- (C3) Settlement money mutations are atomic via DB transaction
- (C4) QRIS invoice is created in `process_payment()` server-side — payment no longer depends on JavaScript running successfully in the browser
- (H1) `expired → paid_unsettled` and other illegal status transitions are now rejected by the explicit transition map
- (H2) Idempotency key reserve is now race-condition safe
- (M2) Webhook token is generated once and persisted — URL stays stable across requests
- (M5) CSV export is protected against formula injection
- (M9) `FormFilter` validates order ownership via `wc_get_order()` before injecting `woo_order_id`
- (M10) `WalletService::resolve_wallet()` no longer silently creates user wallets when `auto_create_user_wallets` is disabled

---

## [0.1.0] - 2026-06-03

### Added

- Initial public release of GateQRIS Payments plugin
- Complete QRIS payment gateway integration with GateQRIS API
- Hosted payment form shortcode `[gateqris_payment_form]` for customer payments
- Payment status shortcode `[gateqris_payment_status]` for transaction lookups
- Signed GateQRIS API requests using HMAC-SHA256
- Webhook endpoint with signature verification and timestamp tolerance
- Idempotent transaction creation to prevent duplicate invoices
- Site-wide wallet for fund aggregation
- Per-user wallet system with automatic or manual provisioning
- Append-only wallet ledger for complete audit trail
- Automatic wallet provisioning on new user registration (configurable)
- Admin console for transaction management
  - View all transactions with search and filtering
  - Filter by gateway status (PENDING, PAID, MANUAL_ACC, EXPIRED)
  - Filter by internal status (draft, pending_payment, paid_unsettled, settled, etc)
  - Filter by update source (webhook, polling, admin_simulation)
  - **NEW:** Filter by wallet owner (site or user)
  - Create admin invoices manually
  - Refresh transaction status on demand
  - View transaction detail with webhook history
- Admin console for wallet management
  - View site and user wallets
  - See wallet balances (available, pending, reserved)
  - View wallet detail pages with recent transactions and settlements
  - Manual wallet adjustments (credit/debit) with audit trail
  - Create user wallets manually
  - View wallet ledger entries
- **NEW:** Per-user payment summary dashboard
  - Quick wallet stats (available balance, pending balance, total received)
  - Recent transactions per user
  - Recent settlements per user
  - Ledger history with entry types
  - Links back to full transaction/settlement lists
- Admin console for settlement tracking
  - View all settlements
  - **NEW:** Filter settlements by wallet owner
  - **NEW:** See wallet owner for each settlement
  - Track settlement status (pending, confirmed)
- Admin console for webhook management
  - View webhook delivery logs
  - See webhook payload and verification status
  - Track successful and failed deliveries
- Automatic fallback polling for pending transactions
  - Default interval: 5 minutes (configurable)
  - Runs via WordPress scheduled events (wp-cron)
  - Marked in transaction `last_update_source` field
  - Prevents payment delays if webhooks fail
- Admin Tools page
  - Test API connection (creates test invoice)
  - Manual transaction reconciliation
  - Webhook delivery simulation
  - Transaction export to CSV
- Health Check page showing:
  - Plugin version
  - Credentials configuration status
  - Webhook URL
  - Public Base URL override status
  - Webhook token strength
  - Next scheduled poll time
  - QR code renderer mode (local vs raw fallback)
  - Database table status
- Production readiness warnings
  - Alert if using localhost/private URLs (webhooks can't reach)
  - Alert if webhook token is weak (< 24 chars or known test values)
  - Alert if API configuration looks like test/mock settings
  - Alert if public webhook URL doesn't match settings
- Database schema v4 with automatic migrations
  - `wp_gq_transactions` - Invoice records with status tracking
  - `wp_gq_webhook_events` - Webhook delivery log
  - `wp_gq_wallet_accounts` - Site and user wallets
  - `wp_gq_ledger_entries` - Append-only wallet ledger
  - `wp_gq_settlements` - Settlement records linking transactions to wallets
  - `wp_gq_idempotency_keys` - Duplicate request prevention
- User wallet flow documentation
  - Auto-provision on user registration
  - Lazy provision on first settlement
  - Manual creation by admin
- Comprehensive README.md with:
  - Feature descriptions
  - Installation guide
  - Quick start guide
  - Configuration options
  - REST API documentation
  - Shortcode reference
  - Troubleshooting guide
  - FAQ section
  - Production checklist
- Extended logging support via Logger class
  - Info, warning, error levels
  - Respects WordPress WP_DEBUG setting
  - Sanitizes sensitive data from logs
  - Saved to debug.log

### Security

- Cryptographic request signing (HMAC-SHA256) for all GateQRIS API calls
- Webhook signature verification with timestamp tolerance (configurable)
- Double validation on webhook endpoint (token + signature)
- Input sanitization on all wp-admin forms
- Nonce verification on all admin POST actions
- Capability checks (manage_options required)
- No sensitive data logged (API keys, tokens)
- SQL prepared statements for all queries
- No hardcoded credentials in plugin files

### Database

- Automatic schema creation and migration
- Version tracking (current: v4)
- UUID generation for all records
- Append-only ledger design (no record deletion)
- Proper charset/collation handling
- Indexing on frequently queried columns

### API Integration

- Signed requests to GateQRIS API endpoints
- Webhook receiver with signature verification
- Idempotency key support for request deduplication
- Timestamp tolerance for webhook clock skew (300s default)
- Error handling with user-friendly messages
- Rate limiting awareness (respects GateQRIS API limits)

### Documentation

- Inline code comments for complex logic
- Admin page help text
- Setting descriptions in Settings page
- Production checklist in Tools page
- Health Check page for diagnostics

## Notes

- All monetary amounts stored as integer IDR minor units (1 IDR = 1, no decimals)
- All timestamps in UTC (GMT) format with `_gmt` suffix
- Plugin requires PHP 8.1+ (uses typed properties, named arguments, etc)
- Requires WordPress 6.0+ (uses modern WP APIs)
- Database tables prefixed with `gq_` to avoid conflicts

## Future Roadmap (Not in 0.1.0)

- [ ] Multi-currency support (currently IDR only)
- [ ] Refund handling (GateQRIS API support pending)
- [ ] Invoice expiration policies
- [ ] Payment plan / subscription support
- [ ] Advanced reporting and analytics
- [ ] REST API v2 improvements
- [ ] Mobile app integration
- [ ] White-label branding options
- [ ] Setup wizard for first-time activation
- [ ] Automated backups to external storage
- [ ] Real-time dashboard widgets
- [ ] Payment email notifications
- [ ] Customer invoice portal
- [ ] Affiliate / referral system (with user wallets)
- [ ] Settlement scheduling to external bank accounts
- [ ] Advanced rate limiting and fraud detection
- [ ] PCI compliance enhancements
- [ ] Multi-language support (i18n)
