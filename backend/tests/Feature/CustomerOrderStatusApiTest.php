<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTransaction;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_order_gets_a_backend_tracking_token_and_status_endpoint_is_safe(): void
    {
        $order = $this->createOrder('pending');

        $this->assertNotNull($order->tracking_token);
        $this->assertSame(36, strlen($order->tracking_token));

        $this->getJson("/api/customer/orders/{$order->tracking_token}/status")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['order_number', 'status', 'submitted_at', 'confirmed_at', 'updated_at']])
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.table_session_id')
            ->assertJsonMissingPath('data.dining_transaction_id')
            ->assertJsonMissingPath('data.items')
            ->assertJsonMissingPath('data.payments')
            ->assertJsonMissingPath('data.receipt')
            ->assertJsonMissingPath('data.kitchen_ticket')
            ->assertJsonMissingPath('data.inventory');
    }

    public function test_tracking_token_is_unique_stable_and_not_taken_from_request_input(): void
    {
        $first = $this->createOrder('pending');
        $second = $this->createOrder('pending', ['tracking_token' => 'request-controlled-token']);

        $this->assertNotSame($first->tracking_token, $second->tracking_token);
        $this->assertNotSame('request-controlled-token', $second->tracking_token);
        $this->assertSame($first->tracking_token, $first->fresh()->tracking_token);
    }

    public function test_unknown_tracking_token_returns_not_found_without_order_details(): void
    {
        $this->getJson('/api/customer/orders/00000000-0000-4000-8000-000000000000/status')
            ->assertNotFound();
    }

    public function test_customer_status_reflects_supported_order_transitions(): void
    {
        $order = $this->createOrder('pending');

        foreach (['pending', 'confirmed', 'preparing', 'completed'] as $status) {
            $order->update(['status' => $status]);
            $this->getJson("/api/customer/orders/{$order->tracking_token}/status")
                ->assertOk()->assertJsonPath('data.status', $status);
        }
    }

    public function test_customer_can_see_rejected_status_without_internal_rejection_details(): void
    {
        $order = $this->createOrder('rejected');

        $this->getJson("/api/customer/orders/{$order->tracking_token}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonMissingPath('data.rejection_reason');
    }

    public function test_tracking_remains_available_after_table_session_closes(): void
    {
        $order = $this->createOrder('confirmed');
        $order->tableSession->update(['status' => 'closed', 'closed_at' => now()]);

        $this->getJson("/api/customer/orders/{$order->tracking_token}/status")
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_status_polling_does_not_consume_chatbot_or_login_rate_limits(): void
    {
        $order = $this->createOrder('pending');
        $statusUrl = "/api/customer/orders/{$order->tracking_token}/status";

        for ($i = 0; $i < 60; $i++) {
            $this->getJson($statusUrl)->assertOk();
        }
        $this->getJson($statusUrl)->assertStatus(429);

        // Validation failures still consume their own endpoint's allowance.
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/customer/chatbot', [])->assertUnprocessable();
        }
        $this->postJson('/api/customer/chatbot', [])->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/login', [])->assertStatus(429);
    }

    public function test_status_endpoint_is_public_read_only_and_does_not_mutate_business_data(): void
    {
        $order = $this->createOrder('pending');
        $before = [Order::count(), OrderItem::count(), Inventory::sum('quantity'), InventoryMovement::count(), KitchenTicket::count(), DiningTransaction::count(), Payment::count(), Receipt::count(), TableSession::count()];

        $this->getJson("/api/customer/orders/{$order->tracking_token}/status")->assertOk();

        $after = [Order::count(), OrderItem::count(), Inventory::sum('quantity'), InventoryMovement::count(), KitchenTicket::count(), DiningTransaction::count(), Payment::count(), Receipt::count(), TableSession::count()];
        $this->assertSame($before, $after);
        $this->assertSame('pending', $order->fresh()->status);
    }

    private function createOrder(string $status, array $overrides = []): Order
    {
        $table = RestaurantTable::create(['table_number' => 'T-' . fake()->unique()->numberBetween(1, 9999), 'capacity' => 4, 'status' => 'occupied']);
        $session = TableSession::create(['restaurant_table_id' => $table->id, 'status' => 'active']);
        $category = Category::create(['name' => 'Category-' . fake()->unique()->numberBetween(1, 999999), 'is_active' => true]);
        $menuItem = MenuItem::create(['category_id' => $category->id, 'name' => 'Item-' . fake()->unique()->numberBetween(1, 999999), 'price' => 130, 'is_available' => true]);
        Inventory::create(['menu_item_id' => $menuItem->id, 'quantity' => 10, 'low_stock_threshold' => 5]);
        $order = Order::create(array_merge([
            'table_session_id' => $session->id,
            'order_number' => 'ORD-' . fake()->unique()->numberBetween(100000, 999999),
            'status' => $status,
            'subtotal' => 130,
            'submitted_at' => now(),
        ], $overrides));
        OrderItem::create(['order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'quantity' => 1, 'unit_price' => 130, 'subtotal' => 130]);

        return $order->fresh()->load('tableSession');
    }
}
