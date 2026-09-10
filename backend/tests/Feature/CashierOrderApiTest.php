<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierOrderApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]));
    }
    use RefreshDatabase;

    public function test_pending_orders_are_listed_in_the_cashier_queue(): void
    {
        $pending = $this->createOrder('pending');

        $this->getJson('/api/cashier/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_confirmed_orders_are_excluded_from_the_cashier_queue(): void
    {
        $pending = $this->createOrder('pending');
        $confirmed = $this->createOrder('confirmed');

        $this->getJson('/api/cashier/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonMissing(['id' => $confirmed->id]);
    }

    public function test_rejected_orders_are_excluded_from_the_cashier_queue(): void
    {
        $pending = $this->createOrder('pending');
        $rejected = $this->createOrder('rejected');

        $this->getJson('/api/cashier/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonMissing(['id' => $rejected->id]);
    }

    public function test_cashier_can_view_order_details(): void
    {
        $order = $this->createOrder('pending');
        $item = $order->items->first();

        $this->getJson("/api/cashier/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', '260.00')
            ->assertJsonPath('data.table_session.id', $order->table_session_id)
            ->assertJsonPath('data.table_session.table.id', $order->tableSession->restaurant_table_id)
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.menu_item.id', $item->menu_item_id)
            ->assertJsonPath('data.items.0.unit_price', '130.00')
            ->assertJsonPath('data.items.0.subtotal', '260.00');
    }

    public function test_unknown_orders_return_not_found(): void
    {
        $this->getJson('/api/cashier/orders/999999')
            ->assertNotFound();
    }

    public function test_pending_order_can_be_confirmed_and_inventory_is_deducted_exactly_once(): void
    {
        $order = $this->createOrder('pending', stock: 5);
        $inventory = $order->items->first()->menuItem->inventory;

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.items.0.unit_price', '130.00');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'confirmed',
        ]);
        $this->assertSame(3, $inventory->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $inventory->id,
            'type' => 'order',
            'quantity_change' => -2,
            'quantity_before' => 5,
            'quantity_after' => 3,
        ]);
        $this->assertTrue($inventory->menuItem->fresh()->is_available);
    }

    public function test_confirmation_makes_a_menu_item_unavailable_when_stock_reaches_zero(): void
    {
        $order = $this->createOrder('pending', stock: 2);
        $inventory = $order->items->first()->menuItem->inventory;

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertOk();

        $this->assertSame(0, $inventory->fresh()->quantity);
        $this->assertFalse($inventory->menuItem->fresh()->is_available);
    }

    public function test_confirmation_rejects_insufficient_stock_without_changes(): void
    {
        $order = $this->createOrder('pending', stock: 1);
        $inventory = $order->items->first()->menuItem->inventory;

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertUnprocessable();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
        ]);
        $this->assertSame(1, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_confirmation_rolls_back_all_items_when_one_item_has_insufficient_stock(): void
    {
        $order = $this->createOrder('pending', stock: 5);
        $firstItem = $order->items->first();
        $secondMenuItem = MenuItem::create([
            'category_id' => $firstItem->menuItem->category_id,
            'name' => 'Second Latte-' . fake()->unique()->numberBetween(1, 999999),
            'price' => 90,
            'is_available' => true,
        ]);
        $secondInventory = Inventory::create([
            'menu_item_id' => $secondMenuItem->id,
            'quantity' => 1,
            'low_stock_threshold' => 5,
        ]);
        $order->items()->create([
            'menu_item_id' => $secondMenuItem->id,
            'quantity' => 2,
            'unit_price' => 90,
            'subtotal' => 180,
        ]);

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertUnprocessable();

        $this->assertSame(5, $firstItem->menuItem->inventory->fresh()->quantity);
        $this->assertSame(1, $secondInventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    public function test_confirming_an_already_confirmed_order_returns_conflict_and_does_not_deduct_again(): void
    {
        $order = $this->createOrder('pending', stock: 5);
        $inventory = $order->items->first()->menuItem->inventory;

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertOk();
        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertStatus(409);

        $this->assertSame(3, $inventory->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::where('inventory_id', $inventory->id)->count());
    }

    public function test_confirming_an_already_rejected_order_returns_conflict(): void
    {
        $order = $this->createOrder('rejected');

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertStatus(409);
    }

    public function test_pending_order_can_be_rejected_and_leaves_the_queue(): void
    {
        $order = $this->createOrder('pending');

        $this->postJson("/api/cashier/orders/{$order->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'rejected');

        $this->getJson('/api/cashier/orders')
            ->assertOk()
            ->assertJsonMissing(['id' => $order->id]);
    }

    public function test_rejected_order_cannot_be_rejected_twice(): void
    {
        $order = $this->createOrder('rejected');

        $this->postJson("/api/cashier/orders/{$order->id}/reject")
            ->assertStatus(409);
    }

    public function test_confirmed_order_cannot_be_rejected(): void
    {
        $order = $this->createOrder('confirmed');

        $this->postJson("/api/cashier/orders/{$order->id}/reject")
            ->assertStatus(409);
    }

    private function createOrder(string $status, int $stock = 17): Order
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-' . fake()->unique()->numberBetween(1, 999),
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        $session = TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);
        $category = Category::create([
            'name' => 'Category-' . fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
        ]);
        $menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Cafe Latte-' . fake()->unique()->numberBetween(1, 999999),
            'price' => 130,
            'is_available' => true,
        ]);
        Inventory::create([
            'menu_item_id' => $menuItem->id,
            'quantity' => $stock,
            'low_stock_threshold' => 5,
        ]);
        $order = Order::create([
            'table_session_id' => $session->id,
            'order_number' => 'ORD-' . fake()->unique()->numberBetween(100000, 999999),
            'status' => $status,
            'subtotal' => 260,
            'submitted_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 2,
            'unit_price' => 130,
            'subtotal' => 260,
        ]);

        return $order->fresh()->load([
            'tableSession.restaurantTable',
            'items.menuItem',
        ]);
    }
}
