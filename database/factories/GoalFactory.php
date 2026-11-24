<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Dompet milik user yang sama (atau user_id yang di-override test).
            'wallet_id' => fn (array $attributes) => Wallet::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'name' => fake()->words(2, true),
            'target_amount' => 1_000_000,
            'deadline' => null,
        ];
    }
}
