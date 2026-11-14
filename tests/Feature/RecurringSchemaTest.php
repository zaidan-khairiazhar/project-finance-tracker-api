<?php

use App\Models\RecurringRule;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

function occurrence(RecurringRule $rule, string $date): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $rule->user_id, 'wallet_id' => $rule->wallet_id,
        'recurring_rule_id' => $rule->id, 'occurrence_date' => $date,
    ]);
}

it('stores a rule with casts and a computed status', function () {
    $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();

    $active = RecurringRule::factory()->create();
    expect($active->fresh()->status)->toBe('active')
        ->and($active->fresh()->amount)->toBe(50000)
        ->and($active->fresh()->next_due_date->toDateString())->toBe($today->toDateString());

    $paused = RecurringRule::factory()->create(['is_active' => false]);
    expect($paused->status)->toBe('paused');

    $finished = RecurringRule::factory()->create([
        'start_date' => $today->subDays(5)->toDateString(),
        'end_date' => $today->subDay()->toDateString(),
        'next_due_date' => $today->toDateString(),
    ]);
    expect($finished->fresh()->status)->toBe('finished');
});

it('rejects a second transaction for the same rule and occurrence date', function () {
    $rule = RecurringRule::factory()->create();
    occurrence($rule, '2026-11-10');

    expect(fn () => occurrence($rule, '2026-11-10'))->toThrow(UniqueConstraintViolationException::class);
});

it('still rejects the duplicate after the first one is soft deleted', function () {
    $rule = RecurringRule::factory()->create();
    occurrence($rule, '2026-11-10')->delete();

    expect(fn () => occurrence($rule, '2026-11-10'))->toThrow(UniqueConstraintViolationException::class);
});

it('allows many transactions without a rule', function () {
    Transaction::factory()->count(2)->create();

    expect(Transaction::count())->toBe(2);
});
