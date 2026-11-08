<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\MonthRange;

class DashboardService
{
    /** @return array{income:int, expense:int, net:int, total_balance:int} */
    public function summary(User $user, string $month): array
    {
        [$start, $end] = MonthRange::parse($month);

        $row = $this->monthQuery($user, $start, $end)
            ->selectRaw("COALESCE(SUM(transactions.amount) FILTER (WHERE transactions.type = 'income'), 0) AS income")
            ->selectRaw("COALESCE(SUM(transactions.amount) FILTER (WHERE transactions.type = 'expense'), 0) AS expense")
            ->first();

        $income = (int) $row->income;
        $expense = (int) $row->expense;

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            // Semua dompet yang belum dihapus, termasuk yang diarsipkan.
            'total_balance' => (int) Wallet::query()->withBalance()->get()->sum('balance'),
        ];
    }

    /** @return list<array{category_id: ?string, name: string, color: ?string, total: int}> */
    public function byCategory(User $user, string $month, string $type): array
    {
        [$start, $end] = MonthRange::parse($month);

        return $this->monthQuery($user, $start, $end)
            ->where('transactions.type', $type)
            // leftJoin tidak menerapkan soft delete, jadi nama kategori lama tetap tampil di riwayat.
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->groupBy('transactions.category_id', 'categories.name', 'categories.color')
            ->selectRaw('transactions.category_id, categories.name, categories.color, SUM(transactions.amount) AS total')
            ->orderByRaw('SUM(transactions.amount) DESC')
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($r) => [
                'category_id' => $r->category_id,
                'name' => $r->name ?? 'Tanpa kategori',
                'color' => $r->color,
                'total' => (int) $r->total,
            ])
            ->values()
            ->all();
    }

    // Hanya income dan expense; transfer tidak dihitung. Soft delete diterapkan oleh model.
    private function monthQuery(User $user, $start, $end)
    {
        return Transaction::query()
            ->where('transactions.user_id', $user->id)
            ->whereIn('transactions.type', ['income', 'expense'])
            ->where('transactions.occurred_at', '>=', $start)
            ->where('transactions.occurred_at', '<', $end);
    }
}
