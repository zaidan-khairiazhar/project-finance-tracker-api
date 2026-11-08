<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;

it('buckets by Asia/Jakarta month boundaries', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $mk = fn ($amt, $at) => Transaction::factory()->create([
        'user_id' => $u->id, 'wallet_id' => $w->id, 'type' => 'expense', 'amount' => $amt, 'occurred_at' => $at,
    ]);
    $mk(1000, '2026-11-30T23:30:00+07:00');
    $mk(5000, '2026-12-01T00:00:00+07:00');

    $this->getJson('/api/v1/dashboard/summary?month=2026-11')->assertJsonPath('data.expense', 1000);
    $this->getJson('/api/v1/dashboard/summary?month=2026-12')->assertJsonPath('data.expense', 5000);
});

it('excludes transfers and computes net and total balance', function () {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id, 'initial_balance' => 1000]);
    $b = Wallet::factory()->create(['user_id' => $u->id, 'initial_balance' => 0, 'is_archived' => true]);
    $at = '2026-11-10T10:00:00+07:00';
    Transaction::factory()->create(['user_id' => $u->id, 'wallet_id' => $a->id, 'type' => 'income', 'amount' => 5000, 'occurred_at' => $at]);
    Transaction::factory()->create(['user_id' => $u->id, 'wallet_id' => $a->id, 'type' => 'expense', 'amount' => 2000, 'occurred_at' => $at]);
    foreach ([[$a, 'transfer_out'], [$b, 'transfer_in']] as [$wallet, $type]) {
        Transaction::factory()->create([
            'user_id' => $u->id, 'wallet_id' => $wallet->id, 'type' => $type,
            'category_id' => null, 'amount' => 500, 'occurred_at' => $at,
        ]);
    }

    $this->getJson('/api/v1/dashboard/summary?month=2026-11')
        ->assertJsonPath('data.month', '2026-11')
        ->assertJsonPath('data.income', 5000)
        ->assertJsonPath('data.expense', 2000)
        ->assertJsonPath('data.net', 3000)
        ->assertJsonPath('data.total_balance', 4000);
});

it('returns zeros for an empty month and 422 for bad months', function () {
    actingUser();

    $this->getJson('/api/v1/dashboard/summary?month=2026-01')
        ->assertOk()->assertJsonPath('data.income', 0)->assertJsonPath('data.total_balance', 0);
    $this->getJson('/api/v1/dashboard/by-category?month=2026-01&type=expense')
        ->assertOk()->assertExactJson(['data' => []]);

    foreach (['2026-13', '2026-1', 'abc', ''] as $m) {
        $this->getJson("/api/v1/dashboard/summary?month=$m")->assertUnprocessable();
    }
    $this->getJson('/api/v1/dashboard/summary')->assertUnprocessable();
});

it('groups by category, largest first, with an uncategorised bucket', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $c = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'name' => 'Makanan & Minuman']);
    $mk = fn ($cat, $amt) => Transaction::factory()->create([
        'user_id' => $u->id, 'wallet_id' => $w->id, 'category_id' => $cat, 'type' => 'expense',
        'amount' => $amt, 'occurred_at' => '2026-11-05T10:00:00+07:00',
    ]);
    $mk($c->id, 3000);
    $mk($c->id, 2000);
    $mk(null, 9000);

    $r = $this->getJson('/api/v1/dashboard/by-category?month=2026-11&type=expense')->assertOk();

    expect($r->json('data.0'))->toMatchArray(['category_id' => null, 'name' => 'Tanpa kategori', 'total' => 9000]);
    expect($r->json('data.1.total'))->toBe(5000);
    expect($r->json('data.1.name'))->toBe('Makanan & Minuman');
});

it("ignores deleted transactions and other users' data", function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $at = '2026-11-10T10:00:00+07:00';
    Transaction::factory()->create(['user_id' => $u->id, 'wallet_id' => $w->id, 'type' => 'expense', 'amount' => 1000, 'occurred_at' => $at]);
    Transaction::factory()->create(['user_id' => $u->id, 'wallet_id' => $w->id, 'type' => 'expense', 'amount' => 7000, 'occurred_at' => $at])->delete();
    Transaction::factory()->create(['type' => 'expense', 'amount' => 99999, 'occurred_at' => $at]); // user lain

    $this->getJson('/api/v1/dashboard/summary?month=2026-11')->assertJsonPath('data.expense', 1000);
    $this->getJson('/api/v1/dashboard/by-category?month=2026-11&type=expense')
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.total', 1000);
});

it('requires a valid type for by-category', function () {
    actingUser();

    $this->getJson('/api/v1/dashboard/by-category?month=2026-11')
        ->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->getJson('/api/v1/dashboard/by-category?month=2026-11&type=transfer_out')
        ->assertUnprocessable()->assertJsonValidationErrors('type');
});
