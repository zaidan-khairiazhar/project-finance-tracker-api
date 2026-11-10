<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\TransactionCsv;
use App\Support\MonthRange;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Transactions
 */
class TransactionExportController extends Controller
{
    public function __construct(private TransactionCsv $csv) {}

    /**
     * Ekspor transaksi ke CSV
     *
     * Filter sama seperti daftar transaksi, tanpa paginasi. Berkas UTF-8 dengan BOM, waktu dalam WIB,
     * urut dari yang terbaru. Maksimum 50.000 baris (lebih dari itu 422: persempit filter).
     *
     * @queryParam wallet_id string UUID dompet. Example: 0198f0a0-0000-7000-8000-000000000000
     * @queryParam category_id string UUID kategori.
     * @queryParam type string income, expense, transfer_out, atau transfer_in.
     * @queryParam from string Tanggal awal `YYYY-MM-DD` (Asia/Jakarta). Example: 2026-11-01
     * @queryParam to string Tanggal akhir `YYYY-MM-DD` (Asia/Jakarta, inklusif). Example: 2026-11-30
     * @queryParam q string Cari di catatan.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'wallet_id' => ['sometimes', 'uuid'],
            'category_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::in(['income', 'expense', 'transfer_out', 'transfer_in'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        $filename = 'transaksi-'.now(MonthRange::TIMEZONE)->format('Ymd').'.csv';

        return $this->csv->stream(Transaction::query()->filter($filters), $filename);
    }
}
