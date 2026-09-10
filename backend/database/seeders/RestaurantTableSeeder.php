<?php

namespace Database\Seeders;

use App\Models\RestaurantTable;
use Illuminate\Database\Seeder;

class RestaurantTableSeeder extends Seeder
{
    public function run(): void
    {
        // Creates 15 dummy tables in the database
        RestaurantTable::factory()->count(15)->create();
    }
}