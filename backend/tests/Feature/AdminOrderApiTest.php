<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_monitor_requires_an_active_admin(): void
    {
        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]));
        $this->getJson('/api/admin/orders')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->getJson('/api/admin/orders')->assertUnauthorized();
    }

    public function test_admin_can_filter_and_paginate_orders_without_changing_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $table = RestaurantTable::create(['table_number' => 'A1', 'capacity' => 4, 'status' => 'occupied']);
        $session = TableSession::create(['restaurant_table_id' => $table->id, 'status' => 'active']);
        for ($i = 1; $i <= 22; $i++) {
            Order::create(['table_session_id' => $session->id, 'order_number' => 'ORDER-'.$i,
                'status' => $i === 22 ? 'confirmed' : 'pending', 'subtotal' => 0, 'submitted_at' => now()]);
        }

        $this->getJson('/api/admin/orders?status=pending')->assertOk()
            ->assertJsonPath('meta.total', 21)->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.table_session.table.table_number', 'A1')
            ->assertJsonPath('data.0.dining_transaction', null);
        $this->getJson('/api/admin/orders?status=pending&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/orders?status=confirmed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/orders?status=unsupported')->assertUnprocessable();
        $this->assertDatabaseCount('orders', 22);
        $this->assertSame(21, Order::where('status', 'pending')->count());
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id, 'status' => 'occupied']);
    }
}
