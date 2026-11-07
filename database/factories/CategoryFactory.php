<?php

namespace Database\Factories;

use App\Enums\CategoryType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'parent_id' => null,
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(array_column(CategoryType::cases(), 'value')),
            'icon' => null,
            'color' => fake()->hexColor(),
        ];
    }
}
