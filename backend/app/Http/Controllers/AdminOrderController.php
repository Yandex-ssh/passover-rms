<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'confirmed', 'preparing', 'completed', 'rejected', 'cancelled'])],
        ]);

        return OrderResource::collection(Order::query()
            ->with(['tableSession.restaurantTable', 'items.menuItem', 'diningTransaction'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('submitted_at')->latest('id')->paginate(20));
    }
}
