<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertBudgetRequest;
use App\Models\Budget;
use App\Services\BudgetService;
use App\Support\MonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * @group Budgets
 *
 * Anggaran bulanan tetap per kategori pengeluaran. Berlaku setiap bulan sampai diubah;
 * anggaran kategori induk menjumlah subkategorinya.
 */
class BudgetController extends Controller
{
    public function __construct(private BudgetService $service) {}

    /**
     * Daftar anggaran beserta pemakaiannya
     *
     * @queryParam month string Bulan `YYYY-MM` (Asia/Jakarta). Default bulan berjalan. Example: 2026-11
     */
    public function index(Request $request): JsonResponse
    {
        $month = (string) $request->query('month', CarbonImmutable::now(MonthRange::TIMEZONE)->format('Y-m'));

        return response()->json(['data' => $this->service->list($request->user(), $month)]);
    }

    /**
     * Atur anggaran kategori (buat atau ubah)
     *
     * Mengembalikan 201 jika anggaran baru, 200 jika memperbarui anggaran yang ada.
     */
    public function upsert(UpsertBudgetRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$budget, $created] = $this->service->upsert($request->user(), $data['category_id'], (int) $data['amount']);

        return response()->json(
            ['data' => ['id' => $budget->id, 'category_id' => $budget->category_id, 'amount' => $budget->amount]],
            $created ? 201 : 200
        );
    }

    /**
     * Hapus anggaran
     */
    public function destroy(Budget $budget): Response
    {
        Gate::authorize('delete', $budget);

        $budget->delete();

        return response()->noContent();
    }
}
