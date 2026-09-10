<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\KitchenTicket;
use App\Services\KitchenTicketService;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenTicketApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]));
    }
    use RefreshDatabase;

    public function test_kitchen_queue_lists_confirmed_and_preparing_orders(): void
    {
        $confirmed = $this->createOrder('confirmed');
        $preparing = $this->createOrder('preparing');

        $this->getJson('/api/kitchen-tickets')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $confirmed->id)
            ->assertJsonPath('data.0.status', 'confirmed')
            ->assertJsonPath('data.1.id', $preparing->id)
            ->assertJsonPath('data.1.status', 'preparing');
    }

    public function test_kitchen_queue_excludes_pending_rejected_and_completed_orders(): void
    {
        $confirmed = $this->createOrder('confirmed');
        $pending = $this->createOrder('pending');
        $rejected = $this->createOrder('rejected');
        $completed = $this->createOrder('completed');

        $this->getJson('/api/kitchen-tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $confirmed->id)
            ->assertJsonMissing(['id' => $pending->id])
            ->assertJsonMissing(['id' => $rejected->id])
            ->assertJsonMissing(['id' => $completed->id]);
    }

    public function test_kitchen_can_view_ticket_details(): void
    {
        $order = $this->createOrder('confirmed');
        $item = $order->items->first();

        $this->getJson("/api/kitchen-tickets/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.table_session.restaurant_table.table_number', $order->tableSession->restaurantTable->table_number)
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.menu_item.name', $item->menuItem->name)
            ->assertJsonPath('data.items.0.quantity', 2);
    }

    public function test_confirmed_order_can_be_marked_as_preparing(): void
    {
        $order = $this->createOrder('confirmed');

        $this->postJson("/api/kitchen-tickets/{$order->id}/prepare")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'preparing');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'preparing',
        ]);
    }

    public function test_preparing_order_can_be_marked_as_completed(): void
    {
        $order = $this->createOrder('preparing');

        $this->postJson("/api/kitchen-tickets/{$order->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
        ]);
    }

    public function test_pending_or_completed_orders_cannot_be_marked_as_preparing(): void
    {
        $pending = $this->createOrder('pending');
        $completed = $this->createOrder('completed');

        $this->postJson("/api/kitchen-tickets/{$pending->id}/prepare")
            ->assertStatus(409);

        $this->postJson("/api/kitchen-tickets/{$completed->id}/prepare")
            ->assertStatus(409);
    }

    public function test_only_preparing_orders_can_be_marked_as_completed(): void
    {
        $confirmed = $this->createOrder('confirmed');
        
        $this->postJson("/api/kitchen-tickets/{$confirmed->id}/complete")
            ->assertStatus(409);
    }

    public function test_confirming_a_pending_order_generates_exactly_one_permanent_ticket(): void
    {
        $order = $this->createOrder('pending');

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $ticket = KitchenTicket::where('order_id', $order->id)->first();

        $this->assertNotNull($ticket);
        $this->assertSame('generated', $ticket->status);
        $this->assertNotNull($ticket->generated_at);
        $this->assertNull($ticket->printed_at);
        $this->assertSame(0, $ticket->print_count);
        $this->assertSame(1, KitchenTicket::where('order_id', $order->id)->count());
    }

    public function test_pending_order_cannot_generate_a_ticket(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(KitchenTicketService::class)->generateForOrder(
            $this->createOrder('pending')
        );
    }

    public function test_rejected_order_cannot_generate_a_ticket(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(KitchenTicketService::class)->generateForOrder(
            $this->createOrder('rejected')
        );
    }

    public function test_generating_a_ticket_twice_reuses_the_same_record(): void
    {
        $order = $this->createOrder('confirmed');
        $service = app(KitchenTicketService::class);

        $first = $service->generateForOrder($order);
        $second = $service->generateForOrder($order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KitchenTicket::where('order_id', $order->id)->count());
    }

    public function test_kitchen_ticket_numbers_are_unique_and_human_readable(): void
    {
        $service = app(KitchenTicketService::class);
        $first = $service->generateForOrder($this->createOrder('confirmed'));
        $second = $service->generateForOrder($this->createOrder('confirmed'));

        $this->assertNotSame($first->ticket_number, $second->ticket_number);
        $this->assertMatchesRegularExpression('/^KOT-\\d{8}-[A-Z0-9]{6}$/', $first->ticket_number);
        $this->assertMatchesRegularExpression('/^KOT-\\d{8}-[A-Z0-9]{6}$/', $second->ticket_number);
    }

    public function test_kitchen_ticket_view_exposes_kitchen_fields_without_prices(): void
    {
        $order = $this->createOrder('pending');
        $this->postJson("/api/cashier/orders/{$order->id}/confirm")->assertOk();
        $ticket = KitchenTicket::where('order_id', $order->id)->firstOrFail();

        $this->getJson("/api/kitchen-order-slips/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.ticket_number', $ticket->ticket_number)
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.table_number', $order->tableSession->restaurantTable->table_number)
            ->assertJsonPath('data.customer_note', $order->customer_note)
            ->assertJsonPath('data.items.0.menu_item_name', $order->items->first()->menuItem->name)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.special_instruction', 'No sugar')
            ->assertJsonPath('data.status', 'generated')
            ->assertJsonPath('data.print_count', 0)
            ->assertJsonMissingPath('data.items.0.unit_price')
            ->assertJsonMissingPath('data.items.0.subtotal')
            ->assertJsonMissingPath('data.subtotal');
    }

    public function test_unknown_kitchen_ticket_returns_not_found(): void
    {
        $this->getJson('/api/kitchen-order-slips/999999')
            ->assertNotFound();
    }

    public function test_first_and_repeated_prints_update_the_same_ticket(): void
    {
        $order = $this->createOrder('pending');
        $this->postJson("/api/cashier/orders/{$order->id}/confirm")->assertOk();
        $ticket = KitchenTicket::where('order_id', $order->id)->firstOrFail();

        $this->postJson("/api/kitchen-order-slips/{$ticket->id}/printed")
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.status', 'printed')
            ->assertJsonPath('data.print_count', 1);

        $this->postJson("/api/kitchen-order-slips/{$ticket->id}/printed")
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.status', 'printed')
            ->assertJsonPath('data.print_count', 2);

        $this->assertSame(1, KitchenTicket::count());
        $this->assertNotNull($ticket->fresh()->printed_at);
    }

    public function test_rejected_order_does_not_generate_a_ticket(): void
    {
        $order = $this->createOrder('pending');

        $this->postJson("/api/cashier/orders/{$order->id}/reject")
            ->assertOk();

        $this->assertDatabaseCount('kitchen_tickets', 0);
    }

    public function test_repeated_confirmation_does_not_create_another_ticket(): void
    {
        $order = $this->createOrder('pending');

        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertOk();
        $this->postJson("/api/cashier/orders/{$order->id}/confirm")
            ->assertStatus(409);

        $this->assertDatabaseCount('kitchen_tickets', 1);
    }

    public function test_ticket_creation_failure_rolls_back_confirmation_and_inventory(): void
    {
        $order = $this->createOrder('pending', stock: 5);
        $inventory = $order->items->first()->menuItem->inventory;

        KitchenTicket::creating(function (): void {
            throw new \RuntimeException('Ticket creation failed');
        });

        try {
            $this->withoutExceptionHandling()
                ->postJson("/api/cashier/orders/{$order->id}/confirm");
        } catch (\RuntimeException $exception) {
            $this->assertSame('Ticket creation failed', $exception->getMessage());
        } finally {
            KitchenTicket::flushEventListeners();
        }

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
        ]);
        $this->assertSame(5, $inventory->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('kitchen_tickets', 0);
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
            'customer_note' => 'Table note',
            'submitted_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 2,
            'unit_price' => 130,
            'subtotal' => 260,
            'special_instruction' => 'No sugar',
        ]);

        return $order->fresh()->load([
            'tableSession.restaurantTable',
            'items.menuItem',
        ]);
    }
}
