<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Progres target tabungan dari saldo dompet. Murni: `$deadline` dan `$today` adalah tanggal kalender
 * (jam 00:00), zona waktu ditangani pemanggil. Semua uang bilangan bulat; tidak ada float.
 */
class GoalProgress
{
    public const IN_PROGRESS = 'in_progress';

    public const REACHED = 'reached';

    public const OVERDUE = 'overdue';

    /**
     * @return array{remaining:int, percent:int, status:string, monthly_needed:?int}
     */
    public static function calculate(int $current, int $target, ?CarbonImmutable $deadline, CarbonImmutable $today): array
    {
        $reached = $current >= $target;
        $remaining = $reached ? 0 : $target - $current;
        $status = match (true) {
            $reached => self::REACHED,
            $deadline !== null && $deadline->lt($today) => self::OVERDUE,
            default => self::IN_PROGRESS,
        };

        $monthlyNeeded = null;
        if ($status === self::IN_PROGRESS && $deadline !== null) {
            $months = max(1, ($deadline->year - $today->year) * 12 + $deadline->month - $today->month);
            $monthlyNeeded = intdiv($remaining + $months - 1, $months);
        }

        return [
            'remaining' => $remaining,
            'percent' => self::percent($current, $target),
            'status' => $status,
            'monthly_needed' => $monthlyNeeded,
        ];
    }

    /** Persen bulat ke bawah, dijepit 0..100. `$current` < `$target` <= 2^53 sehingga `* 100` muat di int64. */
    private static function percent(int $current, int $target): int
    {
        if ($current <= 0) {
            return 0;
        }
        if ($current >= $target) {
            return 100;
        }

        return intdiv($current * 100, $target);
    }
}
