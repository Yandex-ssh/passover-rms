<?php

namespace Tests\Feature;

use App\Models\RestaurantTable;
use App\Models\TableSession;
use Database\Seeders\RestaurantTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_session_is_created_with_generated_session_token_and_opened_at(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 16,
            'capacity' => 4,
            'status' => 'available',
        ]);

        $session = TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);

        $this->assertNotEmpty($session->session_token);
        $this->assertNotNull($session->opened_at);
        $this->assertDatabaseHas('table_sessions', [
            'id' => $session->id,
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);
    }

    public function test_seeded_restaurant_tables_have_qr_tokens_when_model_events_are_disabled(): void
    {
        RestaurantTable::withoutEvents(function (): void {
            $this->seed(RestaurantTableSeeder::class);
        });

        $this->assertSame(15, RestaurantTable::count());
        $this->assertSame(0, RestaurantTable::whereNull('qr_token')->count());
    }
}
