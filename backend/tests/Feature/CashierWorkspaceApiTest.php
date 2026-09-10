<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_workspace_read_routes_are_available_to_active_cashiers(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        RestaurantTable::create(['table_number' => 'C1', 'capacity' => 4, 'status' => 'available']);

        $this->actingAs($cashier)->getJson('/api/cashier/dashboard')
            ->assertOk()->assertJsonPath('data.orders.pending', 0);
        $this->getJson('/api/cashier/tables')
            ->assertOk()->assertJsonPath('data.0.table_number', 'C1');
        $this->getJson('/api/cashier/reports/sales')->assertOk();
        $this->getJson('/api/cashier/reports/transactions')
            ->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson('/api/cashier/reports/payments/summary')->assertOk();
        $this->getJson('/api/cashier/reports/menu-items')->assertOk();
    }

    public function test_cashier_workspace_routes_remain_role_protected(): void
    {
        $this->getJson('/api/cashier/dashboard')->assertUnauthorized();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->getJson('/api/cashier/dashboard')->assertForbidden();
        $this->getJson('/api/cashier/tables')->assertForbidden();
        $this->getJson('/api/cashier/reports/sales')->assertForbidden();
    }

    public function test_cashier_can_view_remaining_stock_and_toggle_only_an_in_stock_item_availability(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $category = Category::create(['name' => 'Drinks', 'is_active' => true]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'Iced Tea', 'price' => 95, 'is_available' => true]);
        Inventory::create(['menu_item_id' => $item->id, 'quantity' => 4, 'low_stock_threshold' => 2]);

        $this->actingAs($cashier)->getJson('/api/cashier/menu-items')
            ->assertOk()
            ->assertJsonPath('data.0.inventory.quantity', 4)
            ->assertJsonPath('data.0.is_available', true);

        $this->putJson("/api/cashier/menu-items/{$item->id}/availability", ['is_available' => false])
            ->assertOk()
            ->assertJsonPath('data.is_available', false);
        $this->assertFalse($item->fresh()->is_available);

        $this->putJson("/api/cashier/menu-items/{$item->id}/availability", ['is_available' => true])
            ->assertOk()
            ->assertJsonPath('data.is_available', true);
    }

    public function test_cashier_cannot_mark_an_out_of_stock_item_available_and_admin_cannot_use_cashier_route(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::create(['name' => 'Drinks', 'is_active' => true]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'Sold Out Tea', 'price' => 95, 'is_available' => false]);
        Inventory::create(['menu_item_id' => $item->id, 'quantity' => 0, 'low_stock_threshold' => 2]);

        $this->actingAs($cashier)->putJson("/api/cashier/menu-items/{$item->id}/availability", ['is_available' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_available');
        $this->actingAs($admin)->getJson('/api/cashier/menu-items')->assertForbidden();
    }
}
