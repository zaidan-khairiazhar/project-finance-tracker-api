<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringRuleFactory extends Factory
{
    public function definition(): array
    {
        $start = CarbonImmutable::now('Asia/Jakarta')->toDateString();

        return [
            'user_id' => User::factory(),
            // Dompet dan kategori pengeluaran milik user yang sama (atau user_id yang di-override test).
            'wallet_id' => fn (array $attributes) => Wallet::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'category_id' => fn (array $attributes) => Category::factory()
                ->create(['user_id' => $attributes['user_id'], 'type' => 'expense'])->id,
            'type' => 'expense',
            'amount' => 50_000,
            'note' => null,
            'frequency' => 'daily',
            'start_date' => $start,
            'end_date' => null,
            'next_due_date' => fn (array $attributes) => $attributes['start_date'],
            'is_active' => true,
            'paused_reason' => null,
        ];
    }
}
