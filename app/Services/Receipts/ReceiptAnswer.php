<?php

namespace App\Services\Receipts;

use App\Support\ReceiptSuggestionSanitizer;
use Carbon\CarbonImmutable;

/** Penguraian jawaban teks model (bersama untuk semua penyedia). */
final class ReceiptAnswer
{
    /**
     * @param  list<array{id: string, name: string, parent: ?string}>  $categories
     *
     * @throws ReceiptScanException
     */
    public static function parse(mixed $text, array $categories): ReceiptSuggestion
    {
        if (! is_string($text) || trim($text) === '') {
            throw new ReceiptScanException('Jawaban penyedia kosong.');
        }

        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
        $decoded = json_decode((string) $clean, true);

        if (! is_array($decoded)) {
            throw new ReceiptScanException('Jawaban penyedia bukan JSON yang valid.');
        }

        return ReceiptSuggestionSanitizer::sanitize(
            $decoded,
            array_column($categories, 'id'),
            CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
        );
    }
}
