<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function actingUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Sanctum::actingAs($user);

    return $user;
}