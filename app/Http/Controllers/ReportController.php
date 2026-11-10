<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use App\Support\MonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Reports
 *
 * Laporan dihitung langsung dari transaksi. Bulan menurut Asia/Jakarta; transfer tidak dihitung.
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $service) {}

    /**
     * Tren bulanan
     *
     * Pemasukan, pengeluaran, dan selisih per bulan; tepat `months` item, berakhir di bulan berjalan.
     *
     * @queryParam months integer 6 atau 12. Default 6. Example: 6
     */
    public function monthlyTrend(Request $request): JsonResponse
    {
        $v = $request->validate(['months' => ['sometimes', Rule::in([6, 12])]]);
        $months = (int) ($v['months'] ?? 6);

        return response()->json(['data' => $this->service->monthlyTrend($request->user(), $months)]);
    }

    /**
     * Rincian kategori lintas bulan
     *
     * @queryParam months integer 3 sampai 12. Default 6. Example: 6
     * @queryParam type string expense atau income. Default expense. Example: expense
     */
    public function categoryComparison(Request $request): JsonResponse
    {
        $v = $request->validate([
            'months' => ['sometimes', 'integer', 'between:3,12'],
            'type' => ['sometimes', Rule::in(['expense', 'income'])],
        ]);

        return response()->json(['data' => $this->service->categoryComparison(
            $request->user(), (int) ($v['months'] ?? 6), $v['type'] ?? 'expense'
        )]);
    }

    /**
     * Laporan per dompet
     *
     * Pemasukan, pengeluaran, dan transfer per dompet pada rentang tanggal (Asia/Jakarta, inklusif),
     * serta saldo akhir pada akhir hari `to`. Rentang maksimum 366 hari.
     *
     * @queryParam from string Tanggal awal `YYYY-MM-DD`. Default awal bulan berjalan. Example: 2026-11-01
     * @queryParam to string Tanggal akhir `YYYY-MM-DD`. Default akhir bulan berjalan. Example: 2026-11-30
     */
    public function wallets(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $now = CarbonImmutable::now(MonthRange::TIMEZONE);
        $from = (string) $request->query('from', $now->startOfMonth()->format('Y-m-d'));
        $to = (string) $request->query('to', $now->endOfMonth()->format('Y-m-d'));

        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);
        if ($days < 0) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        }
        if ($days > 365) {
            throw ValidationException::withMessages(['to' => 'Rentang maksimum 366 hari.']);
        }

        return response()->json(['data' => $this->service->wallets($request->user(), $from, $to)]);
    }
}
