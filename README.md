# GateQRIS Payments

Plugin pembayaran **QRIS** untuk WordPress & WooCommerce. Terima pembayaran QRIS
(GoPay, OVO, DANA, ShopeePay, m-banking, dll.) dengan halaman pembayaran ber-QR
yang rapi, verifikasi webhook, settlement otomatis, dan buku besar (ledger) dompet
internal.

> **Produk kedua dari [grnstore.my.id](https://grnstore.my.id) — yaitu
> [gateqris.grnstore.my.id](https://gateqris.grnstore.my.id).**

## Kenapa GateQRIS

- **Tanpa KYC** — terima pembayaran QRIS tanpa proses verifikasi identitas yang berbelit. Langsung jalan.
- **Biaya transparan** — tidak ada potongan tersembunyi di sisi plugin. Yang dibayar pelanggan dan yang masuk ke dompet jelas; *unique code* QRIS tidak dihitung sebagai saldo.
- **Mutasi jelas** — setiap rupiah tercatat di **ledger append-only** (tak bisa diubah/dihapus). Tiap settlement, pembayaran saldo, refund, dan penyesuaian admin punya jejak audit `before -> after`.
- **Ringan** — pembayaran saldo & refund adalah transaksi database lokal yang atomik. Cocok untuk hosting terbatas.
- **Self-contained** — wallet, ledger, settlement semua di dalam WordPress; tanpa infrastruktur eksternal tambahan.

- **Versi:** 0.2.0
- **Butuh:** WordPress 6.0+, PHP 8.1+, (opsional) WooCommerce 7.0+
- **Mata uang:** Rupiah (IDR) saja
- **Lisensi:** GPLv2 atau lebih baru

---

## Daftar Isi

1. [Instalasi cepat](#instalasi-cepat)
2. [Konfigurasi kredensial](#konfigurasi-kredensial)
3. [Pakai dengan WooCommerce](#pakai-dengan-woocommerce)
4. [Pakai tanpa WooCommerce (shortcode)](#pakai-tanpa-woocommerce-shortcode)
5. [Webhook](#webhook)
6. [Uji coba tanpa pembayaran asli](#uji-coba-tanpa-pembayaran-asli)
7. [Troubleshooting](#troubleshooting)
8. [Keamanan & produksi](#keamanan--produksi)
9. [Sistem Saldo / Wallet User](#sistem-saldo--wallet-user-opsional)
10. [Uninstall](#uninstall)

---

## Instalasi cepat

1. Masuk ke **wp-admin → Plugins → Add New → Upload Plugin**.
2. Pilih berkas **`gateqris-payments-0.2.0.zip`**, klik **Install Now**.
3. Klik **Activate**.

Saat aktif, plugin otomatis membuat tabel database dan dompet utama (site wallet).
Cek **GateQRIS Payments → Health Check** — semua tabel harus berstatus *Present*.

> Butuh: WordPress 6.0+ dan PHP 8.1+. Cek di **Tools → Site Health → Info** bila ragu.

---

## Konfigurasi kredensial

Buka **GateQRIS Payments → Settings**, isi:

| Field | Keterangan |
|---|---|
| **Public Key** | Public key dari akun GateQRIS Anda |
| **Secret Key** | Secret key dari akun GateQRIS (dirahasiakan, tampil termasker setelah disimpan) |
| **API Base URL** | Endpoint API GateQRIS, mis. `https://api.gateqris.com` |
| **Webhook Token** | Token acak (sudah dibuat otomatis; pastikan "Strong" di Health Check) |

Klik **Save Settings**, lalu **GateQRIS Payments → Tools → Test Connection** untuk
memastikan kredensial benar.

> **Penting:** Sebelum kredensial diisi, metode pembayaran GateQRIS **sengaja
> disembunyikan** di checkout agar pelanggan tidak gagal bayar di tengah jalan.
> Setelah kredensial valid, metode otomatis muncul.

---

## Pakai dengan WooCommerce

1. Pastikan **mata uang toko = IDR** (*WooCommerce → Settings → General → Currency*).
   GateQRIS hanya melayani IDR; pada mata uang lain metode tidak akan tampil.
2. Aktifkan WooCommerce dan isi kredensial GateQRIS (lihat di atas).
3. Metode **"GateQRIS - QRIS QR Code"** otomatis aktif di
   *WooCommerce → Settings → Payments*. Judul & deskripsi bisa diubah di sana.

Alur pelanggan: checkout → diarahkan ke halaman QRIS → scan & bayar → halaman
menampilkan "Pembayaran Berhasil" dan kembali ke halaman pesanan secara otomatis.
Pesanan diselesaikan otomatis begitu pembayaran terkonfirmasi (via webhook).

### Checkout berbasis Block (penting)

WooCommerce versi baru memakai **Checkout block** secara default. Plugin ini
memakai gateway klasik, sehingga **mungkin tidak muncul** di Checkout block.

Jika di halaman checkout tertulis *"There are no payment methods available"*
padahal kredensial sudah benar, ubah halaman Checkout ke checkout klasik:

1. **Pages → Checkout → Edit**.
2. Hapus block "Checkout", ganti isi halaman dengan shortcode:
   ```
   [woocommerce_checkout]
   ```
3. **Update**. Muat ulang halaman checkout — metode GateQRIS akan muncul.

---

## Pakai tanpa WooCommerce (shortcode)

Untuk tagihan manual / donasi / pembayaran lepas tanpa WooCommerce:

1. Buat halaman baru, mis. **"Pembayaran QRIS"**.
2. Tambahkan shortcode:
   ```
   [gateqris_payment_form]
   ```
3. Publikasikan. Pelanggan isi nominal → dapat halaman QRIS.

Shortcode status (opsional): `[gateqris_payment_status]`.

---

## Webhook

Webhook membuat status pembayaran terupdate seketika. Tanpa webhook, plugin tetap
aman karena ada **polling otomatis** sebagai cadangan (default tiap 5 menit).

1. Buka **GateQRIS Payments → Settings**, salin nilai **Webhook URL**.
2. Tempel URL itu ke dashboard GateQRIS pada bagian Webhooks.

### Situs lokal / di balik tunnel (ngrok, cloudflared)

Webhook butuh URL yang bisa diakses dari internet. Bila situs Anda lokal atau di
balik tunnel, isi **Public Base URL** di Settings dengan domain publik tunnel
(mis. `https://namaku.trycloudflare.com`). Webhook URL akan otomatis memakai
domain tersebut. Kosongkan untuk kembali ke URL situs normal.

---

## Uji coba tanpa pembayaran asli

Untuk menguji alur penyelesaian pesanan tanpa benar-benar membayar:

1. **GateQRIS Payments → Tools → Create Test Invoice** (membuat invoice Rp 1.000).
2. Buka **hosted payment page**-nya untuk melihat tampilan halaman pembayaran.
3. **Tools → Webhook Simulator**: tempel UUID transaksi, pilih status **PAID**,
   jalankan. Transaksi akan settle ke dompet, ledger tercatat, dan (jika dari
   WooCommerce) pesanan diselesaikan.

---

## Troubleshooting

**Metode pembayaran tidak muncul di checkout**
- Pastikan **kredensial sudah diisi** (Settings) — tanpa itu metode disembunyikan.
- Pastikan **mata uang toko = IDR**.
- Jika pakai **Checkout block**, ganti ke `[woocommerce_checkout]` (lihat di atas).

**REST API / webhook balas 404**
- Aktifkan **pretty permalink**: *Settings → Permalinks → pilih "Post name" → Save*.
  Endpoint `/wp-json/` butuh ini.

**Webhook tidak masuk**
- Pastikan Webhook URL bisa diakses dari internet (cek Public Base URL bila pakai tunnel).
- Cek **GateQRIS Payments → Webhook Logs**.
- Pastikan Secret Key benar (dipakai untuk verifikasi tanda tangan webhook).

**Status pembayaran tidak berubah**
- **Tools → Run Reconcile Now** untuk menarik status terbaru dari GateQRIS.
- Cek **Health Check** dan **Transactions** untuk detail.

---

## Keamanan & produksi

- Semua permintaan API ditandatangani (HMAC-SHA256); webhook diverifikasi tanda
  tangan + token + toleransi waktu.
- Jangan menaruh kredensial di dalam berkas plugin / version control — isi lewat Settings.
- Gunakan **HTTPS** (diberlakukan di level server/proxy). Endpoint webhook harus HTTPS.
- Pastikan **Webhook Token** "Strong" dan **Debug Logging** dimatikan di produksi.
- Lihat **PRODUCTION-SETUP.md** untuk checklist deploy lengkap.

---

## Sistem Saldo / Wallet User (opsional)

Selain menerima pembayaran QRIS biasa, plugin bisa dipakai sebagai **sistem saldo**:
pelanggan mengisi saldo via QRIS, lalu memakai saldo itu untuk membeli produk.

Ada dua arah uang:

1. **Top-up (isi saldo)** — pelanggan bayar QRIS, saldo masuk ke **wallet pelanggan**.
2. **Belanja (bayar pakai saldo)** — saat checkout, saldo pelanggan dipotong dan
   dipindahkan ke **site wallet**; pesanan langsung lunas (tanpa QRIS, instan).

### Mengaktifkan

1. **GateQRIS Payments → Settings:**
   - **Enable User Wallets** = ya (default ya).
   - **Auto Create Wallet On User Registration** = ya — setiap user baru otomatis
     dapat wallet. (User lama dapat wallet otomatis saat pertama menerima saldo.)
2. **WooCommerce → Settings → Payments:** aktifkan **"Bayar pakai Saldo"**
   (default nonaktif). Metode ini hanya tampil untuk pelanggan **login** yang
   **saldonya cukup** menutup total keranjang.

### Cara pakai

- **Isi saldo (top-up):** buat halaman "Isi Saldo" dengan shortcode yang menyebut
  target **user** secara eksplisit:
  ```
  [gateqris_payment_form wallet="user"]
  ```
  Pelanggan (login) isi nominal → bayar QRIS → saldonya bertambah. Halaman ini
  meminta login bila pengunjung belum masuk.
- **Pembayaran umum / donasi (ke kas merchant):**
  ```
  [gateqris_payment_form wallet="site"]
  ```
- Tanpa atribut, `[gateqris_payment_form]` memakai default global
  **Public Form Default Wallet** di Settings. Karena targetnya per-shortcode, satu
  situs bisa punya halaman top-up (user) **dan** halaman pembayaran umum (site)
  sekaligus.
- **Belanja:** di checkout, pelanggan pilih **"Bayar pakai Saldo"** → saldo dipotong,
  pesanan lunas seketika.
- **Pantau:** semua mutasi tercatat di buku besar (ledger) per wallet di
  **GateQRIS Payments → Wallets**. Admin juga bisa kredit/debit manual.

### Refund otomatis

Jika order yang dibayar pakai **saldo** berubah status menjadi **cancelled /
failed / refunded**, plugin otomatis mengembalikan **seluruh** saldo ke wallet
pelanggan (kebalikan transfer: site → user). Aman dari pengembalian ganda
(idempoten). Bila saldo site sedang tidak cukup untuk mengembalikan, refund tidak
dipaksakan (site wallet tak boleh minus) — sebuah catatan order ditambahkan agar
admin menanganinya manual.

> Catatan: fitur saldo hanya untuk pelanggan login dan mata uang IDR. Pembayaran
> & refund saldo adalah transaksi database lokal yang atomik (aman dari saldo minus
> & balapan) — ringan, cocok untuk hosting terbatas. Refund otomatis berlaku untuk
> pembatalan penuh; refund parsial WooCommerce tidak otomatis mengembalikan saldo.

---

## Uninstall

Saat plugin dihapus, perilaku data mengikuti setting **Retain Data on Uninstall**:

- **Aktif (default):** transaksi, dompet, ledger, dan setting **dipertahankan**.
- **Nonaktif:** seluruh tabel `gq_*` dan setting **dihapus permanen**.

---

## Dukungan

- Masalah API/kredensial: hubungi penyedia GateQRIS.
- Dokumen tambahan: `PRODUCTION-SETUP.md`, `docs/SETUP-ID.md`, `CHANGELOG.md`.
