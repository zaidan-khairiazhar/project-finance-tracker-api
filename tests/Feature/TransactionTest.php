<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('creates an expense and returns the resource', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $c = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);

    $this->postJson('/api/v1/transactions', [
        'wallet_id' => $w->id, 'category_id' => $c->id, 'type' => 'expense',
        'amount' => 25000, 'occurred_at' => '2026-11-07T10:00:00+07:00', 'note' => 'makan',
    ])
        ->assertCreated()
        ->assertJsonPath('data.amount', 25000)
        ->assertJsonPath('data.type', 'expense')
        ->assertJsonPath('data.occurred_at', '2026-11-07T03:00:00Z');
});

it('rejects bad amounts with 422', function (mixed $amount) {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);

    $this->postJson('/api/v1/transactions', [
        'wallet_id' => $w->id, 'type' => 'income', 'amount' => $amount,
        'occurred_at' => now()->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors('amount');
})->with(['12.5', 0, -5, 'abc', 9223372036854775808]);

it("rejects category/type mismatch, transfer types, other users' ids, and archived wallets", function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $inc = Category::factory()->create(['user_id' => $u->id, 'type' => 'income']);
    $base = ['wallet_id' => $w->id, 'amount' => 1000, 'occurred_at' => now()->toIso8601String()];

    $this->postJson('/api/v1/transactions', $base + ['type' => 'expense', 'category_id' => $inc->id])
        ->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->postJson('/api/v1/transactions', $base + ['type' => 'transfer_out'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->postJson('/api/v1/transactions', array_merge($base, ['type' => 'income', 'wallet_id' => Wallet::factory()->create()->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('wallet_id');

    $w->update(['is_archived' => true]);
    $this->postJson('/api/v1/transactions', $base + ['type' => 'income'])
        ->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
});

it('filters and paginates by cursor', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    Transaction::factory()->count(3)->create(['user_id' => $u->id, 'wallet_id' => $w->id, 'type' => 'expense', 'note' => 'kopi susu']);
    Transaction::factory()->create(['user_id' => $u->id, 'wallet_id' => $w->id, 'type' => 'income', 'note' => 'gaji']);

    $this->getJson('/api/v1/transactions?q=KOPI')->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/transactions?type=income')->assertJsonCount(1, 'data');

    $page = $this->getJson('/api/v1/transactions?per_page=2')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/transactions?per_page=2&cursor='.$page->json('meta.next_cursor'))->assertJsonCount(2, 'data');
});

it('refuses to edit or delete transfer rows through /transactions', function () {
    $u = actingUser();
    $t = Transaction::factory()->create([
        'user_id' => $u->id, 'type' => 'transfer_out', 'category_id' => null,
        'transfer_group_id' => (string) Str::uuid(),
    ]);

    $this->patchJson("/api/v1/transactions/{$t->id}", ['note' => 'x'])->assertUnprocessable();
    $this->deleteJson("/api/v1/transactions/{$t->id}")->assertUnprocessable();
});

it("returns 404 for another user's transaction", function () {
    actingUser();
    $t = Transaction::factory()->create();

    $this->getJson("/api/v1/transactions/{$t->id}")->assertNotFound();
});

it('stores occurred_at as the correct UTC instant even when sent with a +07:00 offset', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);

    $this->postJson('/api/v1/transactions', [
        'wallet_id' => $w->id, 'type' => 'expense', 'amount' => 1000,
        'occurred_at' => '2026-11-07T23:30:00+07:00',
    ])->assertCreated();

    $stored = DB::table('transactions')
        ->selectRaw("to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS t")
        ->value('t');

    expect($stored)->toBe('2026-11-07 16:30');
});

it('filters from/to as whole days in Asia/Jakarta', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $at = fn (string $iso) => Transaction::factory()->create([
        'user_id' => $u->id, 'wallet_id' => $w->id, 'occurred_at' => $iso,
    ]);
    $at('2026-11-07T23:30:00+07:00'); // 7 Nov di Jakarta (16:30 UTC)
    $at('2026-11-08T00:30:00+07:00'); // 8 Nov di Jakarta (17:30 UTC, masih 7 Nov di UTC)

    $this->getJson('/api/v1/transactions?from=2026-11-08&to=2026-11-08')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/transactions?from=2026-11-07&to=2026-11-07')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/transactions?from=2026-11-07&to=2026-11-08')->assertJsonCount(2, 'data');
});

it('rejects per_page above 100', function () {
    actingUser();

    $this->getJson('/api/v1/transactions?per_page=101')
        ->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('treats an archived wallet as read-only for update and delete', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id, 'is_archived' => true]);
    $t = Transaction::factory()->create(['user_id' => $u->id, 'wallet_id' => $w->id]);

    $this->patchJson("/api/v1/transactions/{$t->id}", ['note' => 'x'])->assertUnprocessable();
    $this->deleteJson("/api/v1/transactions/{$t->id}")->assertUnprocessable();
});

it('updates a transaction and re-checks the category against the type', function () {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);
    $exp = Category::factory()->create(['user_id' => $u->id, 'type' => 'expense']);
    $t = Transaction::factory()->create([
        'user_id' => $u->id, 'wallet_id' => $w->id, 'type' => 'expense', 'category_id' => $exp->id,
    ]);

    $this->patchJson("/api/v1/transactions/{$t->id}", ['amount' => 7000, 'note' => 'revisi'])
        ->assertOk()->assertJsonPath('data.amount', 7000)->assertJsonPath('data.note', 'revisi');

    // Mengubah tipe jadi income sementara kategori masih bertipe expense harus ditolak.
    $this->patchJson("/api/v1/transactions/{$t->id}", ['type' => 'income'])
        ->assertUnprocessable()->assertJsonValidationErrors('category_id');

    $this->deleteJson("/api/v1/transactions/{$t->id}")->assertNoContent();
});

it('defaults occurred_at to now when it is null or blank', function (mixed $value) {
    $u = actingUser();
    $w = Wallet::factory()->create(['user_id' => $u->id]);

    $this->postJson('/api/v1/transactions', [
        'wallet_id' => $w->id, 'type' => 'expense', 'amount' => 1000, 'occurred_at' => $value,
    ])->assertCreated();

    expect(Transaction::first()->occurred_at)->not->toBeNull();
})->with([null, '']);
