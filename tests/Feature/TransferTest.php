<?php

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Str;

it('creates two linked rows and moves balances', function () {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id, 'initial_balance' => 10000]);
    $b = Wallet::factory()->create(['user_id' => $u->id, 'initial_balance' => 0]);

    $r = $this->postJson('/api/v1/transfers', [
        'from_wallet_id' => $a->id, 'to_wallet_id' => $b->id, 'amount' => 4000,
        'occurred_at' => now()->toIso8601String(),
    ])->assertCreated();

    $rows = Transaction::where('transfer_group_id', $r->json('data.transfer_group_id'))->get();
    expect($rows->pluck('type.value')->sort()->values()->all())->toBe(['transfer_in', 'transfer_out']);
    expect($rows->pluck('category_id')->filter()->all())->toBe([]);

    $this->getJson("/api/v1/wallets/{$a->id}")->assertJsonPath('data.balance', 6000);
    $this->getJson("/api/v1/wallets/{$b->id}")->assertJsonPath('data.balance', 4000);
});

it('returns both rows in the response', function () {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Wallet::factory()->create(['user_id' => $u->id]);

    $this->postJson('/api/v1/transfers', [
        'from_wallet_id' => $a->id, 'to_wallet_id' => $b->id, 'amount' => 2500, 'note' => 'pindah',
        'occurred_at' => '2026-11-07T23:30:00+07:00',
    ])
        ->assertCreated()
        ->assertJsonPath('data.out.type', 'transfer_out')
        ->assertJsonPath('data.out.wallet_id', $a->id)
        ->assertJsonPath('data.in.type', 'transfer_in')
        ->assertJsonPath('data.in.wallet_id', $b->id)
        ->assertJsonPath('data.in.amount', 2500)
        ->assertJsonPath('data.in.occurred_at', '2026-11-07T16:30:00Z')
        ->assertJsonPath('data.out.transfer_group_id', fn ($id) => Str::isUuid($id));
});

it("rejects same wallet, archived wallet, and other user's wallet", function () {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Wallet::factory()->create(['user_id' => $u->id, 'is_archived' => true]);
    $p = fn ($from, $to) => $this->postJson('/api/v1/transfers', [
        'from_wallet_id' => $from, 'to_wallet_id' => $to, 'amount' => 1000,
        'occurred_at' => now()->toIso8601String(),
    ]);

    $p($a->id, $a->id)->assertUnprocessable();
    $p($a->id, $b->id)->assertUnprocessable();
    $p($b->id, $a->id)->assertUnprocessable();
    $p($a->id, Wallet::factory()->create()->id)->assertUnprocessable();
});

it('rejects bad amounts with 422', function (mixed $amount) {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Wallet::factory()->create(['user_id' => $u->id]);

    $this->postJson('/api/v1/transfers', [
        'from_wallet_id' => $a->id, 'to_wallet_id' => $b->id, 'amount' => $amount,
    ])->assertUnprocessable()->assertJsonValidationErrors('amount');
})->with(['12.5', 0, -5, 'abc']);

it('is atomic: a failure on the second row leaves nothing saved', function () {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Wallet::factory()->create(['user_id' => $u->id]);
    $n = 0;
    Transaction::creating(function () use (&$n) {
        if (++$n === 2) {
            throw new RuntimeException('boom');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/api/v1/transfers', [
        'from_wallet_id' => $a->id, 'to_wallet_id' => $b->id, 'amount' => 1000,
        'occurred_at' => now()->toIso8601String(),
    ]))->toThrow(RuntimeException::class);

    expect(Transaction::count())->toBe(0);
});

it('deletes both sides, 404 for unknown, malformed or foreign group', function () {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Wallet::factory()->create(['user_id' => $u->id]);
    $g = $this->postJson('/api/v1/transfers', [
        'from_wallet_id' => $a->id, 'to_wallet_id' => $b->id, 'amount' => 1000,
        'occurred_at' => now()->toIso8601String(),
    ])->json('data.transfer_group_id');

    $this->deleteJson("/api/v1/transfers/$g")->assertNoContent();
    expect(Transaction::count())->toBe(0);

    $this->deleteJson('/api/v1/transfers/'.Str::uuid())->assertNotFound();
    $this->deleteJson('/api/v1/transfers/bukan-uuid')->assertNotFound();

    $foreign = Transaction::factory()->create([
        'type' => 'transfer_out', 'transfer_group_id' => (string) Str::uuid(),
    ]);
    $this->deleteJson("/api/v1/transfers/{$foreign->transfer_group_id}")->assertNotFound();
});

it('refuses to delete a transfer while one of its wallets is archived', function () {
    $u = actingUser();
    $a = Wallet::factory()->create(['user_id' => $u->id]);
    $b = Wallet::factory()->create(['user_id' => $u->id]);
    $g = $this->postJson('/api/v1/transfers', [
        'from_wallet_id' => $a->id, 'to_wallet_id' => $b->id, 'amount' => 1000,
        'occurred_at' => now()->toIso8601String(),
    ])->json('data.transfer_group_id');

    $b->update(['is_archived' => true]);

    $this->deleteJson("/api/v1/transfers/$g")->assertUnprocessable();
    expect(Transaction::count())->toBe(2);
});
