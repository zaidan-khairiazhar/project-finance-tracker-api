<?php

namespace App\Support;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/**
 * Perhitungan tanggal kemunculan aturan berulang. Semua argumen dan hasil adalah tanggal kalender
 * (jam 00:00); zona waktu ditangani pemanggil. Bulanan selalu dihitung dari `$start`, bukan dari
 * kemunculan sebelumnya, supaya tanggal 31 tidak "melorot" ke 28 setelah melewati Februari.
 */
class RecurrenceSchedule
{
    /** Kemunculan tepat setelah `$current` (yang merupakan kemunculan valid). */
    public static function nextAfter(RecurrenceFrequency $frequency, CarbonImmutable $start, CarbonImmutable $current): CarbonImmutable
    {
        return match ($frequency) {
            RecurrenceFrequency::Daily => $current->addDay(),
            RecurrenceFrequency::Weekly => $current->addWeek(),
            RecurrenceFrequency::Monthly => $start->addMonthsNoOverflow(self::monthIndex($start, $current) + 1),
        };
    }

    /** Kemunculan pertama yang jatuh pada atau setelah `$date`; `$start` bila `$date` tidak setelah `$start`. */
    public static function firstOnOrAfter(RecurrenceFrequency $frequency, CarbonImmutable $start, CarbonImmutable $date): CarbonImmutable
    {
        if ($date->lte($start)) {
            return $start;
        }

        return match ($frequency) {
            RecurrenceFrequency::Daily => $date,
            RecurrenceFrequency::Weekly => $start->addDays((int) (ceil($start->diffInDays($date) / 7) * 7)),
            RecurrenceFrequency::Monthly => self::firstMonthlyOnOrAfter($start, $date),
        };
    }

    private static function firstMonthlyOnOrAfter(CarbonImmutable $start, CarbonImmutable $date): CarbonImmutable
    {
        $index = self::monthIndex($start, $date);
        $candidate = $start->addMonthsNoOverflow($index);

        return $candidate->lt($date) ? $start->addMonthsNoOverflow($index + 1) : $candidate;
    }

    /** Selisih bulan kalender antara `$start` dan `$date`. */
    private static function monthIndex(CarbonImmutable $start, CarbonImmutable $date): int
    {
        return ($date->year - $start->year) * 12 + ($date->month - $start->month);
    }
}
