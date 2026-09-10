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
use App\Models\Receipt;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use App\Services\ReceiptService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReceiptApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]));
    }
    use RefreshDatabase;

    public function test_receipt_schema_exists_with_print_metadata_and_snapshot(): void
    {
        $this->assertTrue(Schema::hasColumns('receipts', [
            'id',
            'dining_transaction_id',
            'receipt_number',
            'status',
            'snapshot',
            'generated_at',
            'printed_at',
            'print_count',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_receipt_relationships_and_snapshot_cast_work(): void
    {
        $transaction = $this->createTransaction('paid');
        $receipt = Receipt::create([
            'dining_transaction_id' => $transaction->id,
            'receipt_number' => 'RCT-20260908-ABC123',
            'status' => 'generated',
            'snapshot' => ['total_amount' => '100.00'],
            'generated_at' => now(),
            'print_count' => 0,
        ]);

        $this->assertTrue($receipt->diningTransaction->is($transaction));
        $this->assertTrue($transaction->receipt->is($receipt));
        $this->assertSame(['total_amount' => '100.00'], $receipt->snapshot);
        $this->assertNull($receipt->printed_at);
        $this->assertSame(0, $receipt->print_count);
    }

    public function test_receipt_number_and_transaction_are_unique(): void
    {
        $transaction = $this->createTransaction('paid');
        $attributes = [
            'dining_transaction_id' => $transaction->id,
            'receipt_number' => 'RCT-20260908-UNIQUE',
            'status' => 'generated',
            'snapshot' => ['total_amount' => '100.00'],
            'generated_at' => now(),
            'print_count' => 0,
        ];

        Receipt::create($attributes);
        $attributes['receipt_number'] = 'RCT-20260908-SECOND';

        $this->expectException(QueryException::class);
        Receipt::create($attributes);
    }

    public function test_receipt_number_is_unique_across_transactions(): void
    {
        $first = $this->createTransaction('paid');
        $second = $this->createTransaction('paid');
        $attributes = [
            'receipt_number' => 'RCT-20260908-GLOBAL',
            'status' => 'generated',
            'snapshot' => ['total_amount' => '100.00'],
            'generated_at' => now(),
            'print_count' => 0,
        ];

        Receipt::create($attributes + ['dining_transaction_id' => $first->id]);

        $this->expectException(QueryException::class);
        Receipt::create($attributes + ['dining_transaction_id' => $second->id]);
    }

    public function test_paid_status_without_consistent_payments_cannot_generate_receipt(): void
    {
        $transaction = $this->createTransaction('paid');

        $this->expectException(ValidationException::class);
        app(ReceiptService::class)->generateForTransaction($transaction);
    }

    public function test_open_and_partially_paid_transactions_cannot_generate_final_receipts(): void
    {
        foreach (['open', 'partially_paid'] as $status) {
            try {
                app(ReceiptService::class)->generateForTransaction(
                    $this->createTransaction($status)
                );
                $this->fail("{$status} transaction unexpectedly generated a receipt.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_final_cash_payment_generates_one_receipt_with_authoritative_snapshot(): void
    {
        $transaction = $this->createBillableTransaction();

        $response = $this->postJson(
            "/api/cashier/transactions/{$transaction->id}/payments/cash",
            [
                'amount_received' => '1000.00',
                'idempotency_key' => 'receipt-cash-final',
            ]
        )
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'paid')
            ->assertJsonStructure(['data' => ['receipt' => ['id', 'receipt_number', 'status']]]);

        $receipt = Receipt::where('dining_transaction_id', $transaction->id)->first();
        $this->assertNotNull($receipt);
        $this->assertMatchesRegularExpression('/^RCT-\\d{8}-[A-Z0-9]{6}$/', $receipt->receipt_number);
        $this->assertSame('generated', $receipt->status);
        $this->assertNotNull($receipt->generated_at);
        $this->assertNull($receipt->printed_at);
        $this->assertSame(0, $receipt->print_count);
        $this->assertSame('880.00', $receipt->snapshot['total_amount']);
        $this->assertSame('880.00', $receipt->snapshot['paid_amount']);
        $this->assertSame('0.00', $receipt->snapshot['remaining_balance']);
        $this->assertSame($receipt->id, $response->json('data.receipt.id'));
    }

    public function test_final_gcash_payment_generates_one_receipt(): void
    {
        $transaction = $this->createBillableTransaction();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '880.00',
            'reference_number' => 'RECEIPT-GCASH-1',
            'idempotency_key' => 'receipt-gcash-final',
        ])
            ->assertCreated()
            ->assertJsonPath('data.receipt.status', 'generated');

        $this->assertDatabaseCount('receipts', 1);
        $this->assertSame(
            'RECEIPT-GCASH-1',
            Receipt::firstOrFail()->snapshot['payments'][0]['reference_number']
        );
    }

    public function test_partial_payment_creates_no_receipt_and_final_mixed_payment_snapshots_all_payments(): void
    {
        $transaction = $this->createBillableTransaction();

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '400.00',
            'idempotency_key' => 'receipt-mixed-cash',
        ])->assertCreated()->assertJsonPath('data.receipt', null);
        $this->assertDatabaseCount('receipts', 0);

        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/gcash", [
            'amount' => '480.00',
            'reference_number' => 'RECEIPT-MIXED-GCASH',
            'idempotency_key' => 'receipt-mixed-gcash',
        ])->assertCreated()->assertJsonPath('data.receipt.status', 'generated');

        $receipt = Receipt::firstOrFail();
        $this->assertCount(2, $receipt->snapshot['payments']);
        $this->assertSame('cash', $receipt->snapshot['payments'][0]['payment_method']);
        $this->assertSame('gcash', $receipt->snapshot['payments'][1]['payment_method']);
        $this->assertSame('880.00', $receipt->snapshot['paid_amount']);
    }

    public function test_receipt_generation_is_idempotent(): void
    {
        $transaction = $this->createAndPayTransaction();
        $service = app(ReceiptService::class);

        $first = $service->generateForTransaction($transaction->fresh());
        $second = $service->generateForTransaction($transaction->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('receipts', 1);
    }

    public function test_existing_receipt_does_not_bypass_settlement_consistency_validation(): void
    {
        $transaction = $this->createAndPayTransaction();
        $transaction->payments()->update(['status' => 'invalidated']);

        $this->expectException(ValidationException::class);
        app(ReceiptService::class)->generateForTransaction($transaction->fresh());
    }

    public function test_receipt_creation_failure_rolls_back_final_payment_and_paid_state(): void
    {
        $transaction = $this->createBillableTransaction();
        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '400.00',
            'idempotency_key' => 'receipt-rollback-partial',
        ])->assertCreated();

        Receipt::creating(function (): void {
            throw new \RuntimeException('Receipt creation failed');
        });

        try {
            $this->withoutExceptionHandling()->postJson(
                "/api/cashier/transactions/{$transaction->id}/payments/cash",
                [
                    'amount_received' => '480.00',
                    'idempotency_key' => 'receipt-rollback-final',
                ]
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame('Receipt creation failed', $exception->getMessage());
        } finally {
            Receipt::flushEventListeners();
        }

        $this->assertSame('partially_paid', $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->paid_at);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_receipt_view_uses_snapshot_and_excludes_internal_data(): void
    {
        $transaction = $this->createAndPayTransaction();
        $receipt = Receipt::where('dining_transaction_id', $transaction->id)->firstOrFail();
        $originalSnapshot = $receipt->snapshot;

        $transaction->orders->first()->items->first()->menuItem->update(['price' => 999]);

        $this->getJson("/api/receipts/{$receipt->id}")
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $receipt->receipt_number)
            ->assertJsonPath('data.transaction_number', $transaction->transaction_number)
            ->assertJsonPath('data.restaurant_name', 'Pass-over Cafe')
            ->assertJsonPath('data.items.0.unit_price', '440.00')
            ->assertJsonPath('data.items.0.subtotal', '880.00')
            ->assertJsonPath('data.total_amount', '880.00')
            ->assertJsonPath('data.payments.0.payment_method', 'cash')
            ->assertJsonPath('data.payments.0.amount_received', '880.00')
            ->assertJsonMissingPath('data.inventory')
            ->assertJsonMissingPath('data.kitchen_ticket')
            ->assertJsonMissingPath('data.password');

        $this->assertSame($originalSnapshot, $receipt->fresh()->snapshot);
    }

    public function test_unknown_receipt_returns_not_found(): void
    {
        $this->getJson('/api/receipts/999999')->assertNotFound();
    }

    public function test_printing_and_reprinting_update_only_receipt_metadata(): void
    {
        $transaction = $this->createAndPayTransaction();
        $receipt = Receipt::where('dining_transaction_id', $transaction->id)->firstOrFail();
        $payment = $transaction->payments->first();
        $inventoryQuantity = Inventory::sum('quantity');
        $movementCount = InventoryMovement::count();
        $ticket = KitchenTicket::firstOrFail();
        $ticketPrintCount = $ticket->print_count;

        $printTimes = [
            Carbon::parse('2026-09-08 10:00:00'),
            Carbon::parse('2026-09-08 10:01:00'),
            Carbon::parse('2026-09-08 10:02:00'),
        ];

        foreach ([1, 2, 3] as $index => $expectedCount) {
            Carbon::setTestNow($printTimes[$index]);
            $this->postJson("/api/receipts/{$receipt->id}/printed")
                ->assertOk()
                ->assertJsonPath('data.id', $receipt->id)
                ->assertJsonPath('data.status', 'printed')
                ->assertJsonPath('data.print_count', $expectedCount);
        }
        Carbon::setTestNow();

        $this->assertDatabaseCount('receipts', 1);
        $this->assertTrue(
            $receipt->fresh()->printed_at->equalTo($printTimes[2])
        );
        $this->assertSame('880.00', $payment->fresh()->amount);
        $this->assertSame('paid', $transaction->fresh()->status);
        $this->assertSame($inventoryQuantity, Inventory::sum('quantity'));
        $this->assertSame($movementCount, InventoryMovement::count());
        $this->assertSame($ticketPrintCount, $ticket->fresh()->print_count);
        $this->assertSame('active', $transaction->tableSession->fresh()->status);
    }

    public function test_identical_final_payment_retry_reuses_receipt(): void
    {
        $transaction = $this->createBillableTransaction();
        $payload = [
            'amount_received' => '880.00',
            'idempotency_key' => 'receipt-payment-retry',
        ];

        $first = $this->postJson(
            "/api/cashier/transactions/{$transaction->id}/payments/cash",
            $payload
        )->assertCreated()->json('data.receipt.id');
        $second = $this->postJson(
            "/api/cashier/transactions/{$transaction->id}/payments/cash",
            $payload
        )->assertOk()->json('data.receipt.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('receipts', 1);
    }

    private function createAndPayTransaction(): DiningTransaction
    {
        $transaction = $this->createBillableTransaction();
        $this->postJson("/api/cashier/transactions/{$transaction->id}/payments/cash", [
            'amount_received' => '880.00',
            'idempotency_key' => 'receipt-helper-payment-'.$transaction->id,
        ])->assertCreated();

        return $transaction->fresh()->load([
            'tableSession.restaurantTable',
            'orders.items.menuItem',
            'payments',
        ]);
    }

    private function createBillableTransaction(): DiningTransaction
    {
        $table = RestaurantTable::create([
            'table_number' => 'RCPT-'.fake()->unique()->numberBetween(1, 9999),
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        $session = TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);
        $category = Category::create([
            'name' => 'Receipt Category-'.fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
        ]);
        $menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Receipt Item-'.fake()->unique()->numberBetween(1, 999999),
            'price' => 440,
            'is_available' => true,
        ]);
        Inventory::create([
            'menu_item_id' => $menuItem->id,
            'quantity' => 20,
            'low_stock_threshold' => 5,
        ]);
        $order = Order::create([
            'table_session_id' => $session->id,
            'order_number' => 'ORD-RCT-'.fake()->unique()->numberBetween(100000, 999999),
            'status' => 'pending',
            'subtotal' => 880,
            'submitted_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 2,
            'unit_price' => 440,
            'subtotal' => 880,
        ]);

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")->assertOk();

        return $order->fresh()->diningTransaction;
    }

    private function createTransaction(string $status): DiningTransaction
    {
        $table = RestaurantTable::create([
            'table_number' => 'RCT-'.fake()->unique()->numberBetween(1, 9999),
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        $session = TableSession::create([
            'restaurant_table_id' => $table->id,
            'status' => 'active',
        ]);

        return DiningTransaction::create([
            'table_session_id' => $session->id,
            'transaction_number' => 'TXN-RCT-'.fake()->unique()->numberBetween(100000, 999999),
            'status' => $status,
            'opened_at' => now(),
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }
}
