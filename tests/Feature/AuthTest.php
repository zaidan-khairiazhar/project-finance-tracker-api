<?php

use App\Models\Category;
use App\Models\User;
use App\Models\Wallet;

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Zaidan',
        'email' => 'z@x.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ], $overrides);
}

it('registers, seeds defaults, and returns a token', function () {
    $response = $this->postJson('/api/v1/auth/register', validRegistration())->assertCreated();

    expect($response->json('data.token'))->toBeString();

    $user = User::first();
    expect(Category::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(12);
    expect(Wallet::withoutGlobalScopes()->where('user_id', $user->id)->pluck('name')->all())->toBe(['Tunai']);
});

it('rejects an email differing only by case', function () {
    User::factory()->create(['email' => 'a@x.com']);

    $this->postJson('/api/v1/auth/register', validRegistration(['email' => 'A@X.com']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('logs in with valid credentials and returns a token', function () {
    User::factory()->create(['email' => 'a@x.com', 'password' => 'rahasia123']);

    $this->postJson('/api/v1/auth/login', ['email' => 'A@x.com', 'password' => 'rahasia123'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);
});

it('gives the same 422 for wrong password and unknown email', function () {
    User::factory()->create(['email' => 'a@x.com', 'password' => 'rahasia123']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'a@x.com', 'password' => 'salah'])
        ->assertUnprocessable();
    $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'tidak@ada.com', 'password' => 'salah'])
        ->assertUnprocessable();

    expect($wrongPassword->json())->toBe($unknownEmail->json());
});

it('throttles login after 5 attempts per minute', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@x.com', 'password' => 'x']);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'a@x.com', 'password' => 'x'])->assertStatus(429);
});

it('requires a token for me and revokes it on logout', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    $token = User::factory()->create()->createToken('t')->plainTextToken;
    $headers = ['Authorization' => "Bearer $token"];

    $this->getJson('/api/v1/auth/me', $headers)
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name', 'email']]);

    $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized();
});
