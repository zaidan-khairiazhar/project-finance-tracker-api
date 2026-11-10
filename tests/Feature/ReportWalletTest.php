<?php

use App\Models\Transaction;
use App\Models\Wallet;

function walletTx(Wallet $w, string $type, int $amount, string $at): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $w->user_id, 'wallet_id' => $w->id, 'type' => $type, 'amount' => $amount, 'occurred_at' => $at,
    ]);
}

beforeEach(fn () => $this->travelTo('2026-11-15 10:00:00'));

it('sums flows within the inclusive Jakarta date range', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    walletTx($w, 'income', 1000, '2026-11-01T00:00:00+07:00');
    walletTx($w, 'expense', 400, '2026-11-30T23:30:00+07:00');
    walletTx($w, 'expense', 9999, '2026-12-01T00:00:00+07:00'); // di luar rentang
    walletTx($w, 'income', 8888, '2026-10-31T23:59:00+07:00'); // di luar rentang

    $row = $this->getJson('/api/v1/reports/wallets?from=2026-11-01&to=2026-11-30')->assertOk()->json('data.0');

    expect($row)->toMatchArray(['income' => 1000, 'expense' => 400, 'transfer_in' => 0, 'transfer_out' => 0]);
});

it('closing balance counts everything before the end of the to day', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id, 'initial_balance' => 1000]);
    walletTx($w, 'income', 500, '2026-10-10T10:00:00+07:00');   // sebelum rentang
    walletTx($w, 'income', 200, '2026-11-10T10:00:00+07:00');   // dalam rentang
    walletTx($w, 'expense', 9999, '2026-12-01T00:00:00+07:00'); // setelah rentang

    $this->getJson('/api/v1/reports/wallets?from=2026-11-01&to=2026-11-30')
        ->assertJsonPath('data.0.closing_balance', 1700);
});

it('separates transfers from income and expense', function () {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id, 'name' => 'A']);
    $b = Wallet::factory()->create(['user_id' => $u->id, 'name' => 'B']);
    walletTx($a, 'transfer_out', 300, '2026-11-10T10:00:00+07:00');
    walletTx($b, 'transfer_in', 300, '2026-11-10T10:00:00+07:00');

    $rows = collect($this->getJson('/api/v1/reports/wallets')->json('data'))->keyBy('name');

    expect($rows['A'])->toMatchArray(['income' => 0, 'expense' => 0, 'transfer_out' => 300, 'closing_balance' => -300])
        ->and($rows['B'])->toMatchArray(['transfer_in' => 300, 'closing_balance' => 300]);
});

it('shows an archived wallet only with activity in range or a non-zero closing balance', function () {
    $u = actingUser();
    $idle = Wallet::factory()->create(['user_id' => $u->id, 'name' => 'Idle', 'is_archived' => true, 'initial_balance' => 0]);
    $busy = Wallet::factory()->create(['user_id' => $u->id, 'name' => 'Busy', 'is_archived' => true]);
    $rich = Wallet::factory()->create(['user_id' => $u->id, 'name' => 'Rich', 'is_archived' => true, 'initial_balance' => 300]);
    Wallet::factory()->create(['user_id' => $u->id, 'name' => 'Active']);
    walletTx($busy, 'income', 100, '2026-11-05T10:00:00+07:00');
    walletTx($busy, 'expense', 100, '2026-11-06T10:00:00+07:00'); // saldo akhir 0 tetapi ada aktivitas

    $names = array_column($this->getJson('/api/v1/reports/wallets')->json('data'), 'name');

    expect($names)->toBe(['Active', 'Busy', 'Rich']);
});

it('defaults to the current Jakarta month', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    walletTx($w, 'income', 700, '2026-11-02T10:00:00+07:00');
    walletTx($w, 'income', 50, '2026-10-31T23:30:00+07:00');

    $this->getJson('/api/v1/reports/wallets')->assertJsonPath('data.0.income', 700);
});

it('rejects a bad range with 422', function (string $query) {
    actingUser();

    $this->getJson("/api/v1/reports/wallets?{$query}")->assertUnprocessable();
})->with([
    'from after to' => 'from=2026-11-10&to=2026-11-01',
    'longer than 366 days' => 'from=2025-01-01&to=2026-11-30',
    'wrong format' => 'from=10-11-2026',
    'impossible date' => 'from=2026-02-30&to=2026-03-01',
]);

it('accepts a range of exactly 366 days', function () {
    actingUser();

    $this->getJson('/api/v1/reports/wallets?from=2025-11-30&to=2026-11-30')->assertOk();
});

it('ignores deleted transactions, isolates users and requires authentication', function () {
    $this->getJson('/api/v1/reports/wallets')->assertUnauthorized();

    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    walletTx($w, 'income', 900, '2026-11-02T10:00:00+07:00')->delete();
    $other = Wallet::factory()->create();
    walletTx($other, 'income', 400, '2026-11-02T10:00:00+07:00');

    $data = $this->getJson('/api/v1/reports/wallets')->json('data');

    expect($data)->toHaveCount(1)->and($data[0]['income'])->toBe(0);
});
