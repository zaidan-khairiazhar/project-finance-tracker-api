<?php

use App\Models\Transaction;
use App\Models\Wallet;

it('computes balance from all four transaction types and ignores deleted rows', function () {
    $user = actingUser();
    $wallet = Wallet::factory()->create(['user_id' => $user->id, 'initial_balance' => 1000]);
    $make = fn (string $type, int $amount) => Transaction::factory()->create([
        'user_id' => $user->id, 'wallet_id' => $wallet->id, 'type' => $type, 'amount' => $amount,
    ]);

    $make('income', 500);
    $make('transfer_in', 300);
    $make('expense', 200);
    $make('transfer_out', 100);
    $make('expense', 9999)->delete();

    $this->getJson("/api/v1/wallets/{$wallet->id}")
        ->assertOk()
        ->assertJsonPath('data.balance', 1500);
});

it('archives a wallet that has transactions and deletes an empty one', function () {
    $user = actingUser();
    $used = Wallet::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'wallet_id' => $used->id]);
    $empty = Wallet::factory()->create(['user_id' => $user->id]);

    $this->deleteJson("/api/v1/wallets/{$used->id}")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    $this->deleteJson("/api/v1/wallets/{$empty->id}")->assertNoContent();
    expect(Wallet::find($empty->id))->toBeNull();
});

it("returns 404 for another user's wallet", function () {
    actingUser();
    $wallet = Wallet::factory()->create();

    $this->getJson("/api/v1/wallets/{$wallet->id}")->assertNotFound();
});

it('validates wallet input', function () {
    actingUser();

    $this->postJson('/api/v1/wallets', ['name' => '', 'type' => 'kartu', 'initial_balance' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type', 'initial_balance']);
});

it('creates a wallet and returns it with its balance', function () {
    actingUser();

    $this->postJson('/api/v1/wallets', ['name' => 'BCA', 'type' => 'bank', 'initial_balance' => 250000])
        ->assertCreated()
        ->assertJsonPath('data.name', 'BCA')
        ->assertJsonPath('data.type', 'bank')
        ->assertJsonPath('data.currency', 'IDR')
        ->assertJsonPath('data.is_archived', false)
        ->assertJsonPath('data.balance', 250000);
});

it('lists active wallets before archived ones, each by name', function () {
    $user = actingUser();
    Wallet::factory()->create(['user_id' => $user->id, 'name' => 'B', 'is_archived' => true]);
    Wallet::factory()->create(['user_id' => $user->id, 'name' => 'C']);
    Wallet::factory()->create(['user_id' => $user->id, 'name' => 'A']);

    $this->getJson('/api/v1/wallets')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'A')
        ->assertJsonPath('data.1.name', 'C')
        ->assertJsonPath('data.2.name', 'B');
});

it('renames, archives and unarchives through PATCH', function () {
    $user = actingUser();
    $wallet = Wallet::factory()->create(['user_id' => $user->id, 'name' => 'Lama']);

    $this->patchJson("/api/v1/wallets/{$wallet->id}", ['name' => 'Baru', 'is_archived' => true])
        ->assertOk()
        ->assertJsonPath('data.name', 'Baru')
        ->assertJsonPath('data.is_archived', true);

    $this->patchJson("/api/v1/wallets/{$wallet->id}", ['is_archived' => false])
        ->assertOk()
        ->assertJsonPath('data.is_archived', false);
});
