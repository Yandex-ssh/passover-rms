<?php

namespace App\Services;

use App\Models\DiningTransaction;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const ACCEPTED_ORDER_STATUSES = ['confirmed', 'preparing', 'completed'];

    private const DEFAULT_LOW_STOCK_LIMIT = 5;

    public function summary(?int $lowStockLimit = null): array
    {
        $today = Carbon::now('UTC')->toDateString();
        $from = Carbon::createFromFormat('Y-m-d', $today, 'UTC')->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $today, 'UTC')->endOfDay();
        $limit = $lowStockLimit ?? self::DEFAULT_LOW_STOCK_LIMIT;

        $paidTransactions = DiningTransaction::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to]);
        $transactionIds = (clone $paidTransactions)->select('id');
        $salesCents = $this->cents(OrderItem::query()
            ->whereHas('order', fn ($query) => $query->whereIn('status', self::ACCEPTED_ORDER_STATUSES)->whereIn('dining_transaction_id', $transactionIds))
            ->sum('subtotal'));

        $paymentTotals = Payment::query()
            ->where('status', 'completed')
            ->whereBetween('paid_at', [$from, $to])
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $orderCounts = Order::query()->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
        $transactionCounts = DiningTransaction::query()->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
        $inventoryQuery = Inventory::query();
        $inventoryCounts = [
            'tracked_items' => (clone $inventoryQuery)->count(),
            'low_stock' => (clone $inventoryQuery)->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'low_stock_threshold')->count(),
            'out_of_stock' => (clone $inventoryQuery)->where('quantity', 0)->count(),
        ];
        $lowStockItems = (clone $inventoryQuery)->with('menuItem')->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'low_stock_threshold')->orderBy('quantity')->orderBy('id')->limit($limit)->get()->map(fn ($inventory) => [
            'menu_item_id' => $inventory->menu_item_id,
            'name' => $inventory->menuItem->name,
            'quantity' => $inventory->quantity,
            'low_stock_threshold' => $inventory->low_stock_threshold,
        ])->values()->all();

        return [
            'period' => ['date' => $today, 'timezone' => 'UTC'],
            'sales' => [
                'total' => $this->money($salesCents),
                'paid_transactions' => (clone $paidTransactions)->count(),
                'cash' => $this->money($this->cents($paymentTotals->get('cash', 0))),
                'gcash' => $this->money($this->cents($paymentTotals->get('gcash', 0))),
            ],
            'orders' => [
                'pending' => (int) ($orderCounts->get('pending', 0)),
                'confirmed' => (int) ($orderCounts->get('confirmed', 0)),
                'preparing' => (int) ($orderCounts->get('preparing', 0)),
                'completed' => (int) ($orderCounts->get('completed', 0)),
                'rejected' => (int) ($orderCounts->get('rejected', 0)),
            ],
            'transactions' => [
                'open' => (int) ($transactionCounts->get('open', 0)),
                'partially_paid' => (int) ($transactionCounts->get('partially_paid', 0)),
                'paid_today' => (clone $paidTransactions)->count(),
            ],
            'payments' => [
                'count_today' => Payment::query()->where('status', 'completed')->whereBetween('paid_at', [$from, $to])->count(),
                'cash_applied' => $this->money($this->cents($paymentTotals->get('cash', 0))),
                'gcash_applied' => $this->money($this->cents($paymentTotals->get('gcash', 0))),
            ],
            'inventory' => $inventoryCounts,
            'menu' => [
                'available' => MenuItem::query()->where('is_available', true)->count(),
                'unavailable' => MenuItem::query()->where('is_available', false)->count(),
                'active_categories' => DB::table('categories')->where('is_active', true)->count(),
            ],
            'low_stock_items' => $lowStockItems,
        ];
    }

    private function cents($value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
