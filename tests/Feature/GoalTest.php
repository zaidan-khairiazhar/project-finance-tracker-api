<?php

use App\Http\Requests\UpdateGoalRequest;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Hari ini (Jakarta) = 2026-11-12.
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Jakarta'));
});

function goalPayload($user, array $overrides = []): array
{
    $wallet = Wallet::factory()->create(['user_id' => $user->id]);

    return $overrides + ['name' => 'Dana darurat', 'wallet_id' => $wallet->id, 'target_amount' => 1_000_000, 'deadline' => '2027-03-01'];
}

it('computes current_amount from the wallet balance', function () {
    $u = actingUser();
    $wallet = Wallet::factory()->create(['user_id' => $u->id, 'initial_balance' => 200_000]);
    $goal = Goal::factory()->create(['user_id' => $u->id, 'wallet_id' => $wallet->id, 'target_amount' => 1_000_000]);
    Transaction::factory()->create(['user_id' => $u->id, 'wallet_id' => $wallet->id, 'type' => 'income', 'amount' => 300_000]);

    $this->getJson('/api/v1/goals')
        ->assertOk()
        ->assertJsonPath('data.0.id', $goal->id)
        ->assertJsonPath('data.0.current_amount', 500_000)
        ->assertJsonPath('data.0.remaining', 500_000)
        ->assertJsonPath('data.0.percent', 50)
        ->assertJsonPath('data.0.status', 'in_progress');

    Transaction::factory()->create(['user_id' => $u->id, 'wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => 100_000]);

    $this->getJson('/api/v1/goals')
        ->assertJsonPath('data.0.current_amount', 400_000)
        ->assertJsonPath('data.0.remaining', 600_000)
        ->assertJsonPath('data.0.percent', 40);
});

it('orders by deadline ascending with null last', function () {
    $u = actingUser();
    $a = Goal::factory()->create(['user_id' => $u->id, 'name' => 'A', 'deadline' => '2027-03-01']);
    $b = Goal::factory()->create(['user_id' => $u->id, 'name' => 'B', 'deadline' => null]);
    $c = Goal::factory()->create(['user_id' => $u->id, 'name' => 'C', 'deadline' => '2026-12-01']);

    $this->getJson('/api/v1/goals')
        ->assertOk()
        ->assertJsonPath('data.0.id', $c->id)
        ->assertJsonPath('data.1.id', $a->id)
        ->assertJsonPath('data.2.id', $b->id);
});

it('lists only the user’s own goals', function () {
    $u = actingUser();
    $mine = Goal::factory()->create(['user_id' => $u->id]);
    Goal::factory()->create();

    $this->getJson('/api/v1/goals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
});

it('creates a goal and returns 201 with the full resource', function () {
    $u = actingUser();
    $payload = goalPayload($u);

    $this->postJson('/api/v1/goals', $payload)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Dana darurat')
        ->assertJsonPath('data.wallet_id', $payload['wallet_id'])
        ->assertJsonPath('data.wallet_status', 'ok')
        ->assertJsonPath('data.target_amount', 1_000_000)
        ->assertJsonPath('data.current_amount', 0)
        ->assertJsonPath('data.remaining', 1_000_000)
        ->assertJsonPath('data.percent', 0)
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.deadline', '2027-03-01')
        // Nov 2026 -> Mar 2027 = 4 bulan; 1.000.000 / 4.
        ->assertJsonPath('data.monthly_needed', 250_000)
        ->assertJsonStructure(['data' => ['id', 'wallet_name']]);
});

it('creates a goal without a deadline', function () {
    $u = actingUser();

    $this->postJson('/api/v1/goals', goalPayload($u, ['deadline' => null]))
        ->assertCreated()->assertJsonPath('data.deadline', null)->assertJsonPath('data.monthly_needed', null);
});

it('rejects a wallet that belongs to another user', function () {
    $u = actingUser();
    $foreign = Wallet::factory()->create();

    $this->postJson('/api/v1/goals', goalPayload($u, ['wallet_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
});

it('rejects an archived wallet', function () {
    $u = actingUser();
    $wallet = Wallet::factory()->create(['user_id' => $u->id, 'is_archived' => true]);

    $this->postJson('/api/v1/goals', goalPayload($u, ['wallet_id' => $wallet->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
});

it('rejects a wallet that already has a goal', function () {
    $u = actingUser();
    $existing = Goal::factory()->create(['user_id' => $u->id]);

    $this->postJson('/api/v1/goals', goalPayload($u, ['wallet_id' => $existing->wallet_id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('wallet_id')
        ->assertJsonPath('errors.wallet_id.0', 'Dompet ini sudah dipakai target lain.');
});

it('validates the target_amount boundaries', function () {
    $u = actingUser();

    foreach ([0, 9007199254740992] as $bad) {
        $this->postJson('/api/v1/goals', goalPayload($u, ['target_amount' => $bad]))
            ->assertUnprocessable()->assertJsonValidationErrors('target_amount');
    }
    $this->postJson('/api/v1/goals', goalPayload($u, ['target_amount' => 9007199254740991]))->assertCreated();
});

it('validates the name', function () {
    $u = actingUser();

    foreach (['', str_repeat('a', 101)] as $bad) {
        $this->postJson('/api/v1/goals', goalPayload($u, ['name' => $bad]))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }
    $this->postJson('/api/v1/goals', goalPayload($u, ['name' => str_repeat('a', 100)]))->assertCreated();
});

it('rejects a deadline before today (Jakarta) on store and accepts today', function () {
    $u = actingUser();

    $this->postJson('/api/v1/goals', goalPayload($u, ['deadline' => '2026-11-11']))
        ->assertUnprocessable()->assertJsonValidationErrors('deadline');
    $this->postJson('/api/v1/goals', goalPayload($u, ['deadline' => '2026-11-12']))->assertCreated();
});

it('frees the wallet for a new goal after the goal is deleted', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id]);

    $this->deleteJson("/api/v1/goals/{$goal->id}")->assertNoContent();

    $this->postJson('/api/v1/goals', goalPayload($u, ['wallet_id' => $goal->wallet_id]))->assertCreated();
});

it('soft deletes on DELETE', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id]);

    $this->deleteJson("/api/v1/goals/{$goal->id}")->assertNoContent();

    $this->assertSoftDeleted('goals', ['id' => $goal->id]);
});

it('allows renaming an overdue goal when the deadline is omitted or unchanged', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id, 'name' => 'Lama', 'deadline' => '2026-10-01']);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['name' => 'Baru'])
        ->assertOk()->assertJsonPath('data.name', 'Baru')->assertJsonPath('data.status', 'overdue');
    $this->patchJson("/api/v1/goals/{$goal->id}", ['name' => 'Baru 2', 'deadline' => '2026-10-01'])
        ->assertOk()->assertJsonPath('data.name', 'Baru 2');
});

it('rejects a new deadline in the past on update', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id, 'deadline' => '2027-03-01']);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['deadline' => '2026-11-11'])
        ->assertUnprocessable()->assertJsonValidationErrors('deadline');
});

it('can change and clear the deadline on update', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id, 'deadline' => '2027-03-01']);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['deadline' => '2027-05-01'])
        ->assertOk()->assertJsonPath('data.deadline', '2027-05-01');
    $this->patchJson("/api/v1/goals/{$goal->id}", ['deadline' => null])
        ->assertOk()->assertJsonPath('data.deadline', null);
});

it('rejects moving a goal to a wallet used by another goal', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id]);
    $other = Goal::factory()->create(['user_id' => $u->id]);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['wallet_id' => $other->wallet_id])
        ->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
});

it('accepts the goal’s own wallet_id on update', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id]);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['wallet_id' => $goal->wallet_id, 'name' => 'Sama'])
        ->assertOk()->assertJsonPath('data.name', 'Sama');
});

it('moves a goal to a free wallet and rejects an archived one', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id]);
    $free = Wallet::factory()->create(['user_id' => $u->id]);
    $archived = Wallet::factory()->create(['user_id' => $u->id, 'is_archived' => true]);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['wallet_id' => $archived->id])
        ->assertUnprocessable()->assertJsonValidationErrors('wallet_id');
    $this->patchJson("/api/v1/goals/{$goal->id}", ['wallet_id' => $free->id])
        ->assertOk()->assertJsonPath('data.wallet_id', $free->id);
});

it('returns 404 for another user’s goal on PATCH and DELETE', function () {
    actingUser();
    $foreign = Goal::factory()->create();

    $this->patchJson("/api/v1/goals/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/goals/{$foreign->id}")->assertNotFound();
    $this->assertNotSoftDeleted('goals', ['id' => $foreign->id]);
});

it('reports wallet_status ok, archived, and deleted', function () {
    $u = actingUser();
    $ok = Goal::factory()->create(['user_id' => $u->id, 'deadline' => '2026-12-01']);
    $archivedWallet = Wallet::factory()->create(['user_id' => $u->id, 'is_archived' => true]);
    $archived = Goal::factory()->create(['user_id' => $u->id, 'wallet_id' => $archivedWallet->id, 'deadline' => '2026-12-02']);
    $deletedWallet = Wallet::factory()->create(['user_id' => $u->id, 'initial_balance' => 50_000]);
    $deleted = Goal::factory()->create(['user_id' => $u->id, 'wallet_id' => $deletedWallet->id, 'deadline' => '2026-12-03']);
    $deletedWallet->delete();

    $res = $this->getJson('/api/v1/goals')->assertOk();
    $res->assertJsonPath('data.0.id', $ok->id)->assertJsonPath('data.0.wallet_status', 'ok');
    $res->assertJsonPath('data.1.id', $archived->id)->assertJsonPath('data.1.wallet_status', 'archived');
    $res->assertJsonPath('data.2.id', $deleted->id)
        ->assertJsonPath('data.2.wallet_status', 'deleted')
        ->assertJsonPath('data.2.current_amount', 50_000);
});

it('UpdateGoalRequest rules() does not need a route binding', function () {
    expect(fn () => (new UpdateGoalRequest)->rules())->not->toThrow(Throwable::class);
});

it('validates the deadline against the Jakarta date, not the UTC date', function () {
    $u = actingUser();
    // 06:00 WIB tanggal 13 = 23:00 UTC tanggal 12: tanggal UTC masih kemarin.
    $this->travelTo(CarbonImmutable::parse('2026-11-13 06:00', 'Asia/Jakarta'));

    $this->postJson('/api/v1/goals', goalPayload($u, ['deadline' => '2026-11-12']))
        ->assertUnprocessable()->assertJsonValidationErrors('deadline');
    $this->postJson('/api/v1/goals', goalPayload($u, ['deadline' => '2026-11-13']))->assertCreated();
});

it('still allows renaming a goal whose wallet was archived or deleted, even when wallet_id is resent', function () {
    $u = actingUser();
    $archivedWallet = Wallet::factory()->create(['user_id' => $u->id]);
    $deletedWallet = Wallet::factory()->create(['user_id' => $u->id]);
    $a = Goal::factory()->create(['user_id' => $u->id, 'wallet_id' => $archivedWallet->id]);
    $d = Goal::factory()->create(['user_id' => $u->id, 'wallet_id' => $deletedWallet->id]);
    $archivedWallet->update(['is_archived' => true]);
    $deletedWallet->delete();

    $this->patchJson("/api/v1/goals/{$a->id}", ['wallet_id' => $a->wallet_id, 'name' => 'Arsip'])->assertOk();
    $this->patchJson("/api/v1/goals/{$d->id}", ['wallet_id' => strtoupper($d->wallet_id), 'name' => 'Hapus'])->assertOk();
});

it('rejects a foreign or soft-deleted wallet on PATCH and a non-string wallet_id with 422, not 500', function () {
    $u = actingUser();
    $goal = Goal::factory()->create(['user_id' => $u->id]);
    $foreign = Wallet::factory()->create();
    $gone = Wallet::factory()->create(['user_id' => $u->id]);
    $gone->delete();

    $this->patchJson("/api/v1/goals/{$goal->id}", ['wallet_id' => $foreign->id])->assertJsonValidationErrors('wallet_id');
    $this->patchJson("/api/v1/goals/{$goal->id}", ['wallet_id' => $gone->id])->assertJsonValidationErrors('wallet_id');
    $this->patchJson("/api/v1/goals/{$goal->id}", ['wallet_id' => ['x']])->assertJsonValidationErrors('wallet_id');
});
