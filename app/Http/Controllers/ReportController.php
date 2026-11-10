<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
}
