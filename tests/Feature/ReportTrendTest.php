<?php

use App\Models\Transaction;
use App\Models\Wallet;

function trendTx(string $userId, string $type, int $amount, string $at): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $userId,
        'wallet_id' => Wallet::factory()->create(['user_id' => $userId])->id,
        'type' => $type, 'amount' => $amount, 'occurred_at' => $at,
    ]);
}

beforeEach(fn () => $this->travelTo('2026-11-15 10:00:00'));

it('returns exactly N months ending this month, zero-filled', function () {
    actingUser();

    $data = $this->getJson('/api/v1/reports/monthly-trend?months=6')->assertOk()->json('data');

    expect($data)->toHaveCount(6)
        ->and(array_column($data, 'month'))->toBe(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'])
        ->and(array_sum(array_column($data, 'income')) + array_sum(array_column($data, 'expense')))->toBe(0);
});

it('defaults to 6 months and supports 12 across the year boundary', function () {
    actingUser();

    $this->getJson('/api/v1/reports/monthly-trend')->assertJsonCount(6, 'data');
    $twelve = $this->getJson('/api/v1/reports/monthly-trend?months=12')->assertJsonCount(12, 'data')->json('data');

    expect($twelve[0]['month'])->toBe('2025-12')->and($twelve[11]['month'])->toBe('2026-11');
});

it('groups by Asia/Jakarta month and computes net', function () {
    $u = actingUser();
    trendTx($u->id, 'expense', 1000, '2026-10-31T23:30:00+07:00');
    trendTx($u->id, 'expense', 5000, '2026-11-01T00:00:00+07:00');
    trendTx($u->id, 'income', 20000, '2026-11-02T10:00:00+07:00');

    $by = collect($this->getJson('/api/v1/reports/monthly-trend?months=6')->json('data'))->keyBy('month');

    expect($by['2026-10']['expense'])->toBe(1000)
        ->and($by['2026-11']['expense'])->toBe(5000)
        ->and($by['2026-11']['income'])->toBe(20000)
        ->and($by['2026-11']['net'])->toBe(15000);
});

it('excludes transfers, deleted rows and months outside the window', function () {
    $u = actingUser();
    trendTx($u->id, 'transfer_out', 700, '2026-11-02T10:00:00+07:00');
    trendTx($u->id, 'transfer_in', 700, '2026-11-02T10:00:00+07:00');
    trendTx($u->id, 'expense', 900, '2026-11-02T10:00:00+07:00')->delete();
    trendTx($u->id, 'expense', 800, '2026-05-31T23:30:00+07:00'); // sebelum jendela 6 bulan

    $data = $this->getJson('/api/v1/reports/monthly-trend?months=6')->json('data');

    expect(array_sum(array_column($data, 'expense')))->toBe(0)
        ->and(array_sum(array_column($data, 'income')))->toBe(0);
});

it('rejects an invalid months value', function (string $months) {
    actingUser();

    $this->getJson("/api/v1/reports/monthly-trend?months={$months}")
        ->assertUnprocessable()->assertJsonValidationErrors('months');
})->with(['5', '13', 'abc', '0']);

it("isolates users and requires authentication", function () {
    $this->getJson('/api/v1/reports/monthly-trend')->assertUnauthorized();

    $u = actingUser();
    $other = App\Models\User::factory()->create();
    trendTx($other->id, 'expense', 4000, '2026-11-02T10:00:00+07:00');

    $data = $this->getJson('/api/v1/reports/monthly-trend')->json('data');
    expect(array_sum(array_column($data, 'expense')))->toBe(0);
});
