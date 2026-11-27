# Finance Tracker API

API pencatatan keuangan pribadi: dompet, kategori, transaksi, transfer antar dompet, dan ringkasan bulanan.
Dibangun dengan Laravel 12, Sanctum, dan PostgreSQL. Klien web ada di repo `finance-web` (Next.js).

## Fitur

- Registrasi dan login (token Sanctum), setiap user mendapat dompet "Tunai" dan kategori bawaan.
- Dompet dengan saldo yang **dihitung** dari transaksi (tidak disimpan), bisa diarsipkan.
- Kategori pemasukan/pengeluaran, maksimal dua tingkat.
- Transaksi dengan filter (dompet, kategori, tipe, tanggal, pencarian catatan) dan paginasi cursor.
- Transfer antar dompet: dua baris transaksi yang saling terhubung, atomik.
- Dashboard bulanan (pemasukan, pengeluaran, selisih, total saldo, total per kategori).
- Anggaran bulanan per kategori (`over` jika terpakai > batas, `warning` mulai 80%); anggaran induk menjumlah subkategorinya.
- Laporan: tren bulanan, perbandingan kategori antar bulan, dan ringkasan per dompet pada rentang tanggal.
- Transaksi berulang (harian/mingguan/bulanan): aturan membuat transaksi biasa, termasuk kemunculan yang terlewat dengan tanggal yang benar. Dipicu `php artisan recurring:generate` (terjadwal 00:05 WIB, perlu `schedule:run` di cron) dan otomatis saat login. Transaksi yang dihapus tidak dibuat ulang.
- Target tabungan: progres dihitung dari saldo satu dompet (tidak disimpan), dengan persen, sisa, status (`in_progress`/`reached`/`overdue`), dan kebutuhan per bulan sampai tenggat. Satu dompet hanya untuk satu target aktif.
- Ekspor transaksi ke CSV (UTF-8 dengan BOM, waktu WIB, maksimum 50.000 baris).

## Keputusan desain

- Semua id memakai UUID. Uang disimpan sebagai `bigint` rupiah utuh.
- Waktu disimpan **UTC**; batas bulan dihitung di **Asia/Jakarta**. Klien mengirim waktu dengan offset (mis. `2026-11-07T23:30:00+07:00`).
- Data milik user lain dikembalikan sebagai **404**, bukan 403. Id di body yang bukan milik user menghasilkan **422**.
- Dompet yang diarsipkan bersifat baca-saja.
- Format error: `{ "message": "...", "errors": { "field": ["..."] } }` (401, 403, 404, 422). Login dibatasi 5 percobaan per menit.

## Prasyarat

PHP 8.3+, Composer, PostgreSQL 16, dan dua database: `finance` dan `finance_test`.

## Menjalankan

```bash
composer install
cp .env.example .env          # PowerShell: Copy-Item .env.example .env
php artisan key:generate
# isi DB_* di .env (DB_CONNECTION=pgsql, DB_DATABASE=finance, ...)
php artisan migrate
php artisan serve
```

API tersedia di `http://localhost:8000/api/v1`.

## Test

```bash
php artisan test
```

## Dokumentasi API

```bash
php artisan scribe:generate
```

Hasilnya ada di `public/docs/index.html` (buka lewat `http://localhost:8000/docs` saat `php artisan serve` berjalan), lengkap dengan koleksi Postman dan spesifikasi OpenAPI.

## Endpoint (prefiks `/api/v1`)

| Grup | Endpoint |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` |
| Wallets | `GET\|POST /wallets`, `GET\|PATCH\|DELETE /wallets/{id}` |
| Categories | `GET\|POST /categories`, `PATCH\|DELETE /categories/{id}` |
| Transactions | `GET\|POST /transactions`, `GET\|PATCH\|DELETE /transactions/{id}` |
| Receipts | `POST\|GET\|DELETE /transactions/{id}/receipt` (unggah `image` multipart JPEG/PNG/WebP maks. 5 MB, mengganti yang lama; lihat berkas; hapus, idempoten). Hanya pemasukan dan pengeluaran; transfer ditolak 422. |
| Receipt scan | `POST /receipts/scan` (multipart `image`, JPEG/PNG/WebP maks. 5 MB) → `{data: {total, date, merchant, category_id}}` sebagai saran isian; tidak menyimpan apa pun. 503 `scan_unavailable` bila pembaca mati, 502 `scan_failed` bila gagal dibaca, 429 bila melebihi `RECEIPT_SCAN_PER_HOUR` (20/jam per pengguna). |
| Transfers | `POST /transfers`, `DELETE /transfers/{groupId}` |
| Dashboard | `GET /dashboard/summary?month=YYYY-MM`, `GET /dashboard/by-category?month=YYYY-MM&type=expense\|income` |
| Budgets | `GET /budgets?month=YYYY-MM`, `PUT /budgets` (upsert; 201 jika baru, 200 jika diperbarui), `DELETE /budgets/{id}` |
| Reports | `GET /reports/monthly-trend?months=6\|12`, `GET /reports/category-comparison?months=3..12&type=expense\|income`, `GET /reports/wallets?from=&to=` (maks. 366 hari) |
| Recurring | `GET\|POST /recurring-rules`, `PATCH\|DELETE /recurring-rules/{id}`, `POST /recurring-rules/run` (jalankan aturan yang jatuh tempo; mengembalikan `{created}`). `frequency` dan `start_date` tidak bisa diubah setelah dibuat. |
| Goals | `GET\|POST /goals`, `PATCH\|DELETE /goals/{id}`. Daftar urut tenggat naik (tanpa tenggat di akhir). `current_amount` = saldo dompet; `wallet_status` = `ok\|archived\|deleted`. Tenggat tidak boleh sebelum hari ini (WIB), dan pada `PATCH` hanya diperiksa bila berubah. |
| Export | `GET /transactions/export` (CSV; filter sama seperti daftar transaksi) |

## Scan struk

Foto struk bisa disimpan per transaksi dan dibaca otomatis oleh AI untuk saran isian. Pembaca mati secara bawaan (`RECEIPT_SCANNER_DRIVER=null`; `POST /receipts/scan` menjawab 503), tetapi unggah foto tetap jalan.

| Variabel `.env` | Fungsi |
|---|---|
| `RECEIPT_SCANNER_DRIVER` | `null` (mati, bawaan), `gemini`, atau `claude` |
| `GEMINI_API_KEY` | Kunci API Gemini (driver `gemini`) |
| `RECEIPT_GEMINI_MODEL` | Model Gemini, bawaan `gemini-3.5-flash-lite` |
| `ANTHROPIC_API_KEY` | Kunci API Anthropic (driver `claude`) |
| `RECEIPT_CLAUDE_MODEL` | Model Claude, bawaan `claude-haiku-5-5` |
| `RECEIPT_DISK` | Disk penyimpanan foto, bawaan `receipts` (privat, tidak disajikan lewat URL) |
| `RECEIPT_SCAN_PER_HOUR` | Batas scan per pengguna per jam, bawaan 20 |

- **Batas unggah PHP:** bawaan PHP `upload_max_filesize=2M` lebih kecil dari batas foto 5 MB. Set `upload_max_filesize` dan `post_max_size` minimal `6M` di `php.ini`, dan `client_max_body_size 6m;` bila memakai nginx.
- **Privasi:** hanya gambar dan daftar kategori pengeluaran pengguna yang dikirim ke penyedia AI. Pada tier gratis Gemini, Google dapat memakai data yang dikirim untuk meningkatkan produknya; tier berbayar atau driver `claude` tidak.
- **Pembaca bisa diganti:** implementasikan `App\Services\Receipts\ReceiptScanner` dan daftarkan sebagai driver baru.
