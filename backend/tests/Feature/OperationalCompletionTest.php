<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private RestaurantTable $table;
    private MenuItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $this->table = RestaurantTable::create(['table_number' => 'Test 1', 'capacity' => 4, 'status' => 'available']);
        $category = Category::create(['name' => 'Coffee', 'is_active' => true]);
        $this->item = MenuItem::create(['category_id' => $category->id, 'name' => 'Latte', 'price' => 125, 'is_available' => true]);
        Inventory::create(['menu_item_id' => $this->item->id, 'quantity' => 20, 'low_stock_threshold' => 5]);
        $this->actingAs($this->cashier);
    }

    private function order(): Order
    {
        $response = $this->postJson("/api/cashier/tables/{$this->table->id}/orders", [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 1, 'special_instruction' => 'No sugar']],
            'subtotal' => 1, 'customer_note' => 'Serve together',
        ])->assertCreated()->assertJsonPath('data.subtotal', '250.00')->assertJsonPath('data.status', 'pending');

        return Order::findOrFail($response->json('data.id'));
    }

    private function confirm(Order $order): Order
    {
        $this->postJson("/api/cashier/orders/{$order->id}/confirm", ['confirmed_by' => 999])->assertOk();

        return $order->fresh();
    }

    public function test_cashier_entry_uses_server_prices_and_stock_is_only_deducted_on_confirmation(): void
    {
        $order = $this->order();
        $this->assertSame(20, $this->item->inventory->quantity);
        $this->assertSame('occupied', $this->table->fresh()->status);
        $this->getJson('/api/cashier/tables')->assertOk()->assertJsonPath('data.0.active_session.id', $order->table_session_id);
        $order = $this->confirm($order);
        $this->assertSame($this->cashier->id, $order->confirmed_by);
        $this->assertSame(18, $this->item->inventory->fresh()->quantity);
        $this->postJson("/api/cashier/orders/{$order->id}/confirm")->assertConflict();
        $this->assertSame(18, $this->item->inventory->fresh()->quantity);
    }

    public function test_cashier_entry_rejects_duplicate_items_and_insufficient_stock_atomically(): void
    {
        $line = ['menu_item_id' => $this->item->id, 'quantity' => 1];
        $this->postJson("/api/cashier/tables/{$this->table->id}/orders", ['items' => [$line, $line]])->assertUnprocessable();
        $this->postJson("/api/cashier/tables/{$this->table->id}/orders", ['items' => [['menu_item_id' => $this->item->id, 'quantity' => 21]]])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('table_sessions', 0);
        $this->assertSame('available', $this->table->fresh()->status);
    }

    public function test_new_operations_are_restricted_to_active_cashiers(): void
    {
        $order = $this->order();
        foreach (['admin', 'cashier'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $role !== 'cashier']));
            $status = $role === 'admin' ? 403 : 401;
            $this->postJson("/api/cashier/tables/{$this->table->id}/orders", [])->assertStatus($status);
            $this->postJson("/api/cashier/table-sessions/{$order->table_session_id}/close")->assertStatus($status);
        }
        auth()->forgetGuards();
        $this->postJson("/api/cashier/tables/{$this->table->id}/orders", [])->assertUnauthorized();
        $this->postJson("/api/cashier/table-sessions/{$order->table_session_id}/close")->assertUnauthorized();
    }

    public function test_kitchen_slip_is_discoverable_and_reprints_keep_the_same_ticket(): void
    {
        $order = $this->confirm($this->order());
        $ticket = $order->kitchenTicket;
        $this->getJson('/api/cashier/orders?status=all')->assertOk()->assertJsonPath('data.0.kitchen_ticket.id', $ticket->id);
        $this->getJson("/api/kitchen-order-slips/{$ticket->id}")->assertOk()
            ->assertJsonPath('data.items.0.special_instruction', 'No sugar')->assertJsonPath('data.customer_note', 'Serve together')
            ->assertJsonMissingPath('data.items.0.unit_price');
        foreach ([1, 2] as $count) {
            $this->postJson("/api/kitchen-order-slips/{$ticket->id}/printed")->assertOk()
                ->assertJsonPath('data.id', $ticket->id)->assertJsonPath('data.print_count', $count);
        }
        $this->assertDatabaseCount('kitchen_tickets', 1);
    }

    public function test_closure_blocks_unfinished_and_unpaid_orders_then_opens_a_fresh_session(): void
    {
        $order = $this->order();
        $close = "/api/cashier/table-sessions/{$order->table_session_id}/close";
        $this->postJson($close)->assertUnprocessable();
        $order = $this->confirm($order);
        $this->postJson($close)->assertUnprocessable();
        $this->postJson("/api/kitchen-tickets/{$order->id}/prepare")->assertOk();
        $this->postJson("/api/kitchen-tickets/{$order->id}/complete")->assertOk();
        $this->postJson($close)->assertUnprocessable();
        $this->postJson("/api/cashier/transactions/{$order->dining_transaction_id}/payments/cash", ['amount_received' => 250, 'idempotency_key' => 'close-session-payment'])->assertCreated();
        $this->assertSame('active', $order->tableSession->fresh()->status);
        $this->postJson($close)->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.table_status', 'available');
        $this->assertNotNull($order->tableSession->fresh()->closed_at);
        $this->postJson($close)->assertConflict();
        $next = $this->order();
        $this->assertNotSame($order->table_session_id, $next->table_session_id);
        $this->assertSame('occupied', $this->table->fresh()->status);
    }

    public function test_rejected_only_session_can_close_but_admin_cannot_bypass_an_active_session(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->putJson("/api/restaurant-tables/{$this->table->id}", ['status' => 'available'])->assertUnprocessable();
        $this->actingAs($this->cashier)->postJson("/api/cashier/orders/{$order->id}/reject")->assertOk();
        $this->postJson("/api/cashier/table-sessions/{$order->table_session_id}/close")->assertOk();
        $this->assertSame(20, $this->item->inventory->quantity);
    }

    public function test_cash_and_gcash_record_authenticated_actor_and_idempotent_retry_keeps_original_actor(): void
    {
        $order = $this->confirm($this->order());
        $cash = ['amount_received' => 100, 'idempotency_key' => 'attribution-cash', 'processed_by' => 999];
        $this->postJson("/api/cashier/transactions/{$order->dining_transaction_id}/payments/cash", $cash)->assertCreated();
        $second = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $this->actingAs($second)->postJson("/api/cashier/transactions/{$order->dining_transaction_id}/payments/cash", $cash)->assertOk();
        $this->postJson("/api/cashier/transactions/{$order->dining_transaction_id}/payments/gcash", ['amount' => 150, 'reference_number' => 'verified-ref', 'idempotency_key' => 'attribution-gcash'])->assertCreated();
        $this->assertSame($this->cashier->id, Payment::where('payment_method', 'cash')->firstOrFail()->processed_by);
        $this->assertSame($second->id, Payment::where('payment_method', 'gcash')->firstOrFail()->processed_by);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->deleteJson("/api/admin/users/{$this->cashier->id}")->assertUnprocessable();
        $this->deleteJson("/api/admin/users/{$second->id}")->assertUnprocessable();
        $this->postJson("/api/admin/users/{$second->id}/deactivate")->assertOk();
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_database_prevents_deleting_a_referenced_cashier(): void
    {
        $this->confirm($this->order());
        $this->expectException(QueryException::class);
        $this->cashier->delete();
    }
}
