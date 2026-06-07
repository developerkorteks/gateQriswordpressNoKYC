=== GateQRIS Payments ===
Contributors: grnstore
Tags: qris, payments, webhook, wallet, ledger, indonesia, payment gateway
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone GateQRIS payment plugin for WordPress with hosted payment forms, webhook verification, settlement, and internal wallet ledger.

== Description ==

GateQRIS Payments is a complete, production-ready payment gateway plugin that integrates GateQRIS QRIS payments with WordPress. Perfect for Indonesian e-commerce sites, digital services, and subscription platforms.

**Key Features:**

* **Public hosted payment form** - Shortcode `[gateqris_payment_form]` for customer payments
* **Payment status page** - Shortcode `[gateqris_payment_status]` to display transaction status
* **Admin invoice creation** - Create invoices manually from wp-admin
* **Hosted QRIS preview** - QRIS QR codes rendered in browser, with raw payload fallback
* **Signed API requests** - Cryptographic signing for all GateQRIS API calls
* **Verified webhooks** - HMAC signature verification with timestamp tolerance
* **Idempotent settlement** - Prevent duplicate settlements with idempotency keys
* **Internal wallet system** - Site-wide and per-user wallets for fund management
* **Append-only ledger** - Complete audit trail of all wallet transactions
* **Automatic polling** - Fallback polling for pending transactions if webhooks fail
* **User wallets** - Optional per-user wallets for affiliate, referral, or subscription models

**Supported Payment Methods:**

* QRIS (Quick Response Code Indonesian Standard)
* Standard banking transfers via QRIS
* Digital wallet payments via QRIS (GCash, OVO, Dana, LinkAja, etc.)

== Installation ==

1. Upload the `gateqris-payments` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress via Plugins menu.
3. Go to `GateQRIS Payments > Settings` in wp-admin.
4. Enter your GateQRIS API credentials:
   - Public Key
   - Secret Key
   - API Base URL (provided by GateQRIS)
5. Confirm the generated webhook URL.
6. Set `Public Base URL` if your site is behind a tunnel or reverse proxy (ngrok, Cloudflare Tunnel, etc).
7. Optional: Enable `User Wallets` to allow per-user wallet settlements.
8. Optional: Enable `Auto Create Wallet On User Registration` to auto-provision user wallets.
9. Add `[gateqris_payment_form]` shortcode to a public page for hosted payments.
10. Register the webhook URL in your GateQRIS dashboard.
11. Test with `GateQRIS Payments > Tools > Test Connection`.

**Important notes:**

* The GateQRIS payment method is intentionally HIDDEN at checkout until valid API
  credentials are saved, so customers never hit a mid-checkout failure.
* GateQRIS only settles in IDR. The method is not offered when the store currency
  is anything other than IDR.
* WooCommerce Block Checkout: this gateway uses the classic gateway API and may not
  appear in the Block-based checkout. If checkout shows "no payment methods", set the
  Checkout page content to the shortcode `[woocommerce_checkout]` (classic checkout).
* REST/webhook endpoints require pretty permalinks. If `/wp-json/` returns 404, go to
  Settings > Permalinks and select "Post name", then Save.

== Quick Start ==

**For Customers:**

1. Create a new public page (e.g., "Pembayaran QRIS").
2. Add the shortcode `[gateqris_payment_form]` to the page content.
3. Publish the page.
4. Share the page URL with customers.
5. Customers fill the form and pay via QRIS.

**For Administrators:**

1. Monitor payments at `GateQRIS Payments > Transactions`.
2. View wallet balances at `GateQRIS Payments > Wallets`.
3. Track settlements at `GateQRIS Payments > Settlements`.
4. View user payment history at `GateQRIS Payments > User Summary`.
5. Manually adjust wallets if needed via `GateQRIS Payments > Wallets > Wallet Detail`.

== Configuration ==

**Basic Settings:**

* **Public Key** - Your GateQRIS public key for API authentication.
* **Secret Key** - Your GateQRIS secret key (keep this secure!).
* **API Base URL** - GateQRIS API endpoint (e.g., `https://api.gateqris.com`).
* **Webhook Token** - Auto-generated random token for webhook security. Change in production.

**Advanced Settings:**

* **Public Base URL** - Override for tunnel/proxy setups. Leave blank for normal installations.
* **Enable User Wallets** - Allow settlement to per-user wallets (vs. site wallet only).
* **Auto Create Wallet On User Registration** - Auto-provision wallets for new WordPress users.
* **Debug Logging** - Enable detailed logs for troubleshooting (disable in production).
* **Poll Interval** - How often to check pending transactions (minutes).
* **Timestamp Tolerance** - Webhook timestamp tolerance for clock skew (seconds).
* **Retain Data on Uninstall** - Keep transactions and wallets when plugin is deleted.

== User Wallet Flow ==

**Wallet Types:**

* **Site Wallet** - Always exists. Default settlement target for payments.
* **User Wallets** - Optional per-user wallets. Created three ways:

  1. **Automatic** - When user registers (if `Auto Create Wallet On User Registration` enabled).
  2. **Lazy** - When payment first settles into that user (automatic provisioning).
  3. **Manual** - Admin creates wallet via `GateQRIS Payments > Wallets > Create Wallet`.

**Admin Actions:**

* View wallet details: `GateQRIS Payments > Wallets > [Wallet Name]`.
* See balance, recent transactions, settlements, and ledger entries.
* Apply manual adjustments (credit/debit) with reason.
* All adjustments are audit-logged in the ledger.

**Ledger Entry Types:**

* `settlement` - Fund received from completed transaction.
* `adjustment_credit` - Manual admin credit (e.g., refund, bonus).
* `adjustment_debit` - Manual admin debit (e.g., withdrawal, fee).

== Shortcodes ==

**[gateqris_payment_form]**

Renders a public payment form for customers. Attributes:

* None required - uses default site wallet.

Example:
`[gateqris_payment_form]`

**[gateqris_payment_status]**

Displays transaction status (optional - hosted payment links work standalone).

Example:
`[gateqris_payment_status]`

== REST API ==

**Create Transaction:**

```
POST /wp-json/gateqris/v1/transactions
Content-Type: application/json

{
  "amount": 150000,
  "customer_ref": "ORDER-12345",
  "customer_name": "Budi Santoso",
  "customer_email": "budi@example.com",
  "reference": "Order #12345",
  "idempotency_key": "unique-key-12345"
}
```

**Get Transaction Status:**

```
GET /wp-json/gateqris/v1/transactions/{transaction_uuid}
```

**Webhook Endpoint:**

```
POST /wp-json/gateqris/v1/webhook/{webhook_token}
```

Register this URL in your GateQRIS dashboard under Webhooks.

== Production Checklist ==

**Before Going Live:**

1. Verify API credentials are correct (test with `Tools > Test Connection`).
2. Rotate webhook token to a long random value.
3. Register webhook URL in GateQRIS dashboard.
4. Test webhook delivery with `Tools > Simulate Webhook`.
5. Verify public webhook URL is reachable from the internet.
6. Create a test invoice and verify settlement completes.
7. Check `Health Check` page - all indicators should be green.
8. Disable `Debug Logging` (enabled by default for development).
9. Enable automatic backups for your database.
10. Monitor `Webhook Logs` for failed deliveries (should be zero).

**Security:**

* Never hardcode credentials in plugin files - use Settings page.
* Rotate any secret key shared insecurely.
* Use HTTPS for all webhooks (not HTTP).
* Restrict `GateQRIS Payments` menu to trusted administrators.
* Review admin activity logs if available.

== Troubleshooting ==

**Webhook Not Receiving?**

1. Verify URL is publicly accessible: `curl https://yoursite.com/wp-json/gateqris/v1/webhook/[token]` should return 403 (forbidden - expected).
2. Check `GateQRIS Payments > Webhook Logs` for failed deliveries.
3. If using ngrok/tunnel, ensure `Public Base URL` is set correctly.
4. Confirm webhook URL in GateQRIS dashboard matches exactly.
5. Check server firewall - GateQRIS must reach your server.

**Payment Method Missing at Checkout?**

1. Make sure API credentials are saved (the method is hidden until then).
2. Make sure the store currency is IDR (GateQRIS is IDR-only).
3. If you use the WooCommerce Block Checkout, switch the Checkout page content to the
   classic shortcode `[woocommerce_checkout]` — this gateway uses the classic API.

**Checkout 404 Error?**

1. Verify checkout page exists: `GateQRIS Payments > Settings`.
2. Ensure checkout page slug is `checkout` (not `checkout-2`).
3. Regenerate WordPress permalinks: Settings > Permalinks > Save Changes.

**Payments Not Settling?**

1. Check `Transactions` page for transaction status.
2. Verify wallet is active in `Wallets` page.
3. Review `Settlements` page - settlement should exist.
4. Check error logs for settlement failures.
5. If settlement failed, manually reconcile via `Tools > Run Reconcile`.

**Database Issues?**

1. Check table count: `SELECT COUNT(*) FROM wp_gq_transactions;`
2. Check schema version: `SELECT option_value FROM wp_options WHERE option_name='gateqris_payments_schema_version';`
3. Current schema version: 4

== FAQ ==

**Q: Can I use user wallets with WooCommerce?**
A: Yes! Enable `User Wallets` in settings, then use `[gateqris_payment_form]` on any page. Payments settle to that user's wallet.

**Q: How do I refund a payment?**
A: GateQRIS doesn't support refunds yet. Instead, manually credit the user's wallet via `Wallets > Wallet Detail > Manual Adjustment`.

**Q: What happens if webhook delivery fails?**
A: The plugin automatically polls pending transactions every 5 minutes (configurable). Settlement will eventually complete via polling if webhook fails.

**Q: Can I handle multiple currencies?**
A: Not yet - v0.1.0 supports IDR only. Multi-currency planned for v0.2.0.

**Q: Is there an affiliate/referral system?**
A: Not built-in, but user wallets make it easy to build one - settle referral commissions to user wallets via `[gateqris_payment_form]`.

**Q: Can I export transaction history?**
A: Yes! Use `Tools > Export Transactions` to download CSV.

== Support & Contribution ==

**Support:** For GateQRIS API issues, contact GateQRIS directly.

**Bugs:** Report bugs and feature requests via your GateQRIS account or GitHub issues.

**Contributing:** Pull requests welcome! Please test thoroughly before submitting.

== Notes ==

* **Security:** Do not ship production credentials inside plugin files or version control.
* **Monetary Values:** All amounts stored as integer IDR minor units (1 IDR = 1). No decimal places.
* **Timestamps:** All times stored in UTC (GMT) in the database.
* **Idempotency:** Transactions are keyed by `idempotency_key` to prevent duplicates.
* **Ledger:** Wallet ledger is append-only - entries cannot be modified or deleted, only added.
* **Polling:** Fallback polling is triggered if webhook verification fails; polling result recorded in `last_update_source`.

== Changelog ==

= 0.2.0 =

* WooCommerce gateway built-in (no separate plugin needed)
* HPOS-compatible (WooCommerce High-Performance Order Storage)
* Server-side invoice creation — amount from order, never from client
* Atomic settlement — DB transaction wraps all money mutations
* Fixed status machine illegal transitions (expired→paid blocked)
* Idempotency race condition fixed (INSERT IGNORE)
* Webhook token now stable across requests
* Admin transactions list paginated (25/page)
* CSV export protected against formula injection
* LICENSE file added (GPL v2)

= 0.1.0 =

* Initial public release
* Complete QRIS payment gateway integration
* Site and user wallet system
* Append-only ledger
* Webhook verification with signature validation
* Automatic polling fallback
* Admin console for transactions, wallets, settlements
* Per-user payment summary dashboard

== License ==

This plugin is licensed under the GNU General Public License v2 or later. See LICENSE file for details.

== Contributors ==

* grnstore - Original author and maintainer

---

Changelog:
0.1.0 - Initial Release
