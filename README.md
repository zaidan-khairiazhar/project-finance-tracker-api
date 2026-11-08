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
| Transfers | `POST /transfers`, `DELETE /transfers/{groupId}` |
| Dashboard | `GET /dashboard/summary?month=YYYY-MM`, `GET /dashboard/by-category?month=YYYY-MM&type=expense\|income` |
