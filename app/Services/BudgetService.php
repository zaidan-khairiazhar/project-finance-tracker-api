<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\User;
use App\Support\MonthRange;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /**
     * Anggaran user beserta pemakaian pada bulan `YYYY-MM` (Asia/Jakarta).
     * Anggaran kategori induk menjumlah pengeluaran induk dan semua subkategorinya;
     * anggaran subkategori hanya menghitung transaksinya sendiri.
     * Transfer, pemasukan, dan transaksi ter-soft-delete tidak dihitung.
     *
     * @return list<array{id: string, category_id: string, category_name: string, amount: int, spent: int, remaining: int, percent: int, status: string}>
     */
    public function list(User $user, string $month): array
    {
        [$start, $end] = MonthRange::parse($month);

        $rows = DB::select(
            "SELECT b.id, b.category_id, c.name AS category_name, b.amount,
                COALESCE((
                    SELECT SUM(t.amount)
                    FROM transactions t
                    WHERE t.user_id = b.user_id
                      AND t.type = 'expense'
                      AND t.deleted_at IS NULL
                      AND t.occurred_at >= ?
                      AND t.occurred_at < ?
                      AND t.category_id IN (
                          SELECT id FROM categories WHERE id = b.category_id OR parent_id = b.category_id
                      )
                ), 0)::bigint AS spent
             FROM budgets b
             JOIN categories c ON c.id = b.category_id AND c.deleted_at IS NULL
             WHERE b.user_id = ?",
            [$start->format('Y-m-d H:i:sP'), $end->format('Y-m-d H:i:sP'), $user->id]
        );

        $items = array_map(function ($r) {
            $amount = (int) $r->amount;
            $spent = (int) $r->spent;
            // Pembagian bulat tanpa float dan tanpa meluap: spent * 100 bisa melewati PHP_INT_MAX.
            $percent = intdiv($spent, $amount) * 100 + intdiv(($spent % $amount) * 100, $amount);

            return [
                'id' => $r->id,
                'category_id' => $r->category_id,
                'category_name' => $r->category_name,
                'amount' => $amount,
                'spent' => $spent,
                'remaining' => $amount - $spent,
                'percent' => $percent,
                'status' => $spent > $amount ? 'over' : ($percent >= 80 ? 'warning' : 'ok'),
            ];
        }, $rows);

        usort($items, fn ($a, $b) => [$b['percent'], $a['category_name']] <=> [$a['percent'], $b['category_name']]);

        return $items;
    }

    /** @return array{0: Budget, 1: bool} anggaran dan apakah baru dibuat */
    public function upsert(User $user, string $categoryId, int $amount): array
    {
        return DB::transaction(function () use ($user, $categoryId, $amount) {
            $budget = Budget::query()->where('category_id', $categoryId)->first();

            if ($budget) {
                $budget->update(['amount' => $amount]);

                return [$budget, false];
            }

            $budget = new Budget(['category_id' => $categoryId, 'amount' => $amount]);
            $budget->user_id = $user->id;
            $budget->save();

            return [$budget, true];
        });
    }
}
