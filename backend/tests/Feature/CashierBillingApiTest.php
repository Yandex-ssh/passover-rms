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
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashierBillingApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]));
    }
    use RefreshDatabase;

    public function test_dining_transaction_schema_and_nullable_order_relationship_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('dining_transactions', [
            'id',
            'table_session_id',
            'transaction_number',
            'status',
            'opened_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('orders', 'dining_transaction_id'));

        $order = $this->createOrder();
        $this->assertNull($order->dining_transaction_id);
    }

    public function test_transaction_number_is_unique(): void
    {
        $order = $this->createOrder();
        $attributes = [
            'table_session_id' => $order->table_session_id,
            'transaction_number' => 'TXN-20260907-UNIQUE',
            'status' => 'open',
            'opened_at' => now(),
        ];

        DiningTransaction::create($attributes);

        $this->expectException(QueryException::class);
        DiningTransaction::create($attributes);
    }

    public function test_dining_transaction_model_relationships_work(): void
    {
        $order = $this->createOrder();
        $transaction = DiningTransaction::create([
            'table_session_id' => $order->table_session_id,
            'transaction_number' => 'TXN-20260907-ABC123',
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $order->update(['dining_transaction_id' => $transaction->id]);

        $this->assertTrue($transaction->tableSession->is($order->tableSession));
        $this->assertTrue($order->tableSession->diningTransactions->contains($transaction));
        $this->assertTrue($transaction->orders->contains($order));
        $this->assertTrue($order->fresh()->diningTransaction->is($transaction));
    }

    public function test_first_successful_confirmation_creates_and_attaches_an_open_transaction(): void
    {
        $order = $this->createOrder();
        $this->assertNull($order->dining_transaction_id);

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.dining_transaction.status', 'open')
            ->assertJsonStructure([
                'data' => [
                    'dining_transaction' => [
                        'id',
                        'transaction_number',
                        'status',
                    ],
                ],
            ]);

        $order = $order->fresh();
        $transaction = $order->diningTransaction;

        $this->assertNotNull($transaction);
        $this->assertSame($order->table_session_id, $transaction->table_session_id);
        $this->assertSame('open', $transaction->status);
        $this->assertNotNull($transaction->opened_at);
        $this->assertMatchesRegularExpression(
            '/^TXN-\\d{8}-[A-Z0-9]{6}$/',
            $transaction->transaction_number
        );
        $this->assertDatabaseCount('dining_transactions', 1);
        $this->assertDatabaseHas('kitchen_tickets', ['order_id' => $order->id]);
    }

    public function test_additional_orders_for_the_same_session_join_the_same_open_transaction(): void
    {
        $first = $this->createOrder();
        $session = $first->tableSession;
        $second = $this->createOrder($session);
        $third = $this->createOrder($session);

        foreach ([$first, $second, $third] as $order) {
            $this->postJson("/api/cashier/orders/{$order->id}/confirm")
                ->assertOk();
        }

        $transactionIds = collect([$first, $second, $third])
            ->map(fn (Order $order) => $order->fresh()->dining_transaction_id)
            ->unique();

        $this->assertCount(1, $transactionIds);
        $this->assertDatabaseCount('dining_transactions', 1);
        $this->assertDatabaseCount('kitchen_tickets', 3);
    }

    public function test_orders_from_different_table_sessions_use_different_transactions(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();

        $this->postJson("/api/cashier/orders/{$first->id}/confirm")->assertOk();
        $this->postJson("/api/cashier/orders/{$second->id}/confirm")->assertOk();

        $this->assertNotSame(
            $first->fresh()->dining_transaction_id,
            $second->fresh()->dining_transaction_id
        );
        $this->assertDatabaseCount('dining_transactions', 2);
    }

    public function test_failed_confirmation_does_not_create_or_attach_a_transaction(): void
    {
        $order = $this->createOrder(stock: 1, quantity: 2);

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertUnprocessable();

        $this->assertNull($order->fresh()->dining_transaction_id);
        $this->assertDatabaseCount('dining_transactions', 0);
        $this->assertDatabaseCount('kitchen_tickets', 0);
    }

    public function test_rejected_order_remains_unattached(): void
    {
        $order = $this->createOrder();

        $this->postJson("/api/cashier/orders/{$order->id}/reject")
            ->assertOk();

        $this->assertNull($order->fresh()->dining_transaction_id);
        $this->assertDatabaseCount('dining_transactions', 0);
    }

    public function test_repeated_confirmation_does_not_duplicate_the_transaction_or_ticket(): void
    {
        $order = $this->createOrder();

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertStatus(409);

        $this->assertDatabaseCount('dining_transactions', 1);
        $this->assertDatabaseCount('kitchen_tickets', 1);
    }

    public function test_transaction_creation_failure_rolls_back_confirmation_inventory_and_ticket(): void
    {
        $order = $this->createOrder(stock: 5, quantity: 2);
        $inventory = $order->items->first()->menuItem->inventory;

        DiningTransaction::creating(function (): void {
            throw new \RuntimeException('Transaction creation failed');
        });

        try {
            $this->withoutExceptionHandling()
                ->postJson("/api/cashier/orders/{$order->id}/confirm");
        } catch (\RuntimeException $exception) {
            $this->assertSame('Transaction creation failed', $exception->getMessage());
        } finally {
            DiningTransaction::flushEventListeners();
        }

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->dining_transaction_id);
        $this->assertSame(5, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('kitchen_tickets', 0);
        $this->assertDatabaseCount('dining_transactions', 0);
    }

    public function test_combined_bill_uses_historical_item_prices_and_backend_totals(): void
    {
        $first = $this->createOrder(unitPrice: 120, quantity: 2);
        $session = $first->tableSession;
        $second = $this->createOrder($session, unitPrice: 180, quantity: 1);

        $this->postJson("/api/cashier/orders/{$first->id}/confirm")->assertOk();
        $this->postJson("/api/cashier/orders/{$second->id}/confirm")->assertOk();

        $first->items->first()->menuItem->update(['price' => 150]);
        $second->items->first()->menuItem->update(['price' => 210]);
        $transaction = $first->fresh()->diningTransaction;

        $this->getJson("/api/cashier/transactions/{$transaction->id}/bill?subtotal=1&total_amount=1")
            ->assertOk()
            ->assertJsonPath('data.transaction_number', $transaction->transaction_number)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.table_session.id', $session->id)
            ->assertJsonPath('data.table.id', $session->restaurant_table_id)
            ->assertJsonPath('data.table.table_number', $session->restaurantTable->table_number)
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.orders.0.order_number', $first->order_number)
            ->assertJsonPath('data.orders.0.items.0.quantity', 2)
            ->assertJsonPath('data.orders.0.items.0.unit_price', '120.00')
            ->assertJsonPath('data.orders.0.items.0.subtotal', '240.00')
            ->assertJsonPath('data.orders.1.order_number', $second->order_number)
            ->assertJsonPath('data.orders.1.items.0.unit_price', '180.00')
            ->assertJsonPath('data.orders.1.items.0.subtotal', '180.00')
            ->assertJsonPath('data.subtotal', '420.00')
            ->assertJsonPath('data.total_amount', '420.00')
            ->assertJsonMissingPath('data.payment')
            ->assertJsonMissingPath('data.kitchen_ticket')
            ->assertJsonMissingPath('data.inventory');
    }

    public function test_bill_excludes_pending_and_rejected_orders(): void
    {
        $confirmed = $this->createOrder();
        $session = $confirmed->tableSession;
        $this->postJson("/api/cashier/orders/{$confirmed->id}/confirm")->assertOk();
        $transaction = $confirmed->fresh()->diningTransaction;

        $pending = $this->createOrder($session, status: 'pending', unitPrice: 500, quantity: 1);
        $rejected = $this->createOrder($session, status: 'rejected', unitPrice: 600, quantity: 1);
        $pending->update(['dining_transaction_id' => $transaction->id]);
        $rejected->update(['dining_transaction_id' => $transaction->id]);

        $this->getJson("/api/cashier/transactions/{$transaction->id}/bill")
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.order_number', $confirmed->order_number)
            ->assertJsonPath('data.subtotal', '240.00');
    }

    public function test_preparing_and_completed_orders_remain_billable(): void
    {
        $preparing = $this->createOrder();
        $session = $preparing->tableSession;
        $completed = $this->createOrder($session);

        $this->postJson("/api/cashier/orders/{$preparing->id}/confirm")->assertOk();
        $this->postJson("/api/cashier/orders/{$completed->id}/confirm")->assertOk();
        $preparing->update(['status' => 'preparing']);
        $completed->update(['status' => 'completed']);
        $transaction = $preparing->fresh()->diningTransaction;

        $this->getJson("/api/cashier/transactions/{$transaction->id}/bill")
            ->assertOk()
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.subtotal', '480.00');
    }

    public function test_bill_retrieval_is_idempotent_and_keeps_the_table_session_active(): void
    {
        $order = $this->createOrder();
        $this->postJson("/api/cashier/orders/{$order->id}/confirm")->assertOk();
        $transaction = $order->fresh()->diningTransaction;
        $countsBefore = [
            DiningTransaction::count(),
            Order::count(),
            KitchenTicket::count(),
            InventoryMovement::count(),
        ];

        $first = $this->getJson("/api/cashier/transactions/{$transaction->id}/bill")
            ->assertOk()
            ->json('data');
        $second = $this->getJson("/api/cashier/transactions/{$transaction->id}/bill")
            ->assertOk()
            ->json('data');

        $this->assertSame($first, $second);
        $this->assertSame($countsBefore, [
            DiningTransaction::count(),
            Order::count(),
            KitchenTicket::count(),
            InventoryMovement::count(),
        ]);
        $this->assertSame('active', $order->tableSession->fresh()->status);
        $this->assertSame('open', $transaction->fresh()->status);
    }

    public function test_unknown_dining_transaction_returns_not_found(): void
    {
        $this->getJson('/api/cashier/transactions/999999/bill')
            ->assertNotFound();
    }

    private function createOrder(
        ?TableSession $session = null,
        string $status = 'pending',
        int $unitPrice = 120,
        int $quantity = 2,
        int $stock = 20
    ): Order
    {
        if (! $session) {
            $table = RestaurantTable::create([
                'table_number' => 'T-' . fake()->unique()->numberBetween(1, 9999),
                'capacity' => 4,
                'status' => 'occupied',
            ]);
            $session = TableSession::create([
                'restaurant_table_id' => $table->id,
                'status' => 'active',
            ]);
        }

        $category = Category::create([
            'name' => 'Category-' . fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
        ]);
        $menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Item-' . fake()->unique()->numberBetween(1, 999999),
            'price' => $unitPrice,
            'is_available' => true,
        ]);
        Inventory::create([
            'menu_item_id' => $menuItem->id,
            'quantity' => $stock,
            'low_stock_threshold' => 5,
        ]);
        $subtotal = $unitPrice * $quantity;
        $order = Order::create([
            'table_session_id' => $session->id,
            'order_number' => 'ORD-' . fake()->unique()->numberBetween(100000, 999999),
            'status' => $status,
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

        return $order->fresh()->load([
            'tableSession.restaurantTable',
            'items.menuItem.inventory',
        ]);
    }
}
