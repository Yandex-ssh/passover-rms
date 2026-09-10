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
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashierPaymentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]));
    }
    use RefreshDatabase;

    public function test_payment_schema_and_transaction_settlement_fields_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('payments', [
            'id',
            'dining_transaction_id',
            'payment_number',
            'payment_method',
            'amount',
            'amount_received',
            'change_amount',
            'reference_number',
            'idempotency_key',
            'status',
            'paid_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('dining_transactions', 'paid_at'));
    }

    public function test_payment_relationships_and_nullable_method_fields_work(): void
    {
        $transaction = $this->createTransaction();
        $payment = Payment::create([
            'dining_transaction_id' => $transaction->id,
            'payment_number' => 'PAY-20260908-ABC123',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'amount_received' => '100.00',
            'change_amount' => '0.00',
            'reference_number' => null,
            'idempotency_key' => 'cash-schema-key',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $this->assertTrue($payment->diningTransaction->is($transaction));
        $this->assertTrue($transaction->payments->contains($payment));
        $this->assertNull($payment->reference_number);
        $this->assertSame('100.00', $payment->amount);
        $this->assertSame('0.00', $payment->change_amount);
    }

    public function test_payment_number_is_unique(): void
    {
        $transaction = $this->createTransaction();
        $attributes = [
            'dining_transaction_id' => $transaction->id,
            'payment_number' => 'PAY-20260908-UNIQUE',
            'payment_method' => 'gcash',
            'amount' => '100.00',
            'reference_number' => 'REF-ONE',
            'idempotency_key' => 'payment-one',
            'status' => 'completed',
            'paid_at' => now(),
        ];

        Payment::create($attributes);
        $attributes['idempotency_key'] = 'payment-two';

        $this->expectException(QueryException::class);
        Payment::create($attributes);
    }

    public function test_idempotency_key_is_unique_within_a_transaction(): void
    {
        $transaction = $this->createTransaction();
        $attributes = [
            'dining_transaction_id' => $transaction->id,
            'payment_number' => 'PAY-20260908-FIRST1',
            'payment_method' => 'cash',
            'amount' => '50.00',
            'amount_received' => '50.00',
            'change_amount' => '0.00',
            'idempotency_key' => 'same-request-key',
            'status' => 'completed',
            'paid_at' => now(),
        ];

        Payment::create($attributes);
        $attributes['payment_number'] = 'PAY-20260908-SECOND';

        $this->expectException(QueryException::class);
        Payment::create($attributes);
    }

    public function test_cash_overpayment_applies_balance_calculates_change_and_marks_paid(): void
    {
        $transaction = $this->createBillableTransaction();
        $inventoryCount = Inventory::sum('quantity');
        $movementCount = InventoryMovement::count();
        $ticketCount = KitchenTicket::count();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '1000.00',
            'idempotency_key' => 'cash-full-payment',
            'total_amount' => '1.00',
            'change_amount' => '999.00',
            'status' => 'open',
            'processed_by' => 999,
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.amount', '880.00')
            ->assertJsonPath('data.amount_received', '1000.00')
            ->assertJsonPath('data.change_amount', '120.00')
            ->assertJsonPath('data.transaction.total_amount', '880.00')
            ->assertJsonPath('data.transaction.paid_amount', '880.00')
            ->assertJsonPath('data.transaction.remaining_balance', '0.00')
            ->assertJsonPath('data.transaction.status', 'paid');

        $this->assertSame('paid', $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->paid_at);
        $this->assertSame($inventoryCount, Inventory::sum('quantity'));
        $this->assertSame($movementCount, InventoryMovement::count());
        $this->assertSame($ticketCount, KitchenTicket::count());
    }

    public function test_partial_cash_payment_and_later_cash_settlement_are_calculated_safely(): void
    {
        $transaction = $this->createBillableTransaction();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '500.00',
            'idempotency_key' => 'cash-partial-one',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '500.00')
            ->assertJsonPath('data.change_amount', '0.00')
            ->assertJsonPath('data.transaction.paid_amount', '500.00')
            ->assertJsonPath('data.transaction.remaining_balance', '380.00')
            ->assertJsonPath('data.transaction.status', 'partially_paid');

        $this->assertNull($transaction->fresh()->paid_at);

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '500.00',
            'idempotency_key' => 'cash-final-two',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '380.00')
            ->assertJsonPath('data.change_amount', '120.00')
            ->assertJsonPath('data.transaction.paid_amount', '880.00')
            ->assertJsonPath('data.transaction.remaining_balance', '0.00')
            ->assertJsonPath('data.transaction.status', 'paid');

        $this->assertDatabaseCount('payments', 2);
    }

    public function test_cash_payment_rejects_zero_and_negative_amounts(): void
    {
        $transaction = $this->createBillableTransaction();

        foreach (['0.00', '-1.00'] as $index => $amount) {
            $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
                'amount_received' => $amount,
                'idempotency_key' => 'invalid-cash-' . $index,
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_valid_manual_gcash_payment_requires_reference_and_marks_paid(): void
    {
        $transaction = $this->createBillableTransaction();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '880.00',
            'reference_number' => '  GCASH-REF-001  ',
            'idempotency_key' => 'gcash-full-payment',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'gcash')
            ->assertJsonPath('data.amount', '880.00')
            ->assertJsonPath('data.amount_received', null)
            ->assertJsonPath('data.change_amount', null)
            ->assertJsonPath('data.reference_number', 'GCASH-REF-001')
            ->assertJsonPath('data.transaction.status', 'paid')
            ->assertJsonPath('data.transaction.remaining_balance', '0.00');
    }

    public function test_partial_gcash_payment_is_supported_without_change(): void
    {
        $transaction = $this->createBillableTransaction();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '400.00',
            'reference_number' => 'GCASH-PARTIAL-1',
            'idempotency_key' => 'gcash-partial-payment',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '400.00')
            ->assertJsonPath('data.change_amount', null)
            ->assertJsonPath('data.transaction.paid_amount', '400.00')
            ->assertJsonPath('data.transaction.remaining_balance', '480.00')
            ->assertJsonPath('data.transaction.status', 'partially_paid');
    }

    public function test_gcash_rejects_missing_reference_and_overpayment(): void
    {
        $transaction = $this->createBillableTransaction();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '100.00',
            'idempotency_key' => 'gcash-missing-reference',
        ])->assertUnprocessable();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '900.00',
            'reference_number' => 'GCASH-OVERPAY',
            'idempotency_key' => 'gcash-overpayment',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_same_idempotency_key_reuses_payment_but_changed_payload_conflicts(): void
    {
        $transaction = $this->createBillableTransaction();
        $payload = [
            'amount_received' => '500.00',
            'idempotency_key' => 'cash-retry-key',
        ];

        $first = $this->postJson(
            "/api/cashier/transactions/{$transaction->id}/payments/cash",
            $payload
        )->assertCreated()->json('data.id');
        $second = $this->postJson(
            "/api/cashier/transactions/{$transaction->id}/payments/cash",
            $payload
        )->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('payments', 1);

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '501.00',
            'idempotency_key' => 'cash-retry-key',
        ])->assertStatus(409);
    }

    public function test_paid_transaction_rejects_another_normal_payment(): void
    {
        $transaction = $this->createBillableTransaction();
        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '880.00',
            'idempotency_key' => 'cash-paid-first',
        ])->assertCreated();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '10.00',
            'idempotency_key' => 'cash-after-paid',
        ])->assertStatus(409);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_duplicate_gcash_reference_is_rejected_within_transaction(): void
    {
        $transaction = $this->createBillableTransaction();
        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '400.00',
            'reference_number' => 'SAME-REFERENCE',
            'idempotency_key' => 'gcash-reference-one',
        ])->assertCreated();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '100.00',
            'reference_number' => 'SAME-REFERENCE',
            'idempotency_key' => 'gcash-reference-two',
        ])->assertStatus(409);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_history_returns_derived_settlement_without_sensitive_data(): void
    {
        $transaction = $this->createBillableTransaction();
        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '300.00',
            'idempotency_key' => 'history-cash',
        ])->assertCreated();
        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '200.00',
            'reference_number' => 'HISTORY-GCASH',
            'idempotency_key' => 'history-gcash',
        ])->assertCreated();

        $this->getJson("/api/cashier/transactions/{$transaction->id}/payments")
            ->assertOk()
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.total_amount', '880.00')
            ->assertJsonPath('data.paid_amount', '500.00')
            ->assertJsonPath('data.remaining_balance', '380.00')
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.inventory')
            ->assertJsonMissingPath('data.kitchen_ticket');
    }

    public function test_paid_transaction_keeps_session_active_and_later_order_gets_new_transaction(): void
    {
        $transaction = $this->createBillableTransaction();
        $session = $transaction->tableSession;
        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '880.00',
            'idempotency_key' => 'settle-first-transaction',
        ])->assertCreated();

        $this->assertSame('active', $session->fresh()->status);

        $newOrder = $this->createOrderForSession($session, 100, 1);
        $this->postJson("/api/cashier/orders/{$newOrder->id}/confirm")
            ->assertOk();

        $newTransaction = $newOrder->fresh()->diningTransaction;
        $this->assertNotSame($transaction->id, $newTransaction->id);
        $this->assertSame('paid', $transaction->fresh()->status);
        $this->assertSame('open', $newTransaction->status);
        $this->assertSame(0, $newTransaction->payments()->count());
        $this->assertSame('active', $session->fresh()->status);
    }

    public function test_unknown_transaction_payment_routes_return_not_found(): void
    {
        $this->postJson('/api/cashier/transactions/999999/payments/cash', [
            'amount_received' => '100.00',
            'idempotency_key' => 'unknown-cash',
        ])->assertNotFound();

        $this->getJson('/api/cashier/transactions/999999/payments')
            ->assertNotFound();
    }

    private function createBillableTransaction(): DiningTransaction
    {
        $table = RestaurantTable::create([
            'table_number' => 'BILL-' . fake()->unique()->numberBetween(1, 9999),
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        $session = TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);
        $order = $this->createOrderForSession($session, 440, 2);

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertOk();

        return $order->fresh()->diningTransaction;
    }

    private function createOrderForSession(
        TableSession $session,
        int $unitPrice,
        int $quantity
    ): Order {
        $category = Category::create([
            'name' => 'Payment Category-' . fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
        ]);
        $menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Payment Item-' . fake()->unique()->numberBetween(1, 999999),
            'price' => $unitPrice,
            'is_available' => true,
        ]);
        Inventory::create([
            'menu_item_id' => $menuItem->id,
            'quantity' => 20,
            'low_stock_threshold' => 5,
        ]);
        $subtotal = $unitPrice * $quantity;
        $order = Order::create([
            'table_session_id' => $session->id,
            'order_number' => 'ORD-PAY-' . fake()->unique()->numberBetween(100000, 999999),
            'status' => 'pending',
            'subtotal' => $subtotal,
            'submitted_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
        ]);

        return $order->fresh()->load('items.menuItem.inventory');
    }

    private function createTransaction(): DiningTransaction
    {
        $table = RestaurantTable::create([
            'table_number' => 'PAY-' . fake()->unique()->numberBetween(1, 9999),
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        $session = TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);

        return DiningTransaction::create([
            'table_session_id' => $session->id,
            'transaction_number' => 'TXN-' . fake()->unique()->numberBetween(100000, 999999),
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }
}
