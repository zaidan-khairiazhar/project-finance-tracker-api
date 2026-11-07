<?php

use App\Models\User;
use Illuminate\Support\Str;

it('uses uuid ids and issues sanctum tokens', function () {
    $user = User::factory()->create();

    expect(Str::isUuid($user->id))->toBeTrue();
    expect($user->createToken('t')->plainTextToken)->toBeString();
});

it('answers json errors under /api/v1', function () {
    $this->getJson('/api/v1/user')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $this->getJson('/api/v1/tidak-ada')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});
