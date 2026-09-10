<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTransaction;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }
    use RefreshDatabase;

    public function test_sales_report_aggregates_paid_transactions_without_payment_join_multiplication(): void
    {
        [$first, $item] = $this->createPaidTransaction('2026-09-08 09:00:00', 440, 2);
        [$second] = $this->createPaidTransaction('2026-09-08 15:00:00', 100, 3);
        Payment::create($this->paymentAttributes($first, 'cash', '400.00', '400.00', '0.00', 'sales-cash'));
        Payment::create($this->paymentAttributes($first, 'gcash', '480.00', null, null, 'sales-gcash', 'R-1'));
        Payment::create($this->paymentAttributes($second, 'gcash', '300.00', null, null, 'sales-gcash-2', 'R-2'));

        $this->getJson('/api/reports/sales?from=2026-09-08&to=2026-09-08')
            ->assertOk()
            ->assertJsonPath('data.transaction_count', 2)
            ->assertJsonPath('data.item_quantity', 5)
            ->assertJsonPath('data.total_sales', '1180.00')
            ->assertJsonPath('data.average_transaction_value', '590.00')
            ->assertJsonPath('data.payment_methods.cash', '400.00')
            ->assertJsonPath('data.payment_methods.gcash', '780.00');

        $this->assertSame(20, $item->fresh()->inventory->quantity);
    }

    public function test_sales_report_excludes_unpaid_transactions_and_honors_utc_boundaries(): void
    {
        [$paid] = $this->createPaidTransaction('2026-09-08 00:00:00', 50, 1);
        Payment::create($this->paymentAttributes($paid, 'cash', '50.00', '50.00', '0.00', 'boundary'));
        $this->createTransactionWithStatus('2026-09-08 23:59:59', 'open', 80, 1);
        $this->createPaidTransaction('2026-09-07 23:59:59', 90, 1);

        $this->getJson('/api/reports/sales?from=2026-09-08&to=2026-09-08')
            ->assertOk()
            ->assertJsonPath('data.transaction_count', 1)
            ->assertJsonPath('data.total_sales', '50.00');
    }

    public function test_report_date_range_is_validated(): void
    {
        $this->getJson('/api/reports/sales?from=2026-09-09&to=2026-09-08')->assertUnprocessable();
        $this->getJson('/api/reports/sales?from=09-08-2026')->assertUnprocessable();
        $this->getJson('/api/reports/transactions?status=cancelled')->assertOk();
        $this->getJson('/api/reports/payments?payment_method=bank')->assertUnprocessable();
    }

    public function test_transaction_and_payment_reports_are_read_only_and_paginated(): void
    {
        [$transaction] = $this->createPaidTransaction('2026-09-08 10:00:00', 100, 1);
        Payment::create($this->paymentAttributes($transaction, 'cash', '100.00', '100.00', '0.00', 'readonly'));
        $before = [$this->countRows('orders'), $this->countRows('order_items'), $this->countRows('payments'), Inventory::sum('quantity'), InventoryMovement::count()];

        $this->getJson('/api/reports/transactions?from=2026-09-08&to=2026-09-08')
            ->assertOk()->assertJsonStructure(['data', 'links', 'meta'])->assertJsonPath('data.0.transaction_number', $transaction->transaction_number);
        $this->getJson('/api/reports/payments?from=2026-09-08&to=2026-09-08')
            ->assertOk()->assertJsonStructure(['data', 'links', 'meta'])->assertJsonPath('data.0.amount', '100.00');

        $after = [$this->countRows('orders'), $this->countRows('order_items'), $this->countRows('payments'), Inventory::sum('quantity'), InventoryMovement::count()];
        $this->assertSame($before, $after);
    }

    public function test_menu_item_inventory_and_movement_reports_are_available(): void
    {
        [$transaction, $item] = $this->createPaidTransaction('2026-09-08 11:00:00', 125, 2);
        Payment::create($this->paymentAttributes($transaction, 'gcash', '250.00', null, null, 'menu-report', 'M-1'));
        $inventory = $item->inventory;
        InventoryMovement::create(['inventory_id' => $inventory->id, 'type' => 'restock', 'quantity_change' => 5, 'quantity_before' => 0, 'quantity_after' => 5, 'reason' => 'report test']);

        $this->getJson('/api/reports/menu-items?from=2026-09-08&to=2026-09-08')
            ->assertOk()->assertJsonPath('data.0.menu_item_id', $item->id)->assertJsonPath('data.0.quantity_sold', 2)->assertJsonPath('data.0.sales_amount', '250.00');
        $this->getJson('/api/reports/inventory')->assertOk()->assertJsonPath('data.0.menu_item_id', $item->id);
        $this->getJson('/api/reports/inventory-movements?from=2026-09-08&to=2026-09-08')
            ->assertOk()->assertJsonStructure(['data', 'links', 'meta'])->assertJsonPath('data.0.type', 'restock');
    }

    public function test_transaction_filters_include_open_status_and_search_identifiers(): void
    {
        [$paid] = $this->createPaidTransaction('2026-09-08 10:00:00', 100, 1);
        Payment::create($this->paymentAttributes($paid, 'cash', '100.00', '100.00', '0.00', 'filter-paid'));
        [$open] = $this->createTransactionWithStatus('2026-09-08 11:00:00', 'open', 80, 1);

        $this->getJson('/api/reports/transactions?status=open&search='.$open->transaction_number)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'open');
        $this->getJson('/api/reports/transactions?status=paid')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.transaction_number', $paid->transaction_number);
    }


    public function test_payment_summary_uses_applied_amount_and_completed_payments_only(): void
    {
        [$transaction] = $this->createPaidTransaction('2026-09-08 12:00:00', 100, 1);
        Payment::create($this->paymentAttributes($transaction, 'cash', '80.00', '100.00', '20.00', 'summary-cash'));
        Payment::create($this->paymentAttributes($transaction, 'gcash', '20.00', null, null, 'summary-gcash', 'SUM-1'));

        $this->getJson('/api/reports/payments/summary?from=2026-09-08&to=2026-09-08')
            ->assertOk()->assertJsonPath('data.payment_count', 2)->assertJsonPath('data.total_paid', '100.00')
            ->assertJsonPath('data.payment_methods.cash', '80.00')->assertJsonPath('data.payment_methods.gcash', '20.00');
    }

    private function createPaidTransaction(string $openedAt, int $price, int $quantity): array
    {
        return $this->createTransactionWithStatus($openedAt, 'paid', $price, $quantity);
    }

    private function createTransactionWithStatus(string $openedAt, string $status, int $price, int $quantity): array
    {
        $table = RestaurantTable::create(['table_number' => 'R'.fake()->unique()->numberBetween(1, 9999), 'capacity' => 4, 'status' => 'occupied']);
        $session = TableSession::create(['restaurant_table_id' => $table->id, 'status' => 'active']);
        $category = Category::create(['name' => 'C'.fake()->unique()->numberBetween(1, 999999), 'is_active' => true]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'I'.fake()->unique()->numberBetween(1, 999999), 'price' => $price, 'is_available' => true]);
        $inventory = Inventory::create(['menu_item_id' => $item->id, 'quantity' => 20, 'low_stock_threshold' => 5]);
        $order = Order::create(['table_session_id' => $session->id, 'order_number' => 'O'.fake()->unique()->numberBetween(100000, 999999), 'status' => 'confirmed', 'subtotal' => $price * $quantity, 'submitted_at' => $openedAt, 'confirmed_at' => $openedAt]);
        $orderItem = OrderItem::create(['order_id' => $order->id, 'menu_item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => $price, 'subtotal' => $price * $quantity]);
        $transaction = DiningTransaction::create(['table_session_id' => $session->id, 'transaction_number' => 'T'.fake()->unique()->numberBetween(100000, 999999), 'status' => $status, 'opened_at' => $openedAt, 'paid_at' => $status === 'paid' ? $openedAt : null]);
        $order->update(['dining_transaction_id' => $transaction->id]);

        return [$transaction->fresh(), $item->fresh()->load('inventory'), $orderItem];
    }

    private function paymentAttributes(DiningTransaction $transaction, string $method, string $amount, ?string $received, ?string $change, string $key, ?string $reference = null): array
    {
        return ['dining_transaction_id' => $transaction->id, 'payment_number' => 'P'.fake()->unique()->numberBetween(100000, 999999), 'payment_method' => $method, 'amount' => $amount, 'amount_received' => $received, 'change_amount' => $change, 'reference_number' => $reference, 'idempotency_key' => $key, 'status' => 'completed', 'paid_at' => $transaction->paid_at];
    }

    private function countRows(string $table): int
    {
        return (int) \DB::table($table)->count();
    }
}
