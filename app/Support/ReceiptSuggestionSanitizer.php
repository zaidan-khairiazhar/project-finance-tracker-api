<?php

namespace App\Support;

use App\Services\Receipts\ReceiptSuggestion;
use Carbon\CarbonImmutable;

final class ReceiptSuggestionSanitizer
{
    private const MAX_TOTAL = 9007199254740991;

    private const MERCHANT_MAX = 100;

    /**
     * Hasil model tidak dipercaya: setiap field yang tidak valid menjadi null.
     *
     * @param  list<string>  $allowedCategoryIds
     */
    public static function sanitize(mixed $raw, array $allowedCategoryIds, CarbonImmutable $today): ReceiptSuggestion
    {
        if (! is_array($raw)) {
            return new ReceiptSuggestion;
        }

        return new ReceiptSuggestion(
            total: self::total($raw['total'] ?? null),
            date: self::date($raw['date'] ?? null, $today),
            merchant: self::merchant($raw['merchant'] ?? null),
            categoryId: self::category($raw['category_id'] ?? null, $allowedCategoryIds),
        );
    }

    private static function total(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d{1,16}$/', $value)) {
            $value = (int) $value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value && $value >= 1 && $value <= self::MAX_TOTAL) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 && $value <= self::MAX_TOTAL ? $value : null;
    }

    private static function date(mixed $value, CarbonImmutable $today): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        // Bandingkan sebagai tanggal kalender (bukan instan) agar zona waktu tidak menggeser batasnya.
        // Tanggal mustahil (sebelum 2000) dan tanggal lebih dari 1 hari ke depan dibuang.
        return ($value < '2000-01-01' || $value > $today->addDay()->format('Y-m-d')) ? null : $value;
    }

    private static function merchant(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::MERCHANT_MAX);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function category(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
