<?php

namespace Tests\Performance;

use App\Models\Category;
use App\Models\DiningTransaction;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Run explicitly: php vendor/bin/phpunit tests/Performance */
class ServicePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_representative_local_service_response_budgets(): void
    {
        Http::preventStrayRequests();
        $cashier = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $category = Category::create(['name' => 'Performance menu', 'is_active' => true]);
        $table = RestaurantTable::create(['table_number' => 'Performance 1', 'capacity' => 4]);
        for ($i = 0; $i < 100; $i++) {
            $item = MenuItem::create(['category_id' => $category->id, 'name' => "Item {$i}", 'price' => 100, 'is_available' => true]);
            Inventory::create(['menu_item_id' => $item->id, 'quantity' => 1000, 'low_stock_threshold' => 5]);
        }
        $session = $table->sessions()->create(['status' => 'active']);
        // Include history so reports and dashboards are not measured on empty tables.
        for ($i = 0; $i < 200; $i++) {
            $transaction = DiningTransaction::create(['table_session_id' => $session->id, 'transaction_number' => "PERF-T-{$i}", 'status' => 'paid', 'opened_at' => now(), 'paid_at' => now()]);
            $order = $session->orders()->create(['dining_transaction_id' => $transaction->id, 'order_number' => "PERF-O-{$i}", 'status' => 'completed', 'subtotal' => 100]);
            $order->items()->create(['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
        }

        $timings = [];
        $measure = function (string $operation, int $budget, callable $request) use (&$timings) {
            $start = hrtime(true);
            $response = $request();
            $response->assertSuccessful();
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $timings[$operation]['samples_ms'][] = round($elapsed, 2);
            $timings[$operation]['budget_ms'] = $budget;

            return $response;
        };

        for ($i = 0; $i < 8; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)]);
            $measure('login', 2000, fn () => $this->postJson('/api/login', ['email' => $cashier->email, 'password' => 'password']));
            $measure('menu', 2000, fn () => $this->getJson('/api/menu-items'));
            $response = $measure('submit', 3000, fn () => $this->postJson("/api/customer/tables/{$table->qr_token}/orders", ['items' => [['menu_item_id' => $item->id, 'quantity' => 1]]]));
            $id = $response->json('data.id');
            $measure('confirm_and_inventory', 3000, fn () => $this->postJson("/api/cashier/orders/{$id}/confirm"));
            $measure('prepare', 3000, fn () => $this->postJson("/api/kitchen-tickets/{$id}/prepare"));
            $measure('complete', 3000, fn () => $this->postJson("/api/kitchen-tickets/{$id}/complete"));
            $order = Order::findOrFail($id);
            $measure('status', 3000, fn () => $this->getJson("/api/customer/orders/{$order->tracking_token}/status"));
            $measure('bill', 2000, fn () => $this->getJson("/api/cashier/transactions/{$order->dining_transaction_id}/bill"));
            $measure('payment_and_receipt', 2000, fn () => $this->postJson("/api/cashier/transactions/{$order->dining_transaction_id}/payments/cash", ['amount_received' => 100, 'idempotency_key' => "performance-payment-{$i}"]));
            $measure('dashboard', 4000, fn () => $this->getJson('/api/cashier/dashboard'));
            $measure('sales_report', 2000, fn () => $this->getJson('/api/cashier/reports/sales'));
            $measure('local_best_sellers', 5000, fn () => $this->postJson('/api/customer/chatbot', ['question' => 'Best sellers']));
            $this->postJson('/api/logout')->assertSuccessful();
        }
        $summary = [];
        foreach ($timings as $operation => $values) {
            sort($values['samples_ms']);
            $p95 = $values['samples_ms'][(int) ceil(count($values['samples_ms']) * .95) - 1];
            $summary[$operation] = ['samples' => count($values['samples_ms']), 'p95_ms' => $p95, 'budget_ms' => $values['budget_ms']];
            $this->assertLessThan($values['budget_ms'], $p95, "{$operation} exceeded its local response budget");
        }
        fwrite(STDERR, "\n".json_encode(['environment' => 'Local PHP HTTP-kernel requests, '.DB::connection()->getDriverName().'; 100 menu items, 200 historical orders; sequential samples. External AI, LAN and printer latency excluded.', 'performance' => $summary], JSON_PRETTY_PRINT)."\n");
    }
}
