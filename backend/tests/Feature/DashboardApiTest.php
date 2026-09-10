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
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }
    use RefreshDatabase;

    public function test_dashboard_returns_zero_safe_summary_when_no_business_data_exists(): void
    {
        Carbon::setTestNow('2026-09-08 12:00:00');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.period.date', '2026-09-08')
            ->assertJsonPath('data.period.timezone', 'UTC')
            ->assertJsonPath('data.sales.total', '0.00')
            ->assertJsonPath('data.sales.paid_transactions', 0)
            ->assertJsonPath('data.payments.count_today', 0)
            ->assertJsonPath('data.orders.pending', 0)
            ->assertJsonPath('data.transactions.open', 0)
            ->assertJsonPath('data.inventory.tracked_items', 0)
            ->assertJsonPath('data.menu.available', 0)
            ->assertJsonPath('data.low_stock_items', []);

        Carbon::setTestNow();
    }

    public function test_dashboard_uses_paid_sales_and_applied_payment_amounts_without_double_counting(): void
    {
        Carbon::setTestNow('2026-09-08 12:00:00');
        [$transaction, $item] = $this->createTransaction('2026-09-08 09:00:00', 'paid', 440, 2);
        Payment::create($this->paymentAttributes($transaction, 'cash', '400.00', '500.00', '100.00', 'dashboard-cash'));
        Payment::create($this->paymentAttributes($transaction, 'gcash', '480.00', null, null, 'dashboard-gcash', 'DASH-1'));
        $this->createTransaction('2026-09-07 23:59:59', 'paid', 100, 1);
        $this->createTransaction('2026-09-08 10:00:00', 'open', 200, 1);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.sales.total', '880.00')
            ->assertJsonPath('data.sales.paid_transactions', 1)
            ->assertJsonPath('data.sales.cash', '400.00')
            ->assertJsonPath('data.sales.gcash', '480.00')
            ->assertJsonPath('data.payments.count_today', 2)
            ->assertJsonPath('data.payments.cash_applied', '400.00')
            ->assertJsonPath('data.payments.gcash_applied', '480.00');

        $this->assertSame(20, $item->fresh()->inventory->quantity);
        Carbon::setTestNow();
    }

    public function test_dashboard_reports_current_order_transaction_inventory_and_menu_state(): void
    {
        Carbon::setTestNow('2026-09-08 12:00:00');
        $this->createTransaction('2026-09-08 08:00:00', 'open', 100, 1, 'pending');
        $this->createTransaction('2026-09-08 08:00:00', 'open', 100, 1, 'confirmed');
        $this->createTransaction('2026-09-08 08:00:00', 'partially_paid', 100, 1, 'preparing');
        $paid = $this->createTransaction('2026-09-08 08:00:00', 'paid', 100, 1, 'completed')[0];
        Payment::create($this->paymentAttributes($paid, 'cash', '100.00', '100.00', '0.00', 'state-paid'));
        $this->createTransaction('2026-09-08 08:00:00', 'open', 100, 1, 'rejected');

        $low = $this->createMenuItem('Low', 2, 5, true);
        $out = $this->createMenuItem('Out', 0, 5, true);
        $disabled = $this->createMenuItem('Disabled', 20, 5, false);
        $this->createMenuItem('Available', 20, 5, true);
        Category::create(['name' => 'Inactive', 'is_active' => false]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.orders.pending', 1)
            ->assertJsonPath('data.orders.confirmed', 1)
            ->assertJsonPath('data.orders.preparing', 1)
            ->assertJsonPath('data.orders.completed', 1)
            ->assertJsonPath('data.orders.rejected', 1)
            ->assertJsonPath('data.transactions.open', 3)
            ->assertJsonPath('data.transactions.partially_paid', 1)
            ->assertJsonPath('data.transactions.paid_today', 1)
            ->assertJsonPath('data.inventory.tracked_items', 9)
            ->assertJsonPath('data.inventory.low_stock', 1)
            ->assertJsonPath('data.inventory.out_of_stock', 1)
            ->assertJsonPath('data.menu.available', 8)
            ->assertJsonPath('data.menu.unavailable', 1)
            ->assertJsonPath('data.menu.active_categories', 9)
            ->assertJsonPath('data.low_stock_items.0.menu_item_id', $low->id)
            ->assertJsonPath('data.low_stock_items.0.quantity', 2);

        $this->assertNotNull($out);
        $this->assertNotNull($disabled);
        Carbon::setTestNow();
    }

    public function test_dashboard_low_stock_limit_is_validated_and_deterministic(): void
    {
        $first = $this->createMenuItem('A', 1, 5, true);
        $second = $this->createMenuItem('B', 2, 5, true);
        $this->createMenuItem('C', 3, 5, true);

        $this->getJson('/api/dashboard?limit=2')
            ->assertOk()->assertJsonCount(2, 'data.low_stock_items')
            ->assertJsonPath('data.low_stock_items.0.menu_item_id', $first->id)
            ->assertJsonPath('data.low_stock_items.1.menu_item_id', $second->id);
        $this->getJson('/api/dashboard?limit=0')->assertUnprocessable();
        $this->getJson('/api/dashboard?limit=51')->assertUnprocessable();
    }

    public function test_dashboard_is_read_only_for_all_business_records(): void
    {
        [$transaction] = $this->createTransaction('2026-09-08 08:00:00', 'paid', 100, 1, 'confirmed');
        $payment = Payment::create($this->paymentAttributes($transaction, 'cash', '100.00', '100.00', '0.00', 'readonly-dashboard'));
        $before = [Order::count(), OrderItem::count(), DiningTransaction::count(), Payment::count(), Receipt::count(), KitchenTicket::count(), Inventory::sum('quantity'), InventoryMovement::count(), TableSession::count()];

        $this->getJson('/api/dashboard')->assertOk();

        $after = [Order::count(), OrderItem::count(), DiningTransaction::count(), Payment::count(), Receipt::count(), KitchenTicket::count(), Inventory::sum('quantity'), InventoryMovement::count(), TableSession::count()];
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    private function createTransaction(string $openedAt, string $transactionStatus, int $price, int $quantity, string $orderStatus = 'confirmed'): array
    {
        $table = RestaurantTable::create(['table_number' => 'D'.fake()->unique()->numberBetween(1, 9999), 'capacity' => 4, 'status' => 'occupied']);
        $session = TableSession::create(['restaurant_table_id' => $table->id, 'status' => 'active']);
        $category = Category::create(['name' => 'Cat'.fake()->unique()->numberBetween(1, 999999), 'is_active' => true]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'Item'.fake()->unique()->numberBetween(1, 999999), 'price' => $price, 'is_available' => true]);
        Inventory::create(['menu_item_id' => $item->id, 'quantity' => 20, 'low_stock_threshold' => 5]);
        $order = Order::create(['table_session_id' => $session->id, 'order_number' => 'DO'.fake()->unique()->numberBetween(100000, 999999), 'status' => $orderStatus, 'subtotal' => $price * $quantity, 'submitted_at' => $openedAt, 'confirmed_at' => $openedAt]);
        OrderItem::create(['order_id' => $order->id, 'menu_item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => $price, 'subtotal' => $price * $quantity]);
        $transaction = DiningTransaction::create(['table_session_id' => $session->id, 'transaction_number' => 'DT'.fake()->unique()->numberBetween(100000, 999999), 'status' => $transactionStatus, 'opened_at' => $openedAt, 'paid_at' => $transactionStatus === 'paid' ? $openedAt : null]);
        $order->update(['dining_transaction_id' => $transaction->id]);

        return [$transaction->fresh(), $item->fresh()->load('inventory'), $order];
    }

    private function createMenuItem(string $name, int $quantity, int $threshold, bool $available): MenuItem
    {
        $category = Category::create(['name' => $name.' Category'.fake()->unique()->numberBetween(1, 999999), 'is_active' => true]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => $name.' Item'.fake()->unique()->numberBetween(1, 999999), 'price' => 100, 'is_available' => $available]);
        Inventory::create(['menu_item_id' => $item->id, 'quantity' => $quantity, 'low_stock_threshold' => $threshold]);

        return $item;
    }

    private function paymentAttributes(DiningTransaction $transaction, string $method, string $amount, ?string $received, ?string $change, string $key, ?string $reference = null): array
    {
        return ['dining_transaction_id' => $transaction->id, 'payment_number' => 'DP'.fake()->unique()->numberBetween(100000, 999999), 'payment_method' => $method, 'amount' => $amount, 'amount_received' => $received, 'change_amount' => $change, 'reference_number' => $reference, 'idempotency_key' => $key, 'status' => 'completed', 'paid_at' => $transaction->paid_at];
    }
}
