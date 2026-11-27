<?php

use App\Support\ReceiptSuggestionSanitizer;
use Carbon\CarbonImmutable;

function sanitizeReceipt(mixed $raw): array
{
    return ReceiptSuggestionSanitizer::sanitize($raw, ['c1', 'c2'], CarbonImmutable::parse('2026-11-20'))->toArray();
}

it('keeps a complete valid suggestion', function () {
    expect(sanitizeReceipt(['total' => 45000, 'date' => '2026-11-20', 'merchant' => 'Indomaret', 'category_id' => 'c1']))
        ->toBe(['total' => 45000, 'date' => '2026-11-20', 'merchant' => 'Indomaret', 'category_id' => 'c1']);
});

it('accepts an integral total only as int, integral float or digit string', function (mixed $in, ?int $out) {
    expect(sanitizeReceipt(['total' => $in])['total'])->toBe($out);
})->with([
    'digit string' => ['45000', 45000],
    'integral float' => [45000.0, 45000],
    'int' => [45000, 45000],
    'max safe int' => [9007199254740991, 9007199254740991],
    'thousand separator' => ['45.000', null],
    'negative' => [-5, null],
    'zero' => [0, null],
    'above max' => [9007199254740992, null],
    'letters' => ['abc', null],
    'null' => [null, null],
    'fractional float' => [45000.5, null],
    'bool' => [true, null],
]);

it('validates the date', function (mixed $in, ?string $out) {
    expect(sanitizeReceipt(['date' => $in])['date'])->toBe($out);
})->with([
    'invalid month/day' => ['2026-13-40', null],
    'words' => ['kemarin', null],
    'two days ahead' => ['2026-11-22', null],
    'one day ahead' => ['2026-11-21', '2026-11-21'],
    'impossibly old' => ['0001-01-01', null],
    'past' => ['2025-01-02', '2025-01-02'],
    'non-string' => [20261120, null],
]);

it('accepts only known category ids as strings', function (mixed $in, ?string $out) {
    expect(sanitizeReceipt(['category_id' => $in])['category_id'])->toBe($out);
})->with([
    'known' => ['c2', 'c2'],
    'invented' => ['zzz', null],
    'number' => [1, null],
]);

it('trims and truncates the merchant', function () {
    expect(sanitizeReceipt(['merchant' => '  Toko  '])['merchant'])->toBe('Toko');
    expect(sanitizeReceipt(['merchant' => '   '])['merchant'])->toBeNull();
    expect(sanitizeReceipt(['merchant' => str_repeat('é', 150)])['merchant'])->toBe(str_repeat('é', 100));
    expect(sanitizeReceipt(['merchant' => 5])['merchant'])->toBeNull();
});

it('returns all null when raw is not an array', function (mixed $raw) {
    expect(sanitizeReceipt($raw))->toBe(['total' => null, 'date' => null, 'merchant' => null, 'category_id' => null]);
})->with(['string' => ['x'], 'null' => [null], 'int' => [3]]);
