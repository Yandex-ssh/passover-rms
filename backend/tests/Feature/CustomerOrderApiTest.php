<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_resolve_table_context_from_qr_token(): void
    {
        $table = RestaurantTable::create([
            'table_number' => '04',
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);

        $this->getJson("/api/customer/tables/{$table->qr_token}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.table_number', '04')
            ->assertJsonPath('data.capacity', 4)
            ->assertJsonPath('data.accepts_orders', true)
            ->assertJsonMissingPath('data.qr_token');
    }

    public function test_customer_table_context_rejects_an_unknown_qr_token(): void
    {
        $this->getJson('/api/customer/tables/unknown-token')->assertNotFound();
    }

    public function test_available_table_context_accepts_customer_orders(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 'A-1',
            'capacity' => 4,
            'status' => 'available',
        ]);

        $this->getJson("/api/customer/tables/{$table->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.accepts_orders', true);
    }

    public function test_customer_can_submit_an_order_with_a_table_qr_token(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-1',
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        $session = TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);
        $menuItem = $this->createMenuItemWithInventory();

        $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
            'items' => [[
                'menu_item_id' => $menuItem->id,
                'quantity' => 2,
                'special_instruction' => 'Less sugar please',
            ]],
            'customer_note' => 'Table order from QR',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.tracking_token', fn ($token) => is_string($token) && strlen($token) === 36)
            ->assertJsonPath('data.subtotal', '260.00')
            ->assertJsonPath('data.table_session.id', $session->id)
            ->assertJsonPath('data.table_session.table.id', $table->id)
            ->assertJsonPath('data.items.0.menu_item.id', $menuItem->id)
            ->assertJsonPath('data.items.0.special_instruction', 'Less sugar please');

        $this->assertDatabaseHas('orders', [
            'table_session_id' => $session->id,
            'status' => 'pending',
            'subtotal' => 260,
            'customer_note' => 'Table order from QR',
        ]);
        $this->assertDatabaseHas('order_items', [
            'menu_item_id' => $menuItem->id,
            'quantity' => 2,
            'subtotal' => 260,
        ]);
    }

    public function test_customer_order_rejects_an_unknown_qr_token(): void
    {
        $menuItem = $this->createMenuItemWithInventory();

        $this->postJson('/api/customer/tables/unknown-token/orders', [
            'items' => [[
                'menu_item_id' => $menuItem->id,
                'quantity' => 1,
            ]],
        ])->assertNotFound();
    }

    public function test_customer_order_opens_a_session_for_an_available_table(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-AVAILABLE',
            'capacity' => 4,
            'status' => 'available',
        ]);
        $menuItem = $this->createMenuItemWithInventory();

        $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
            'items' => [[
                'menu_item_id' => $menuItem->id,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $table->id,
            'status' => 'occupied',
        ]);
        $this->assertDatabaseHas('table_sessions', [
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);
    }

    public function test_customer_order_rejects_when_the_table_has_no_active_session(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-2',
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'closed',
        ]);
        $menuItem = $this->createMenuItemWithInventory();

        $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
            'items' => [[
                'menu_item_id' => $menuItem->id,
                'quantity' => 1,
            ]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_order_rejects_an_invalid_menu_item(): void
    {
        $table = $this->createTableWithActiveSession();

        $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
            'items' => [[
                'menu_item_id' => 999999,
                'quantity' => 1,
            ]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_order_rejects_unavailable_menu_items(): void
    {
        $table = $this->createTableWithActiveSession();
        $menuItem = $this->createMenuItemWithInventory(isAvailable: false);

        $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
            'items' => [[
                'menu_item_id' => $menuItem->id,
                'quantity' => 1,
            ]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_order_rejects_items_when_stock_is_insufficient(): void
    {
        $table = $this->createTableWithActiveSession();
        $menuItem = $this->createMenuItemWithInventory(quantity: 1);

        $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
            'items' => [[
                'menu_item_id' => $menuItem->id,
                'quantity' => 2,
            ]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_order_rejects_malformed_or_invalid_quantities(): void
    {
        $table = $this->createTableWithActiveSession();
        $menuItem = $this->createMenuItemWithInventory();

        foreach ([0, -1, 100, 'two'] as $quantity) {
            $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
                'items' => [[
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $quantity,
                ]],
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_order_uses_backend_prices_and_does_not_deduct_inventory(): void
    {
        $table = $this->createTableWithActiveSession();
        $menuItem = $this->createMenuItemWithInventory(quantity: 17, price: 130);
        $quantityBefore = $menuItem->inventory->quantity;
        $movementCountBefore = $menuItem->inventory->movements()->count();

        $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
            'items' => [[
                'menu_item_id' => $menuItem->id,
                'quantity' => 2,
                'unit_price' => 0,
            ]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '130.00')
            ->assertJsonPath('data.items.0.subtotal', '260.00');

        $this->assertDatabaseHas('order_items', [
            'menu_item_id' => $menuItem->id,
            'unit_price' => 130,
            'subtotal' => 260,
        ]);
        $this->assertSame($quantityBefore, $menuItem->inventory->fresh()->quantity);
        $this->assertSame($movementCountBefore, $menuItem->inventory->movements()->count());
    }

    public function test_customer_order_rolls_back_when_any_item_is_rejected(): void
    {
        $table = $this->createTableWithActiveSession();
        $validItem = $this->createMenuItemWithInventory();
        $unavailableItem = MenuItem::create([
            'category_id' => $validItem->category_id,
            'name' => 'Unavailable Latte',
            'price' => 140,
            'is_available' => false,
        ]);
        Inventory::create([
            'menu_item_id' => $unavailableItem->id,
            'quantity' => 17,
            'low_stock_threshold' => 5,
        ]);

        $this->postJson("/api/customer/tables/{$table->qr_token}/orders", [
            'items' => [
                ['menu_item_id' => $validItem->id, 'quantity' => 1],
                ['menu_item_id' => $unavailableItem->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    private function createTableWithActiveSession(): RestaurantTable
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-' . fake()->unique()->numberBetween(3, 99),
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);

        return $table;
    }

    private function createMenuItemWithInventory(
        int $quantity = 17,
        bool $isAvailable = true,
        bool $categoryActive = true,
        int $price = 130
    ): MenuItem
    {
        $category = Category::create([
            'name' => 'Coffee',
            'is_active' => $categoryActive,
        ]);
        $menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Cafe Latte',
            'price' => $price,
            'is_available' => $isAvailable,
        ]);
        Inventory::create([
            'menu_item_id' => $menuItem->id,
            'quantity' => $quantity,
            'low_stock_threshold' => 5,
        ]);

        return $menuItem;
    }
}
