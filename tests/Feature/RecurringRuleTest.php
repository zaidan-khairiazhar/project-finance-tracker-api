<?php

use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Jakarta'));
});

function payload($user, array $overrides = []): array
{
    $wallet = Wallet::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    return $overrides + [
        'wallet_id' => $wallet->id, 'category_id' => $category->id, 'type' => 'expense', 'amount' => 25000,
        'note' => 'Langganan', 'frequency' => 'monthly', 'start_date' => '2026-12-01',
    ];
}

it('creates a rule and returns 201 with the resource', function () {
    $u = actingUser();

    $this->postJson('/api/v1/recurring-rules', payload($u))
        ->assertCreated()
        ->assertJsonPath('data.amount', 25000)
        ->assertJsonPath('data.frequency', 'monthly')
        ->assertJsonPath('data.start_date', '2026-12-01')
        ->assertJsonPath('data.next_due_date', '2026-12-01')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonStructure(['data' => ['id', 'wallet_id', 'wallet_name', 'category_id', 'category_name', 'type', 'note', 'end_date', 'paused_reason']]);
});

it('creates today’s occurrence immediately when start_date is today', function () {
    $u = actingUser();

    $this->postJson('/api/v1/recurring-rules', payload($u, ['start_date' => '2026-11-12', 'frequency' => 'daily']))
        ->assertCreated()
        ->assertJsonPath('data.next_due_date', '2026-11-13');

    expect(Transaction::whereNotNull('recurring_rule_id')->count())->toBe(1);
});

it('does not create anything for a future start_date', function () {
    $u = actingUser();

    $this->postJson('/api/v1/recurring-rules', payload($u, ['start_date' => '2026-12-01']))->assertCreated();

    expect(Transaction::count())->toBe(0);
});

it('rejects a start_date more than 366 days in the past and accepts exactly 366', function () {
    $u = actingUser();

    $this->postJson('/api/v1/recurring-rules', payload($u, ['start_date' => '2025-11-10']))
        ->assertUnprocessable()->assertJsonValidationErrors('start_date');
    // 2025-11-11 tepat 366 hari sebelum 2026-11-12.
    $this->postJson('/api/v1/recurring-rules', payload($u, ['start_date' => '2025-11-11']))->assertCreated();
});

it('validates the amount boundaries', function () {
    $u = actingUser();

    foreach ([0, 9007199254740992, 1.5, '12abc'] as $bad) {
        $this->postJson('/api/v1/recurring-rules', payload($u, ['amount' => $bad]))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }
    $this->postJson('/api/v1/recurring-rules', payload($u, ['amount' => 9007199254740991, 'start_date' => '2026-12-01']))->assertCreated();
});

it('rejects an end_date before the start_date', function () {
    $u = actingUser();

    $this->postJson('/api/v1/recurring-rules', payload($u, ['end_date' => '2026-11-30']))
        ->assertUnprocessable()->assertJsonValidationErrors('end_date');
});

it('rejects an unknown frequency and a transfer type', function () {
    $u = actingUser();

    $this->postJson('/api/v1/recurring-rules', payload($u, ['frequency' => 'yearly']))->assertJsonValidationErrors('frequency');
    $this->postJson('/api/v1/recurring-rules', payload($u, ['type' => 'transfer_out']))->assertJsonValidationErrors('type');
});

it('rejects foreign ids, archived wallets and category of the wrong type with 422', function () {
    $u = actingUser();
    $other = Wallet::factory()->create();
    $otherCategory = Category::factory()->create(['type' => 'expense']);
    $archived = Wallet::factory()->create(['user_id' => $u->id, 'is_archived' => true]);
    $income = Category::factory()->create(['user_id' => $u->id, 'type' => 'income']);

    $this->postJson('/api/v1/recurring-rules', payload($u, ['wallet_id' => $other->id]))->assertJsonValidationErrors('wallet_id');
    $this->postJson('/api/v1/recurring-rules', payload($u, ['category_id' => $otherCategory->id]))->assertJsonValidationErrors('category_id');
    $this->postJson('/api/v1/recurring-rules', payload($u, ['wallet_id' => $archived->id]))->assertJsonValidationErrors('wallet_id');
    $this->postJson('/api/v1/recurring-rules', payload($u, ['category_id' => $income->id]))->assertJsonValidationErrors('category_id');
});

it('lists only own rules ordered by next_due_date with names and status', function () {
    $u = actingUser();
    RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-12-20', 'note' => 'later']);
    RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-12-05', 'note' => 'sooner']);
    RecurringRule::factory()->create(['start_date' => '2026-12-01']);

    $this->getJson('/api/v1/recurring-rules')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.note', 'sooner')
        ->assertJsonPath('data.1.note', 'later')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonStructure(['data' => [['wallet_name', 'category_name']]]);
});

it('returns 404 when deleting another user’s rule', function () {
    actingUser();
    $theirs = RecurringRule::factory()->create();

    $this->deleteJson("/api/v1/recurring-rules/{$theirs->id}")->assertNotFound();
});

it('soft deletes a rule but keeps the generated transactions', function () {
    $u = actingUser();
    $rule = RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-11-11']);
    $this->postJson('/api/v1/recurring-rules/run')->assertOk();

    $this->deleteJson("/api/v1/recurring-rules/{$rule->id}")->assertNoContent();

    expect(RecurringRule::find($rule->id))->toBeNull()
        ->and(Transaction::where('recurring_rule_id', $rule->id)->count())->toBe(2);
});

it('run creates the due occurrences and returns the count', function () {
    $u = actingUser();
    RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-11-10']);

    $this->postJson('/api/v1/recurring-rules/run')->assertOk()->assertExactJson(['created' => 3]);
    $this->postJson('/api/v1/recurring-rules/run')->assertOk()->assertExactJson(['created' => 0]);
});

it('changes the amount for future occurrences only', function () {
    $u = actingUser();
    $rule = RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-11-11']);
    $this->postJson('/api/v1/recurring-rules/run')->assertOk();

    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['amount' => 75000])->assertOk()->assertJsonPath('data.amount', 75000);
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Jakarta'));
    $this->postJson('/api/v1/recurring-rules/run')->assertOk()->assertExactJson(['created' => 1]);

    $amounts = Transaction::where('recurring_rule_id', $rule->id)->orderBy('occurrence_date')->pluck('amount')->all();
    expect($amounts)->toBe([50000, 50000, 75000]);
});

it('rejects frequency and start_date changes with 422', function () {
    $u = actingUser();
    $rule = RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-12-01']);

    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['frequency' => 'weekly'])->assertJsonValidationErrors('frequency');
    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['start_date' => '2026-12-02'])->assertJsonValidationErrors('start_date');
});

it('rejects null or empty frequency and start_date with 422 and leaves the rule unchanged', function () {
    $u = actingUser();
    $rule = RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-12-01', 'frequency' => 'daily']);

    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['frequency' => null])->assertUnprocessable()->assertJsonValidationErrors('frequency');
    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['start_date' => null])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['start_date' => ''])->assertUnprocessable()->assertJsonValidationErrors('start_date');

    $fresh = $rule->fresh();
    expect($fresh->frequency->value)->toBe('daily')->and($fresh->start_date->toDateString())->toBe('2026-12-01');
});

it('lets end_date be cleared with null and rejects one before the start_date', function () {
    $u = actingUser();
    $rule = RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-12-10', 'end_date' => '2026-12-31']);

    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['end_date' => '2026-12-09'])->assertJsonValidationErrors('end_date');
    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['end_date' => null])->assertOk()->assertJsonPath('data.end_date', null);
});

it('pauses manually without a paused_reason', function () {
    $u = actingUser();
    $rule = RecurringRule::factory()->create(['user_id' => $u->id]);

    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.paused_reason', null);
});

it('reactivating moves next_due_date to today and creates nothing', function () {
    $u = actingUser();
    $rule = RecurringRule::factory()->create([
        'user_id' => $u->id, 'start_date' => '2026-11-02', 'next_due_date' => '2026-11-02', 'is_active' => false, 'paused_reason' => 'wallet_archived',
    ]);
    expect($rule->next_due_date->toDateString())->toBe('2026-11-02');

    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.next_due_date', '2026-11-12')
        ->assertJsonPath('data.paused_reason', null)
        ->assertJsonPath('data.status', 'active');

    expect(Transaction::count())->toBe(0);
});

it('refuses to reactivate while the wallet is still archived or after the category was deleted', function () {
    $u = actingUser();
    $archived = RecurringRule::factory()->create(['user_id' => $u->id, 'is_active' => false]);
    $archived->wallet->update(['is_archived' => true]);
    $noCategory = RecurringRule::factory()->create(['user_id' => $u->id, 'is_active' => false]);
    $noCategory->category->delete();

    $this->patchJson("/api/v1/recurring-rules/{$archived->id}", ['is_active' => true])->assertJsonValidationErrors('wallet_id');
    $this->patchJson("/api/v1/recurring-rules/{$noCategory->id}", ['is_active' => true])->assertJsonValidationErrors('category_id');
    expect($archived->fresh()->is_active)->toBeFalse();
});

it('can fix the wallet and reactivate in one request', function () {
    $u = actingUser();
    $rule = RecurringRule::factory()->create(['user_id' => $u->id, 'is_active' => false, 'paused_reason' => 'wallet_archived']);
    $rule->wallet->update(['is_archived' => true]);
    $fresh = Wallet::factory()->create(['user_id' => $u->id]);

    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['is_active' => true, 'wallet_id' => $fresh->id])
        ->assertOk()->assertJsonPath('data.wallet_id', $fresh->id);
});

it('returns 404 when updating another user’s rule', function () {
    actingUser();
    $theirs = RecurringRule::factory()->create();

    $this->patchJson("/api/v1/recurring-rules/{$theirs->id}", ['amount' => 1000])->assertNotFound();
});

it('treats is_active 1 and "1" as a real reactivation (no flood of old transactions)', function (mixed $flag) {
    $u = actingUser();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Jakarta'));
    $rule = RecurringRule::factory()->create([
        'user_id' => $u->id, 'frequency' => 'daily', 'start_date' => '2026-11-02', 'next_due_date' => '2026-11-02',
        'is_active' => false, 'paused_reason' => 'wallet_archived',
    ]);

    $this->patchJson("/api/v1/recurring-rules/{$rule->id}", ['is_active' => $flag])->assertOk();

    expect($rule->fresh()->next_due_date->toDateString())->toBe('2026-11-12')
        ->and($rule->fresh()->paused_reason)->toBeNull()
        ->and(Transaction::where('recurring_rule_id', $rule->id)->count())->toBe(0);
})->with([1, '1', true]);
