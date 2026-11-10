<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;

function catTx(string $userId, ?string $categoryId, int $amount, string $at, string $type = 'expense'): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $userId,
        'wallet_id' => Wallet::factory()->create(['user_id' => $userId])->id,
        'category_id' => $categoryId, 'type' => $type, 'amount' => $amount, 'occurred_at' => $at,
    ]);
}

beforeEach(fn () => $this->travelTo('2026-11-15 10:00:00'));

it('lists categories by total descending with per-month totals aligned to months', function () {
    $u = actingUser();
    $food = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'name' => 'Makanan']);
    $fun = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'name' => 'Hiburan']);
    catTx($u->id, $food->id, 3000, '2026-10-10T10:00:00+07:00');
    catTx($u->id, $food->id, 4000, '2026-11-10T10:00:00+07:00');
    catTx($u->id, $fun->id, 1000, '2026-11-11T10:00:00+07:00');

    $data = $this->getJson('/api/v1/reports/category-comparison?months=3')->assertOk()->json('data');

    expect($data['months'])->toBe(['2026-09', '2026-10', '2026-11'])
        ->and($data['rows'][0])->toMatchArray(['name' => 'Makanan', 'totals' => [0, 3000, 4000], 'total' => 7000])
        ->and($data['rows'][1])->toMatchArray(['name' => 'Hiburan', 'totals' => [0, 0, 1000], 'total' => 1000]);
});

it('puts uncategorised spending in its own row', function () {
    $u = actingUser();
    catTx($u->id, null, 2500, '2026-11-10T10:00:00+07:00');

    $row = $this->getJson('/api/v1/reports/category-comparison')->json('data.rows.0');

    expect($row['category_id'])->toBeNull()->and($row['name'])->toBe('Tanpa kategori')->and($row['total'])->toBe(2500);
});

it('does not roll children up into the parent', function () {
    $u = actingUser();
    $parent = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'name' => 'Induk']);
    $child = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense', 'name' => 'Anak', 'parent_id' => $parent->id]);
    catTx($u->id, $child->id, 1500, '2026-11-10T10:00:00+07:00');

    $rows = collect($this->getJson('/api/v1/reports/category-comparison')->json('data.rows'))->keyBy('name');

    expect($rows)->toHaveCount(1)->and($rows['Anak']['total'])->toBe(1500);
});

it('buckets a 23:30 WIB transaction on the last day into that month and supports income', function () {
    $u = actingUser();
    $salary = Category::factory()->create(['user_id' => $u->id, 'type' => 'income', 'name' => 'Gaji']);
    catTx($u->id, $salary->id, 9000, '2026-10-31T23:30:00+07:00', 'income');
    catTx($u->id, $salary->id, 1000, '2026-11-01T00:00:00+07:00', 'income');

    $row = $this->getJson('/api/v1/reports/category-comparison?months=3&type=income')->json('data.rows.0');

    expect($row['totals'])->toBe([0, 9000, 1000]);
});

it('excludes transfers, deleted rows, the other type and other users', function () {
    $u = actingUser();
    $c = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);
    catTx($u->id, $c->id, 100, '2026-11-10T10:00:00+07:00', 'transfer_out');
    catTx($u->id, $c->id, 200, '2026-11-10T10:00:00+07:00', 'income');
    catTx($u->id, $c->id, 300, '2026-11-10T10:00:00+07:00')->delete();
    $other = App\Models\User::factory()->create();
    catTx($other->id, null, 400, '2026-11-10T10:00:00+07:00');

    $this->getJson('/api/v1/reports/category-comparison')->assertJsonCount(0, 'data.rows');
});

it('rejects invalid months and type, and requires authentication', function () {
    $this->getJson('/api/v1/reports/category-comparison')->assertUnauthorized();

    actingUser();
    foreach (['months=2', 'months=13', 'months=abc', 'type=transfer_in'] as $query) {
        $this->getJson("/api/v1/reports/category-comparison?{$query}")->assertUnprocessable();
    }
});
