<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @group Dashboard
 *
 * Ringkasan bulanan; batas bulan dihitung di zona Asia/Jakarta.
 */
class DashboardController extends Controller
{
    public function __construct(private DashboardService $service) {}

    /**
     * Ringkasan pemasukan, pengeluaran, dan total saldo
     */
    public function summary(Request $request): JsonResponse
    {
        $month = (string) $request->query('month', '');

        return response()->json(['data' => ['month' => $month] + $this->service->summary($request->user(), $month)]);
    }

    /**
     * Total per kategori
     */
    public function byCategory(Request $request): JsonResponse
    {
        $month = (string) $request->query('month', '');

        // Validasi month lewat MonthRange (dipanggil service); type divalidasi di sini.
        $type = $request->validate(['type' => ['required', Rule::in(['income', 'expense'])]])['type'];
        $rows = $this->service->byCategory($request->user(), $month, $type);

        return response()->json(['data' => $rows]);
    }
}
