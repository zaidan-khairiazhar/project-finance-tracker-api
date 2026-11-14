<?php

use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Jakarta'));
});

it('the command creates due transactions for every user and exits 0', function () {
    $a = RecurringRule::factory()->create(['start_date' => '2026-11-10']);
    $b = RecurringRule::factory()->create(['start_date' => '2026-11-11']);

    $this->artisan('recurring:generate')->expectsOutputToContain('5')->assertExitCode(0);

    expect(Transaction::where('recurring_rule_id', $a->id)->count())->toBe(3)
        ->and(Transaction::where('recurring_rule_id', $b->id)->count())->toBe(2);
});

it('is scheduled daily at 00:05 Asia/Jakarta', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'recurring:generate'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('5 0 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Jakarta');
});

it('login catches up the user’s rules', function () {
    $user = User::factory()->create(['password' => 'secret-pass-123']);
    $rule = RecurringRule::factory()->create(['user_id' => $user->id, 'start_date' => '2026-11-10']);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass-123'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['user', 'token']]);

    expect(Transaction::where('recurring_rule_id', $rule->id)->count())->toBe(3);
});

it('login still succeeds when the generator throws', function () {
    $user = User::factory()->create(['password' => 'secret-pass-123']);
    $this->mock(RecurringGenerator::class)->shouldReceive('catchUp')->andThrow(new RuntimeException('boom'));
    Log::shouldReceive('error')->once();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass-123'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['user', 'token']]);
});

it('exposes recurring_rule_id on transactions', function () {
    $u = actingUser();
    $plain = Transaction::factory()->create(['user_id' => $u->id]);
    $rule = RecurringRule::factory()->create(['user_id' => $u->id, 'start_date' => '2026-11-12']);
    app(RecurringGenerator::class)->catchUp($u);

    $this->getJson("/api/v1/transactions/{$plain->id}")->assertOk()->assertJsonPath('data.recurring_rule_id', null);

    $generated = Transaction::where('recurring_rule_id', $rule->id)->firstOrFail();
    $this->getJson("/api/v1/transactions/{$generated->id}")->assertOk()->assertJsonPath('data.recurring_rule_id', $rule->id);
});
