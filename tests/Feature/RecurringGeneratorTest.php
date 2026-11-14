<?php

use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringGenerator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Jakarta'));
});

function generator(): RecurringGenerator
{
    return app(RecurringGenerator::class);
}

function rule(array $attributes = []): RecurringRule
{
    $attributes += ['start_date' => '2026-11-10'];
    $attributes += ['next_due_date' => $attributes['start_date']];

    return RecurringRule::factory()->create($attributes);
}

it('catches up every missed daily occurrence with its own date', function () {
    $rule = rule();

    expect(generator()->catchUp(User::find($rule->user_id)))->toBe(3);

    $dates = Transaction::where('recurring_rule_id', $rule->id)->orderBy('occurrence_date')->get();
    expect($dates->pluck('occurrence_date')->map->toDateString()->all())->toBe(['2026-11-10', '2026-11-11', '2026-11-12'])
        ->and($dates[0]->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe('2026-11-09T17:00:00Z')
        ->and($dates[0]->amount)->toBe(50000)
        ->and($rule->fresh()->next_due_date->toDateString())->toBe('2026-11-13');
});

it('is idempotent', function () {
    $rule = rule();
    $user = User::find($rule->user_id);
    generator()->catchUp($user);

    expect(generator()->catchUp($user))->toBe(0)
        ->and(Transaction::where('recurring_rule_id', $rule->id)->count())->toBe(3);
});

it('does not recreate a soft-deleted occurrence', function () {
    $rule = rule();
    $user = User::find($rule->user_id);
    generator()->catchUp($user);
    Transaction::where('occurrence_date', '2026-11-11')->first()->delete();
    $rule->update(['next_due_date' => '2026-11-11']);

    expect(generator()->catchUp($user))->toBe(0)
        ->and(Transaction::where('recurring_rule_id', $rule->id)->count())->toBe(2)
        ->and($rule->fresh()->next_due_date->toDateString())->toBe('2026-11-13');
});

it('stops at end_date', function () {
    $rule = rule(['end_date' => '2026-11-11']);

    expect(generator()->catchUpRule($rule))->toBe(2)
        ->and($rule->fresh()->status)->toBe('finished');
});

it('uses the Jakarta calendar day', function () {
    $rule = rule(['start_date' => '2026-11-12']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 23:30', 'Asia/Jakarta'));
    expect(generator()->catchUpRule($rule))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-12 00:30', 'Asia/Jakarta'));
    expect(generator()->catchUpRule($rule->fresh()))->toBe(1);
});

it('clamps monthly day 31 across months', function () {
    $rule = rule(['frequency' => 'monthly', 'start_date' => '2026-01-31']);
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00', 'Asia/Jakarta'));

    expect(generator()->catchUpRule($rule))->toBe(3)
        ->and(Transaction::where('recurring_rule_id', $rule->id)->orderBy('occurrence_date')->get()
            ->pluck('occurrence_date')->map->toDateString()->all())->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('pauses on a soft-deleted wallet without throwing and still serves other rules', function () {
    $broken = rule();
    $broken->wallet->delete();
    $fine = rule();

    expect(generator()->catchUpAll())->toBe(3);

    $broken->refresh();
    expect($broken->is_active)->toBeFalse()
        ->and($broken->paused_reason)->toBe('wallet_deleted')
        ->and(Transaction::where('recurring_rule_id', $broken->id)->count())->toBe(0)
        ->and(Transaction::where('recurring_rule_id', $fine->id)->count())->toBe(3);
});

it('pauses on an archived wallet', function () {
    $rule = rule();
    $rule->wallet->update(['is_archived' => true]);

    expect(generator()->catchUpRule($rule))->toBe(0)
        ->and($rule->fresh()->paused_reason)->toBe('wallet_archived');
});

it('pauses when the category was deleted', function () {
    $rule = rule();
    $rule->category->delete();

    expect(generator()->catchUpRule($rule))->toBe(0)
        ->and($rule->fresh()->paused_reason)->toBe('category_invalid');
});

it('does not pause a rule that is not due yet', function () {
    $rule = rule(['start_date' => '2026-11-20']);
    $rule->wallet->update(['is_archived' => true]);

    expect(generator()->catchUpRule($rule))->toBe(0)
        ->and($rule->fresh()->is_active)->toBeTrue();
});

it('skips inactive rules', function () {
    rule(['is_active' => false]);

    expect(generator()->catchUpAll())->toBe(0);
});

it('catchUp only touches the given user', function () {
    $mine = rule();
    $theirs = rule();

    generator()->catchUp(User::find($mine->user_id));

    expect(Transaction::where('recurring_rule_id', $mine->id)->count())->toBe(3)
        ->and(Transaction::where('recurring_rule_id', $theirs->id)->count())->toBe(0)
        ->and($theirs->fresh()->next_due_date->toDateString())->toBe('2026-11-10');
});

it('ignores an existing row for an occurrence and moves on', function () {
    $rule = rule();
    Transaction::factory()->create([
        'user_id' => $rule->user_id, 'wallet_id' => $rule->wallet_id,
        'recurring_rule_id' => $rule->id, 'occurrence_date' => '2026-11-10',
    ]);

    expect(generator()->catchUpRule($rule))->toBe(2)
        ->and(Transaction::where('recurring_rule_id', $rule->id)->count())->toBe(3)
        ->and($rule->fresh()->next_due_date->toDateString())->toBe('2026-11-13');
});
