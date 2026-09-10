<?php

namespace Database\Factories;

use App\Models\RestaurantTable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RestaurantTableFactory extends Factory
{
    protected $model = RestaurantTable::class;

    public function definition(): array
    {
        return [
            // Generates table numbers like T-1, T-2, T-3, etc.
            'table_number' => 'T-' . fake()->unique()->numberBetween(1, 50),
            'capacity'     => fake()->randomElement([2, 4, 6, 8]),
            'status'       => fake()->randomElement(['available', 'occupied', 'reserved']),
            'qr_token'     => (string) Str::uuid(),
        ];
    }
}
