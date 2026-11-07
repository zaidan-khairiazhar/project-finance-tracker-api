<?php

namespace Database\Factories;

use App\Enums\WalletType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(array_column(WalletType::cases(), 'value')),
            'initial_balance' => 0,
            'currency' => 'IDR',
            'is_archived' => false,
        ];
    }
}
