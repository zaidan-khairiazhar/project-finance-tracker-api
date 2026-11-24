<?php

use App\Models\Goal;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Insert mentah. Dompet dibuat di luar savepoint, dan insert dibungkus DB::transaction (savepoint di dalam
 * transaksi RefreshDatabase) supaya kegagalan CHECK tidak membatalkan transaksi tes pada Postgres.
 */
function rawGoal(Goal $base, int $amount): void
{
    $walletId = Wallet::factory()->create(['user_id' => $base->user_id])->id;

    DB::transaction(fn () => DB::table('goals')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $base->user_id,
        'wallet_id' => $walletId,
        'name' => 'Raw',
        'target_amount' => $amount,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

it('allows only one active goal per wallet', function () {
    $first = Goal::factory()->create();

    expect(fn () => Goal::factory()->create([
        'user_id' => $first->user_id,
        'wallet_id' => $first->wallet_id,
    ]))->toThrow(QueryException::class, 'goals_wallet_id_active_unique');
});

it('frees the wallet when its goal is soft-deleted', function () {
    $first = Goal::factory()->create();
    $first->delete();

    $second = Goal::factory()->create([
        'user_id' => $first->user_id,
        'wallet_id' => $first->wallet_id,
    ]);

    expect($second->exists)->toBeTrue()
        ->and(Goal::where('wallet_id', $first->wallet_id)->count())->toBe(1);
});

it('rejects target_amount outside 1..9007199254740991', function () {
    $base = Goal::factory()->create();

    expect(fn () => rawGoal($base, 0))->toThrow(QueryException::class, 'goals_target_amount_range');
    expect(fn () => rawGoal($base, 9007199254740992))->toThrow(QueryException::class, 'goals_target_amount_range');
    rawGoal($base, 9007199254740991);
    expect(Goal::where('target_amount', 9007199254740991)->exists())->toBeTrue();
});

it('relation wallet() still returns a soft-deleted wallet', function () {
    $goal = Goal::factory()->create();
    $goal->wallet->delete();

    expect($goal->fresh()->wallet)->not->toBeNull()
        ->and($goal->fresh()->wallet->trashed())->toBeTrue();
});

it('casts target_amount to integer, deadline to a date, and defaults deadline to null', function () {
    $goal = Goal::factory()->create(['target_amount' => 2_500_000, 'deadline' => '2027-01-31']);

    expect($goal->fresh()->target_amount)->toBe(2_500_000)
        ->and($goal->fresh()->deadline->toDateString())->toBe('2027-01-31')
        ->and(Goal::factory()->create()->deadline)->toBeNull();
});
