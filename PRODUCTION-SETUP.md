# GateQRIS Payments - Production Setup Guide

## Overview

This guide walks through deploying GateQRIS Payments to a production environment safely and securely.

## Pre-Deployment Checklist

### 1. Environment Preparation

- [ ] WordPress 6.0+ installed and updated
- [ ] PHP 8.1+ running
- [ ] MySQL 8.0+ with proper character set (utf8mb4)
- [ ] HTTPS certificate installed (not self-signed)
- [ ] Domain name pointing to server
- [ ] Database backups enabled
- [ ] PHP memory is sufficient (plugin install / admin can exhaust low limits)

#### PHP memory (penting — cegah error saat install/aktivasi)

Instalasi/aktivasi plugin dan layar admin WooCommerce bisa kehabisan memori pada
limit rendah. Pastikan **PHP `memory_limit` minimal 256M** (disarankan 512M+), dan
naikkan batas admin WordPress ke **minimal 1 GB**. Tambahkan ke `wp-config.php`
(sebelum baris `/* That's all, stop editing! */`):

```php
define( 'WP_MEMORY_LIMIT', '512M' );      // frontend
define( 'WP_MAX_MEMORY_LIMIT', '1024M' ); // admin & install (minimal 1 GB)
```

Jika `memory_limit` PHP server lebih rendah dari di atas, naikkan juga di `php.ini`
(`memory_limit = 1024M`) atau via panel hosting, lalu restart PHP-FPM. Catatan:
`WP_MAX_MEMORY_LIMIT` hanya berlaku di area admin (termasuk proses install plugin),
jadi inilah yang harus minimal 1 GB.

### 2. GateQRIS Account Setup

- [ ] GateQRIS merchant account created and verified
- [ ] Production API keys obtained (not test/sandbox keys)
- [ ] Public Key documented
- [ ] Secret Key documented securely (password manager, not email)
- [ ] API Base URL noted

### 3. Plugin Preparation

- [ ] Latest version of GateQRIS Payments downloaded
- [ ] Changelog reviewed for breaking changes
- [ ] Plugin tested on staging environment first
- [ ] All tests passing locally
- [ ] No PHP warnings or errors in debug.log

### 4. WordPress Configuration

- [ ] Backup database BEFORE plugin activation
- [ ] Backup wp-content directory
- [ ] Git commit all changes (if using version control)
- [ ] Any database backups scheduled in production

## Step-by-Step Deployment

### Step 1: Upload Plugin

```bash
# Via SFTP or file manager:
scp -r gateqris-payments/ user@prodserver:/var/www/html/wp-content/plugins/

# OR via WordPress admin (if available):
# Dashboard → Plugins → Add New → Upload Plugin
```

### Step 2: Activate Plugin

1. Go to WordPress admin: `https://yourdomain.com/wp-admin`
2. Navigate to **Plugins**
3. Find **GateQRIS Payments**
4. Click **Activate**

**Expected result**: Plugin activates, database tables created automatically.

### Step 3: Verify Installation

1. Go to **GateQRIS Payments** → **Health Check**
2. Check all indicators:
   - Plugin Version: should show v0.1.0
   - Credentials Configured: should show "No" initially
   - Database tables: all should be present
   - Webhook Token Strength: should show "Strong"

### Step 4: Configure API Credentials

1. Go to **GateQRIS Payments** → **Settings**
2. Enter your production credentials:
   - **Public Key**: [from GateQRIS account]
   - **Secret Key**: [from GateQRIS account]
   - **API Base URL**: [from GateQRIS documentation]
3. Leave other settings at defaults initially
4. Click **Save Settings**

**Expected result**: Settings saved successfully.

### Step 5: Verify API Connection

1. Go to **GateQRIS Payments** → **Tools**
2. Click **Test Connection**
3. Wait for response (takes ~5 seconds)

**Expected result**: Success message with test invoice link.

### Step 6: Create Payment Form Page

1. Go to **Pages** → **Add New**
2. Title: `Pembayaran QRIS` (or your preferred name)
3. In content editor, add shortcode:
   ```
   [gateqris_payment_form]
   ```
4. Publish page

**Expected result**: Page is live at `/pembayaran-qris/`

### Step 7: Test Full Flow (Staging Environment First!)

1. Go to payment page: `https://yourdomain.com/pembayaran-qris/`
2. **In staging only**: Submit payment form
3. Should see QRIS QR code
4. Check wp-admin → **GateQRIS Payments** → **Transactions**
5. Transaction should appear with status "pending"

### Step 8: Register Webhook

1. Go to **GateQRIS Payments** → **Settings**
2. Copy the **Webhook URL** value
3. Go to **GateQRIS Dashboard** → **Webhooks** → **Add Webhook**
4. Paste webhook URL exactly as shown
5. Save in GateQRIS dashboard

**Expected result**: Webhook registered and status shows "Connected"

### Step 9: Test Webhook Delivery

1. Go to **GateQRIS Payments** → **Tools**
2. Scroll to **Simulate Webhook Delivery**
3. Click **Simulate Payment Received**
4. Go to **Webhook Logs** tab
5. Should see webhook delivery with result "processed"

**Expected result**: Webhook received and processed successfully.

### Step 10: Harden for Production

1. Go to **GateQRIS Payments** → **Settings**
2. Set:
   - **Webhook Token**: a long random value (the Health Check page must report "Strong")
   - **Debug Logging**: NO (disable for production)
3. Save
4. Ensure HTTPS is enforced at the server/proxy level (redirect HTTP → HTTPS). The
   webhook endpoint relies on the token + HMAC signature for authenticity; serve it
   over HTTPS so the payload and headers are never sent in clear text.

**Expected result**: Strong webhook token, debug logging off, HTTPS-only endpoint.

> Note: HTTPS enforcement and request rate limiting are handled at the web server /
> reverse proxy layer, not by plugin settings.

## Post-Deployment Verification

### Day 1

- [ ] Check Health Check page - all green
- [ ] Review Webhook Logs - no errors
- [ ] Monitor error logs for PHP warnings
- [ ] Check database size - should be < 5MB
- [ ] Verify backups are running

### Week 1

- [ ] Process real payment (if small volume allows)
- [ ] Verify transaction appears in wp-admin
- [ ] Verify settlement completed
- [ ] Check wallet shows credit
- [ ] Confirm webhook delivered
- [ ] Review error logs for any issues
- [ ] Verify HTTPS is enforced
- [ ] Test transaction export function

### Monthly

- [ ] Review transaction volume
- [ ] Check database size growth
- [ ] Audit manual wallet adjustments
- [ ] Review webhook delivery rate
- [ ] Rotate webhook token (optional)
- [ ] Verify backups are current

## Common Issues & Troubleshooting

### Issue: "This page doesn't seem to exist" on /checkout/

**Solution:**
1. Go to Pages in wp-admin
2. Find "Checkout" page
3. Verify slug is exactly `checkout` (not `checkout-2` or other variant)
4. Regenerate permalinks: Settings → Permalinks → Save Changes

### Issue: Webhook delivery failing

**Solution:**
1. Verify domain has public HTTPS certificate (not self-signed)
2. Check firewall allows inbound from GateQRIS IP ranges
3. Verify `Public Base URL` is empty or correct
4. Test via `Tools → Simulate Webhook Delivery`
5. Review error logs for details

### Issue: Credentials error "Invalid API Base URL"

**Solution:**
1. Verify URL format: `https://api.gateqris.com` (or your endpoint)
2. Ensure HTTPS is used
3. No trailing slash
4. Check credentials match your account type (production vs sandbox)

### Issue: "Webhook token looks weak"

**Solution:**
1. Go to Settings
2. Generate new token (click "Generate" if button available)
3. Copy new token
4. Update in GateQRIS dashboard webhooks
5. Save

## Security Hardening

### Essential Security Practices

1. **Credentials Management**
   - Never paste API keys in email, Slack, or unencrypted channels
   - Use password manager for long-term storage
   - Rotate keys if ever shared insecurely
   - Keep keys out of version control

2. **HTTPS Only**
   - All payment pages must be HTTPS (redirect HTTP → HTTPS)
   - Webhook URL must be HTTPS
   - Use valid SSL certificate (Let's Encrypt recommended)

3. **Access Control**
   - Only administrators should see payment pages
   - Restrict wp-admin to trusted IPs if possible
   - Enable WordPress security plugins (Wordfence, etc.)

4. **Monitoring**
   - Review webhook logs weekly
   - Monitor error logs for API failures
   - Set up alerts for webhook delivery failures
   - Track transaction volume for anomalies

5. **Data Backup**
   - Automated daily backups to external storage
   - Test restore procedure monthly
   - Keep backups for 30 days minimum

## Rollback Plan

If something goes wrong in production:

### Option 1: Disable Plugin

1. SSH into server
2. Rename plugin: `mv wp-content/plugins/gateqris-payments wp-content/plugins/gateqris-payments-disabled`
3. Verify site is accessible
4. Investigate issue offline

### Option 2: Restore from Backup

1. Stop WordPress
2. Restore database from backup
3. Restore wp-content from backup
4. Restart WordPress
5. Verify site is working

### Option 3: Deactivate via Database

```sql
UPDATE wp_options SET option_value = 'a:0:{}' WHERE option_name = 'active_plugins';
```

## Monitoring & Maintenance

### Daily Checks

```bash
# Check transaction volume
wp option get gq_transaction_count

# Check webhook status
wp option get gq_last_webhook_delivery

# Check error logs
tail -50 /var/www/html/wp-content/debug.log
```

### Weekly Tasks

- Review Health Check page
- Check Webhook Logs for failures
- Verify recent transactions settled
- Check database size

### Monthly Tasks

- Audit wallet adjustments
- Rotate webhook token (optional)
- Review transaction volume trends
- Test disaster recovery

## Support & Escalation

### Before Contacting Support

1. Check Health Check page for diagnostics
2. Review Webhook Logs for errors
3. Check WordPress error logs
4. Verify credentials are correct
5. Test with `Tools → Test Connection`

### Gather Information

```bash
# Export system info
wp plugin list
wp core version
php -v
mysql --version

# Export transaction count
wp option get gateqris_payments_settings

# Export error logs (last 100 lines)
tail -100 /var/www/html/wp-content/debug.log > support-logs.txt
```

## References

- GateQRIS API Documentation: https://docs.gateqris.com
- WordPress REST API: https://developer.wordpress.org/rest-api/
- PHP 8.1 Requirements: https://www.php.net/releases/8.1/
- HTTPS Best Practices: https://www.ssl.com/

---

**Questions?** Contact GateQRIS support or refer to the plugin's FAQ section in readme.txt.
