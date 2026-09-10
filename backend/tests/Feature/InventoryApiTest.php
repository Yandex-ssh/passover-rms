<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    use RefreshDatabase;

    public function test_adjusting_inventory_updates_availability_and_records_the_movement(): void
    {
        $inventory = $this->createInventory(20);

        $this->postJson("/api/inventories/{$inventory->id}/adjust", [
            'quantity' => 17,
            'reason' => '3 items damaged during storage',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity', 17);

        $this->assertTrue($inventory->menuItem->fresh()->is_available);

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $inventory->id,
            'type' => 'adjustment',
            'quantity_change' => -3,
            'quantity_before' => 20,
            'quantity_after' => 17,
            'reason' => '3 items damaged during storage',
        ]);
    }

    public function test_adjusting_inventory_to_zero_makes_the_menu_item_unavailable(): void
    {
        $inventory = $this->createInventory(1);

        $this->postJson("/api/inventories/{$inventory->id}/adjust", [
            'quantity' => 0,
            'reason' => 'Last item discarded',
        ])
            ->assertOk()
            ->assertJsonPath('data.quantity', 0);

        $this->assertFalse($inventory->menuItem->fresh()->is_available);
    }

    public function test_adjusting_inventory_upward_records_positive_change(): void
    {
        $inventory = $this->createInventory(10);

        $this->postJson("/api/inventories/{$inventory->id}/adjust", [
            'quantity' => 15,
            'reason' => 'Physical count correction',
        ])->assertOk()->assertJsonPath('data.quantity', 15);

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $inventory->id,
            'type' => 'adjustment',
            'quantity_before' => 10,
            'quantity_change' => 5,
            'quantity_after' => 15,
        ]);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_adjustment_rejects_invalid_target_quantity(): void
    {
        $inventory = $this->createInventory(10);

        $this->postJson("/api/inventories/{$inventory->id}/adjust", [
            'quantity' => -1,
            'reason' => 'Invalid count',
        ])->assertUnprocessable();
        $this->postJson("/api/inventories/{$inventory->id}/adjust", [
            'quantity' => 'not-a-number',
            'reason' => 'Invalid count',
        ])->assertUnprocessable();

        $this->assertSame(10, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_cashier_and_guest_cannot_adjust_inventory(): void
    {
        $inventory = $this->createInventory(10);

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/inventories/{$inventory->id}/adjust", ['quantity' => 5])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]));
        $this->postJson("/api/inventories/{$inventory->id}/adjust", ['quantity' => 5])->assertForbidden();
    }

    public function test_adjustment_rolls_back_inventory_when_movement_creation_fails(): void
    {
        $inventory = $this->createInventory(20);
        InventoryMovement::creating(function (): void {
            throw new \RuntimeException('Movement creation failed');
        });

        try {
            $this->withoutExceptionHandling()->postJson("/api/inventories/{$inventory->id}/adjust", [
                'quantity' => 17,
                'reason' => 'Rollback test',
            ]);
        } catch (\RuntimeException $exception) {
            $this->assertSame('Movement creation failed', $exception->getMessage());
        } finally {
            InventoryMovement::flushEventListeners();
        }

        $this->assertSame(20, $inventory->fresh()->quantity);
        $this->assertTrue($inventory->menuItem->fresh()->is_available);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_any_saved_inventory_transition_to_zero_automatically_disables_the_menu_item(): void
    {
        $inventory = $this->createInventory(3);
        $inventory->update(['quantity' => 0]);

        $this->assertFalse($inventory->menuItem->fresh()->is_available);
    }

    private function createInventory(int $quantity): Inventory
    {
        $category = Category::create(['name' => 'Coffee']);
        $menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Cafe Latte',
            'price' => 120,
            'is_available' => true,
        ]);

        return Inventory::create([
            'menu_item_id' => $menuItem->id,
            'quantity' => $quantity,
            'low_stock_threshold' => 5,
        ]);
    }
}
