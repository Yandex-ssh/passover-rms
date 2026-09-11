<?php

namespace App\Services;

use App\Models\DiningTransaction;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ReportingService
{
    public function __construct(private readonly BestSellingService $bestSelling) {}

    private const ACCEPTED_ORDER_STATUSES = ['confirmed', 'preparing', 'completed'];

    public function sales(?string $from, ?string $to): array
    {
        $transactions = $this->paidTransactions($from, $to);
        $ids = $transactions->pluck('id');
        $items = $this->acceptedItems()->whereHas('order', fn ($q) => $q->whereIn('dining_transaction_id', $ids))->get();
        $totalCents = $items->sum(fn ($item) => $this->cents($item->subtotal));
        $quantity = (int) $items->sum('quantity');
        $payments = Payment::query()->whereIn('dining_transaction_id', $ids)->where('status', 'completed')->get();
        $methods = [];
        foreach ($payments as $payment) {
            $methods[$payment->payment_method] = ($methods[$payment->payment_method] ?? 0) + $this->cents($payment->amount);
        }
        $count = $transactions->count();
        $salesByTransaction = $items->groupBy('order.dining_transaction_id')
            ->map(fn ($lines) => $lines->sum(fn ($line) => $this->cents($line->subtotal)));
        $dailySales = $transactions->groupBy(fn ($transaction) => $transaction->paid_at->utc()->toDateString())
            ->map(fn ($rows, $date) => ['date' => $date, 'total' => $this->money($rows->sum(fn ($row) => $salesByTransaction->get($row->id, 0)))])
            ->sortKeys()->values()->all();

        return [
            'from' => $from,
            'to' => $to,
            'transaction_count' => $count,
            'item_quantity' => $quantity,
            'total_sales' => $this->money($totalCents),
            'average_transaction_value' => $this->money($count ? intdiv($totalCents, $count) : 0),
            'payment_methods' => collect($methods)->map(fn ($value) => $this->money($value))->all(),
            'daily_sales' => $dailySales,
        ];
    }

    public function transactions(array $filters)
    {
        $query = DiningTransaction::query()->with(['tableSession.restaurantTable', 'receipt', 'payments', 'orders.items'])
            ->withCount(['orders as accepted_order_count' => fn ($q) => $q->whereIn('status', self::ACCEPTED_ORDER_STATUSES)])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('opened_at', '>=', $this->start($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('opened_at', '<=', $this->end($to)))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('transaction_number', 'like', "%{$search}%")
                        ->orWhereHas('orders', fn ($orders) => $orders->where('order_number', 'like', "%{$search}%"))
                        ->orWhereHas('tableSession.restaurantTable', fn ($tables) => $tables->where('table_number', 'like', "%{$search}%"));
                });
            })->latest('opened_at');

        return $query->paginate(15)->through(fn ($transaction) => $this->transactionRow($transaction));
    }

    public function payments(array $filters)
    {
        $query = Payment::query()->with(['diningTransaction.orders.items', 'diningTransaction.tableSession.restaurantTable', 'diningTransaction.receipt'])
            ->where('status', 'completed')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('paid_at', '>=', $this->start($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('paid_at', '<=', $this->end($to)))
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->latest('paid_at');
        $paginator = $query->paginate(15);
        $paginator->through(fn ($payment) => [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'transaction_number' => $payment->diningTransaction->transaction_number,
            'payment_method' => $payment->payment_method,
            'amount' => $payment->amount,
            'amount_received' => $payment->amount_received,
            'change_amount' => $payment->change_amount,
            'reference_number' => $payment->reference_number,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ]);

        return $paginator;
    }

    public function paymentSummary(?string $from, ?string $to, ?string $method): array
    {
        $query = Payment::query()->where('status', 'completed')
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $this->start($from)))
            ->when($to, fn ($q) => $q->where('paid_at', '<=', $this->end($to)))
            ->when($method, fn ($q) => $q->where('payment_method', $method));
        $payments = $query->get();
        $byMethod = [];
        foreach ($payments as $payment) {
            $byMethod[$payment->payment_method] = ($byMethod[$payment->payment_method] ?? 0) + $this->cents($payment->amount);
        }

        return ['payment_count' => $payments->count(), 'total_paid' => $this->money($payments->sum(fn ($p) => $this->cents($p->amount))), 'payment_methods' => collect($byMethod)->map(fn ($v) => $this->money($v))->all()];
    }

    public function menuItems(?string $from, ?string $to): array
    {
        return $this->bestSelling->ranked($from, $to);
    }

    public function inventory(): array
    {
        return Inventory::query()->with('menuItem')->orderBy('id')->get()->map(fn ($inventory) => ['id' => $inventory->id, 'menu_item_id' => $inventory->menu_item_id, 'menu_item_name' => $inventory->menuItem->name, 'quantity' => $inventory->quantity, 'low_stock_threshold' => $inventory->low_stock_threshold, 'is_available' => (bool) $inventory->menuItem->is_available, 'is_low_stock' => $inventory->quantity > 0 && $inventory->quantity <= $inventory->low_stock_threshold, 'is_out_of_stock' => $inventory->quantity === 0, 'last_restocked_at' => $inventory->last_restocked_at?->toIso8601String()])->all();
    }

    public function movements(array $filters)
    {
        return InventoryMovement::query()->with('inventory.menuItem')->when($filters['inventory_id'] ?? null, fn ($q, $id) => $q->where('inventory_id', $id))->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $this->start($from)))->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $this->end($to)))->latest('created_at')->paginate(15)->through(fn ($movement) => ['id' => $movement->id, 'inventory_id' => $movement->inventory_id, 'menu_item_id' => $movement->inventory->menu_item_id, 'menu_item_name' => $movement->inventory->menuItem->name, 'type' => $movement->type, 'quantity_change' => $movement->quantity_change, 'quantity_before' => $movement->quantity_before, 'quantity_after' => $movement->quantity_after, 'reason' => $movement->reason, 'created_at' => $movement->created_at?->toIso8601String()]);
    }

    private function paidTransactions(?string $from, ?string $to)
    {
        return DiningTransaction::query()->where('status', 'paid')->whereNotNull('paid_at')->when($from, fn ($q) => $q->where('paid_at', '>=', $this->start($from)))->when($to, fn ($q) => $q->where('paid_at', '<=', $this->end($to)))->get();
    }

    private function acceptedItems(): Builder
    {
        return OrderItem::query()->whereHas('order', function ($query) {
            $query->whereIn('status', self::ACCEPTED_ORDER_STATUSES);
        })->whereHas('order.diningTransaction', function ($query) {
            $query->where('status', 'paid')->whereNotNull('paid_at');
        });
    }

    private function transactionRow(DiningTransaction $t): array
    {
        $total = $t->orders->whereIn('status', self::ACCEPTED_ORDER_STATUSES)->flatMap->items->sum(fn ($i) => $this->cents($i->subtotal));
        $paid = $t->payments->where('status', 'completed')->sum(fn ($p) => $this->cents($p->amount));

        return ['id' => $t->id, 'transaction_number' => $t->transaction_number, 'status' => $t->status, 'opened_at' => $t->opened_at?->toIso8601String(), 'paid_at' => $t->paid_at?->toIso8601String(), 'table' => ['id' => $t->tableSession->restaurantTable->id, 'table_number' => $t->tableSession->restaurantTable->table_number], 'order_numbers' => $t->orders->pluck('order_number')->values()->all(), 'accepted_order_count' => $t->accepted_order_count, 'total_amount' => $this->money($total), 'paid_amount' => $this->money($paid), 'remaining_balance' => $this->money(max(0, $total - $paid)), 'payment_methods' => $t->payments->where('status', 'completed')->pluck('payment_method')->unique()->values()->all(), 'receipt' => $t->receipt ? ['id' => $t->receipt->id, 'receipt_number' => $t->receipt->receipt_number, 'status' => $t->receipt->status, 'generated_at' => $t->receipt->generated_at?->toIso8601String(), 'printed_at' => $t->receipt->printed_at?->toIso8601String(), 'print_count' => $t->receipt->print_count] : null];
    }

    private function cents($value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function start(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, 'UTC')->startOfDay();
    }

    private function end(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, 'UTC')->endOfDay();
    }
}
