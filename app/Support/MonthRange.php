<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class MonthRange
{
    public const TIMEZONE = 'Asia/Jakarta';

    /**
     * Rentang [awal, akhir) dalam UTC untuk bulan kalender `YYYY-MM` di zona Asia/Jakarta.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function parse(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Format bulan harus YYYY-MM.']);
        }

        $start = CarbonImmutable::parse("{$month}-01", self::TIMEZONE)->startOfDay();

        return [$start->utc(), $start->addMonthNoOverflow()->utc()];
    }
}
