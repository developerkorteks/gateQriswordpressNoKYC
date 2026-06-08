# Setup GateQRIS Payments

Dokumen ini menjelaskan cara memakai plugin `GateQRIS Payments` di site WordPress baru maupun site yang sudah berjalan.

## Ringkasan Flow

1. Admin mengisi kredensial GateQRIS di wp-admin.
2. Admin menyiapkan minimal satu halaman publik berisi shortcode pembayaran.
3. User membuka halaman pembayaran dan membuat invoice QRIS.
4. Plugin membuat invoice ke GateQRIS, menampilkan hosted payment page, lalu menunggu webhook atau fallback refresh.
5. Saat status `PAID` atau `MANUAL_ACC` diterima, plugin melakukan settlement ke wallet internal dan menulis ledger.

## Setup Dasar

Buka `GateQRIS Payments` di wp-admin, lalu isi:

- `Public Key`
- `Secret Key`
- `API Base URL`
- `Webhook Token`
- `Public Base URL` bila site berada di balik tunnel atau reverse proxy

Setting yang disarankan:

- `Enable User Wallets`: `Yes`
- `Auto Create Wallet On User Registration`: `Yes`
- `Debug Logging`: `Yes` saat setup, lalu boleh dimatikan setelah stabil

## Halaman Publik

Buat halaman publik seperti:

- `Pembayaran QRIS`

Isi kontennya dengan shortcode:

```txt
[gateqris_payment_form]
```

Opsional, buat halaman status:

```txt
[gateqris_payment_status]
```

Catatan:

- hosted payment link plugin sudah bisa bekerja tanpa halaman status khusus
- shortcode status berguna kalau Anda ingin halaman status yang bisa dipanggil manual

## Webhook

Salin `Webhook URL` dari halaman `GateQRIS Payments`, lalu tempelkan ke dashboard GateQRIS.

Pastikan:

- URL publik benar-benar bisa diakses dari internet
- bila site memakai tunnel, `Public Base URL` sudah diisi
- token webhook bukan token lemah atau token test

## Wallet User

Plugin mendukung tiga cara pembuatan wallet user:

1. otomatis saat user WordPress register, bila `Auto Create Wallet On User Registration` aktif
2. otomatis saat wallet user pertama kali dibutuhkan dalam settlement
3. manual oleh admin dari `GateQRIS Payments > Wallets`

## Operasional Admin

Menu utama plugin:

- `Transactions`
- `Wallets`
- `Settlements`
- `Webhook Logs`
- `Tools`

Yang bisa dilakukan admin:

- membuat invoice admin
- melihat hosted payment link
- melihat wallet detail user
- melihat settlement dan ledger
- melakukan manual wallet adjustment `credit` atau `debit`
- menjalankan reconcile
- mengetes koneksi API
- mensimulasikan status webhook

## Testing Setelah Install

Lakukan urutan ini:

1. simpan setting plugin
2. buat satu halaman `[gateqris_payment_form]`
3. buat invoice test
4. buka hosted payment page
5. bayar atau simulasi status
6. cek:
   - `Transactions`
   - `Wallets`
   - `Settlements`
   - `Webhook Logs`

## Catatan Production

- jangan hardcode kredensial di source code
- rotate secret key yang pernah dibagikan tidak aman
- gunakan webhook token yang panjang dan acak
- pastikan polling hanya jadi fallback, webhook tetap jalur utama
- lakukan minimal satu uji invoice live setelah deploy
