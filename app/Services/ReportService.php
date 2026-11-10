<?php

namespace App\Services;

use App\Models\User;
use App\Support\MonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Pemasukan, pengeluaran, dan selisih per bulan Jakarta untuk `$months` bulan terakhir
     * (berakhir di bulan berjalan). Bulan tanpa transaksi bernilai 0. Transfer tidak dihitung.
     *
     * @return list<array{month: string, income: int, expense: int, net: int}>
     */
    public function monthlyTrend(User $user, int $months): array
    {
        $starts = $this->monthStarts($months);

        $rows = DB::table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', ['income', 'expense'])
            ->whereNull('deleted_at')
            ->where('occurred_at', '>=', $this->bound($starts[0]))
            ->where('occurred_at', '<', $this->bound($starts[$months - 1]->addMonthNoOverflow()))
            ->groupByRaw($this->monthExpr())
            ->selectRaw($this->monthExpr().' AS month')
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE type = 'income'), 0) AS income")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE type = 'expense'), 0) AS expense")
            ->get()
            ->keyBy('month');

        return array_map(function (CarbonImmutable $start) use ($rows) {
            $key = $start->format('Y-m');
            $income = (int) ($rows[$key]->income ?? 0);
            $expense = (int) ($rows[$key]->expense ?? 0);

            return ['month' => $key, 'income' => $income, 'expense' => $expense, 'net' => $income - $expense];
        }, $starts);
    }

    /**
     * Total per kategori per bulan. Kategori dikelompokkan sesuai kategori transaksinya sendiri
     * (anak tidak digulung ke induk); transaksi tanpa kategori menjadi satu baris "Tanpa kategori".
     *
     * @return array{months: list<string>, rows: list<array{category_id: ?string, name: string, totals: list<int>, total: int}>}
     */
    public function categoryComparison(User $user, int $months, string $type): array
    {
        $starts = $this->monthStarts($months);
        $keys = array_map(fn (CarbonImmutable $s) => $s->format('Y-m'), $starts);

        $data = DB::table('transactions')
            // leftJoin tidak menerapkan soft delete, jadi nama kategori lama tetap tampil di riwayat.
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $user->id)
            ->where('transactions.type', $type)
            ->whereNull('transactions.deleted_at')
            ->where('transactions.occurred_at', '>=', $this->bound($starts[0]))
            ->where('transactions.occurred_at', '<', $this->bound($starts[$months - 1]->addMonthNoOverflow()))
            ->groupBy('transactions.category_id', 'categories.name')
            ->groupByRaw($this->monthExpr('transactions.occurred_at'))
            ->selectRaw('transactions.category_id, categories.name')
            ->selectRaw($this->monthExpr('transactions.occurred_at').' AS month')
            ->selectRaw('SUM(transactions.amount) AS total')
            ->get();

        $rows = [];
        foreach ($data as $r) {
            $id = $r->category_id ?? '';
            $rows[$id] ??= [
                'category_id' => $r->category_id,
                'name' => $r->name ?? 'Tanpa kategori',
                'totals' => array_fill(0, $months, 0),
                'total' => 0,
            ];
            $i = array_search($r->month, $keys, true);
            $rows[$id]['totals'][$i] += (int) $r->total;
            $rows[$id]['total'] += (int) $r->total;
        }

        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return ['months' => $keys, 'rows' => $rows];
    }

    /**
     * Arus per dompet pada rentang tanggal Jakarta [from, to] (inklusif) dan saldo akhir pada akhir hari `to`.
     * Dompet aktif selalu tampil; dompet arsip hanya jika ada transaksi pada rentang atau saldo akhirnya tidak nol.
     *
     * @return list<array{wallet_id: string, name: string, is_archived: bool, income: int, expense: int, transfer_in: int, transfer_out: int, closing_balance: int}>
     */
    public function wallets(User $user, string $from, string $to): array
    {
        $start = $this->bound(CarbonImmutable::parse($from, MonthRange::TIMEZONE)->startOfDay());
        $end = $this->bound(CarbonImmutable::parse($to, MonthRange::TIMEZONE)->addDay()->startOfDay());
        $inRange = 't.occurred_at >= ? AND t.occurred_at < ?';
        $flow = fn (string $type) => "COALESCE(SUM(t.amount) FILTER (WHERE t.type = '{$type}' AND {$inRange}), 0)";

        $rows = DB::select(
            "SELECT w.id, w.name, w.is_archived,
                {$flow('income')} AS income,
                {$flow('expense')} AS expense,
                {$flow('transfer_in')} AS transfer_in,
                {$flow('transfer_out')} AS transfer_out,
                COUNT(t.id) FILTER (WHERE {$inRange}) AS activity,
                (w.initial_balance + COALESCE(SUM(
                    CASE WHEN t.type IN ('income', 'transfer_in') THEN t.amount ELSE -t.amount END
                ) FILTER (WHERE t.occurred_at < ?), 0))::bigint AS closing_balance
             FROM wallets w
             LEFT JOIN transactions t ON t.wallet_id = w.id AND t.deleted_at IS NULL
             WHERE w.user_id = ? AND w.deleted_at IS NULL
             GROUP BY w.id, w.name, w.is_archived, w.initial_balance
             ORDER BY w.name, w.id",
            [$start, $end, $start, $end, $start, $end, $start, $end, $start, $end, $end, $user->id]
        );

        $report = [];
        foreach ($rows as $r) {
            if ($r->is_archived && (int) $r->activity === 0 && (int) $r->closing_balance === 0) {
                continue;
            }
            $report[] = [
                'wallet_id' => $r->id,
                'name' => $r->name,
                'is_archived' => (bool) $r->is_archived,
                'income' => (int) $r->income,
                'expense' => (int) $r->expense,
                'transfer_in' => (int) $r->transfer_in,
                'transfer_out' => (int) $r->transfer_out,
                'closing_balance' => (int) $r->closing_balance,
            ];
        }

        return $report;
    }

    /** Awal tiap bulan Jakarta, dari `$months - 1` bulan lalu sampai bulan berjalan. @return list<CarbonImmutable> */
    private function monthStarts(int $months): array
    {
        $current = now(MonthRange::TIMEZONE)->toImmutable()->startOfMonth();

        return array_map(
            fn (int $i) => $current->subMonthsNoOverflow($months - 1 - $i),
            range(0, $months - 1)
        );
    }

    private function bound(CarbonImmutable $localStart): string
    {
        return $localStart->utc()->format('Y-m-d H:i:sP');
    }

    /** Kolom `occurred_at` bertipe timestamptz (UTC); dikonversi ke jam dinding Jakarta lalu dipotong ke bulan. */
    private function monthExpr(string $column = 'occurred_at'): string
    {
        return "to_char({$column} AT TIME ZONE 'Asia/Jakarta', 'YYYY-MM')";
    }
}
