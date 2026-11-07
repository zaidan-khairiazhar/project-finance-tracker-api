<?php

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

it('scopes owned models to the authenticated user', function () {
    $a = actingUser();
    $b = User::factory()->create();
    Wallet::factory()->create(['user_id' => $a->id]);
    Wallet::factory()->create(['user_id' => $b->id]);

    expect(Wallet::count())->toBe(1);

    $wallet = Wallet::create(['name' => 'X', 'type' => 'cash', 'initial_balance' => 0]);

    expect($wallet->user_id)->toBe($a->id)
        ->and($wallet->currency)->toBe('IDR');
});

it('lets only the owner pass the policy', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $wallet = Wallet::factory()->create(['user_id' => $a->id]);

    expect(Gate::forUser($a)->allows('update', $wallet))->toBeTrue();
    expect(Gate::forUser($b)->allows('update', $wallet))->toBeFalse();
});

it('rejects non-positive amounts in the database', function () {
    expect(fn () => Transaction::factory()->create(['amount' => 0]))
        ->toThrow(QueryException::class);
});
