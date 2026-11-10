<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;

function spend(string $userId, string $walletId, ?string $categoryId, int $amount, string $at, string $type = 'expense'): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $userId, 'wallet_id' => $walletId, 'category_id' => $categoryId,
        'type' => $type, 'amount' => $amount, 'occurred_at' => $at,
    ]);
}

it('buckets spend by Asia/Jakarta month', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Budget::factory()->create(['user_id' => $u->id, 'amount' => 100000]);
    spend($u->id, $w->id, $b->category_id, 1000, '2026-11-30T23:30:00+07:00');
    spend($u->id, $w->id, $b->category_id, 5000, '2026-12-01T00:00:00+07:00');

    $this->getJson('/api/v1/budgets?month=2026-11')->assertOk()->assertJsonPath('data.0.spent', 1000);
    $this->getJson('/api/v1/budgets?month=2026-12')->assertOk()->assertJsonPath('data.0.spent', 5000);
});

it('sums children into a parent budget but not the parent into a child budget', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $mk = fn (?string $parent, string $name) => Category::factory()->create([
        'user_id' => $u->id, 'type' => 'expense', 'parent_id' => $parent, 'name' => $name,
    ]);
    $a = $mk(null, 'A');
    $a1 = $mk($a->id, 'A1');
    $a2 = $mk($a->id, 'A2');
    Budget::factory()->create(['user_id' => $u->id, 'category_id' => $a->id, 'amount' => 1000000]);
    Budget::factory()->create(['user_id' => $u->id, 'category_id' => $a1->id, 'amount' => 500000]);
    $at = '2026-11-10T10:00:00+07:00';
    spend($u->id, $w->id, $a->id, 1000, $at);
    spend($u->id, $w->id, $a1->id, 2000, $at);
    spend($u->id, $w->id, $a2->id, 4000, $at);

    $rows = collect($this->getJson('/api/v1/budgets?month=2026-11')->assertOk()->json('data'))->keyBy('category_name');

    expect($rows['A']['spent'])->toBe(7000)
        ->and($rows['A1']['spent'])->toBe(2000);
});

it('ignores transfers, income, uncategorised and deleted transactions', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Budget::factory()->create(['user_id' => $u->id, 'amount' => 100000]);
    $at = '2026-11-10T10:00:00+07:00';
    spend($u->id, $w->id, $b->category_id, 3000, $at);
    spend($u->id, $w->id, $b->category_id, 100, $at, 'transfer_out');
    spend($u->id, $w->id, $b->category_id, 200, $at, 'income');
    spend($u->id, $w->id, null, 400, $at);
    spend($u->id, $w->id, $b->category_id, 800, $at)->delete();

    $this->getJson('/api/v1/budgets?month=2026-11')->assertJsonPath('data.0.spent', 3000);
});

it('computes percent, remaining and status', function (int $spent, int $percent, string $status, int $remaining) {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Budget::factory()->create(['user_id' => $u->id, 'amount' => 1000]);
    spend($u->id, $w->id, $b->category_id, $spent, '2026-11-10T10:00:00+07:00');

    $this->getJson('/api/v1/budgets?month=2026-11')
        ->assertJsonPath('data.0.percent', $percent)
        ->assertJsonPath('data.0.status', $status)
        ->assertJsonPath('data.0.remaining', $remaining);
})->with([
    'under 80%' => [799, 79, 'ok', 201],
    'exactly 80%' => [800, 80, 'warning', 200],
    'exactly 100%' => [1000, 100, 'warning', 0],
    'a rupiah over' => [1001, 100, 'over', -1],
]);

it('orders budgets by percent descending', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $low = Budget::factory()->create(['user_id' => $u->id, 'amount' => 1000]);
    $high = Budget::factory()->create(['user_id' => $u->id, 'amount' => 1000]);
    spend($u->id, $w->id, $low->category_id, 100, '2026-11-10T10:00:00+07:00');
    spend($u->id, $w->id, $high->category_id, 900, '2026-11-10T10:00:00+07:00');

    expect($this->getJson('/api/v1/budgets?month=2026-11')->json('data.*.category_id'))
        ->toBe([$high->category_id, $low->category_id]);
});

it('creates then updates a budget with upsert', function () {
    $u = actingUser();
    $c = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);

    $this->putJson('/api/v1/budgets', ['category_id' => $c->id, 'amount' => 100000])
        ->assertCreated()->assertJsonPath('data.amount', 100000);
    $this->putJson('/api/v1/budgets', ['category_id' => $c->id, 'amount' => 250000])
        ->assertOk()->assertJsonPath('data.amount', 250000);

    expect(Budget::count())->toBe(1);
});

it('rejects an invalid category with 422', function (string $case) {
    $u = actingUser();
    $categoryId = match ($case) {
        'another user' => Category::factory()->create(['type' => 'expense'])->id,
        'income' => Category::factory()->create(['user_id' => $u->id, 'type' => 'income'])->id,
        'deleted' => tap(Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']), fn ($c) => $c->delete())->id,
        'malformed' => 'abc',
        'missing' => null,
    };

    $this->putJson('/api/v1/budgets', ['category_id' => $categoryId, 'amount' => 1000])
        ->assertUnprocessable()->assertJsonValidationErrors('category_id');
})->with(['another user', 'income', 'deleted', 'malformed', 'missing']);

it('rejects a bad amount with 422', function (mixed $amount) {
    $u = actingUser();
    $c = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);

    $this->putJson('/api/v1/budgets', ['category_id' => $c->id, 'amount' => $amount])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
})->with([0, -5, 12.5, 'abc', 9007199254740992, null]);

it('deletes own budget and hides other users budgets', function () {
    $u = actingUser();
    $mine = Budget::factory()->create(['user_id' => $u->id]);
    $theirs = Budget::factory()->create();

    $this->getJson('/api/v1/budgets?month=2026-11')->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/budgets/{$theirs->id}")->assertNotFound();
    $this->deleteJson("/api/v1/budgets/{$mine->id}")->assertNoContent();

    expect(Budget::count())->toBe(0);
});

it('removes the budget when its category is deleted', function () {
    $u = actingUser();
    $b = Budget::factory()->create(['user_id' => $u->id]);

    $this->deleteJson("/api/v1/categories/{$b->category_id}")->assertNoContent();

    expect(Budget::withoutGlobalScopes()->count())->toBe(0);
    $this->getJson('/api/v1/budgets?month=2026-11')->assertJsonCount(0, 'data');
});

it('rejects a malformed month and requires authentication', function () {
    $this->getJson('/api/v1/budgets')->assertUnauthorized();

    actingUser();
    foreach (['2026-13', 'abc', '2026-1'] as $month) {
        $this->getJson("/api/v1/budgets?month={$month}")->assertUnprocessable()->assertJsonValidationErrors('month');
    }
});
