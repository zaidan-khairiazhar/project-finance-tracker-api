<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Kategori pengeluaran milik user yang sama (atau user_id yang di-override test).
            'category_id' => fn (array $attributes) => Category::factory()
                ->create(['user_id' => $attributes['user_id'], 'type' => 'expense'])->id,
            'amount' => 100_000,
        ];
    }
}
