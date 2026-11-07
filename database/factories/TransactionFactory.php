<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Dompet default dibuat milik user yang sama (atau user_id yang di-override test).
            'wallet_id' => fn (array $attributes) => Wallet::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'category_id' => null,
            'type' => 'expense',
            'amount' => fake()->numberBetween(1_000, 500_000),
            'occurred_at' => now(),
            'note' => null,
            'transfer_group_id' => null,
        ];
    }
}
