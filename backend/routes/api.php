<?php

use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashierBillingController;
use App\Http\Controllers\CashierMenuController;
use App\Http\Controllers\CashierOrderController;
use App\Http\Controllers\CashierPaymentController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerChatbotController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerOrderStatusController;
use App\Http\Controllers\CustomerTableController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\KitchenOrderSlipController;
use App\Http\Controllers\KitchenTicketController;
use App\Http\Controllers\MenuItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RestaurantTableController;
use App\Http\Controllers\StaffUserController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:10,1'])->post('/login', [AuthController::class, 'login']);
Route::middleware(['web', 'auth:sanctum', 'active'])->group(function (): void {
    Route::get('/user', fn (Request $request) => response()->json(['data' => new UserResource($request->user()), 'success' => true]));
    Route::match(['put', 'patch'], '/profile', [ProfileController::class, 'update']);
    Route::post('/profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1');
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Customer/public read and ordering flow.
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/{category}', [CategoryController::class, 'show']);
Route::get('menu-items', [MenuItemController::class, 'index']);
Route::get('menu-items/{menuItem}', [MenuItemController::class, 'show']);
Route::get('customer/tables/{qrToken}', [CustomerTableController::class, 'show']);
Route::post('customer/tables/{qrToken}/orders', [CustomerOrderController::class, 'store']);
Route::get('customer/orders/{trackingToken}/status', [CustomerOrderStatusController::class, 'show'])
    ->whereUuid('trackingToken')
    ->middleware('throttle:60,1');
Route::post('customer/chatbot', [CustomerChatbotController::class, 'store'])->middleware('throttle:30,1');

Route::middleware(['web', 'auth:sanctum', 'active', 'role:admin'])->group(function (): void {
    Route::get('admin/orders', [AdminOrderController::class, 'index']);
    Route::apiResource('restaurant-tables', RestaurantTableController::class);
    Route::get('admin/users', [StaffUserController::class, 'index']);
    Route::post('admin/users', [StaffUserController::class, 'store']);
    Route::match(['put', 'patch'], 'admin/users/{user}', [StaffUserController::class, 'update']);
    Route::post('admin/users/{user}/activate', [StaffUserController::class, 'activate']);
    Route::post('admin/users/{user}/deactivate', [StaffUserController::class, 'deactivate']);
    Route::post('admin/users/{user}/password', [StaffUserController::class, 'password']);
    Route::delete('admin/users/{user}', [StaffUserController::class, 'destroy']);
    Route::get('restaurant-tables/{restaurantTable}/qr', [RestaurantTableController::class, 'qr']);
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
    Route::apiResource('menu-items', MenuItemController::class)->except(['index', 'show']);
    Route::get('inventories', [InventoryController::class, 'index']);
    Route::get('inventories/{inventory}', [InventoryController::class, 'show']);
    Route::post('inventories/{inventory}/restock', [InventoryController::class, 'restock']);
    Route::post('inventories/{inventory}/adjust', [InventoryController::class, 'adjust']);
    Route::get('reports/sales', [ReportController::class, 'sales']);
    Route::get('reports/transactions', [ReportController::class, 'transactions']);
    Route::get('reports/payments/summary', [ReportController::class, 'paymentSummary']);
    Route::get('reports/payments', [ReportController::class, 'payments']);
    Route::get('reports/menu-items', [ReportController::class, 'menuItems']);
    Route::get('reports/inventory', [ReportController::class, 'inventory']);
    Route::get('reports/inventory-movements', [ReportController::class, 'movements']);
    Route::get('dashboard', DashboardController::class);
});

Route::middleware(['web', 'auth:sanctum', 'active', 'role:cashier'])->group(function (): void {
    Route::get('cashier/dashboard', DashboardController::class);
    Route::get('cashier/tables', [RestaurantTableController::class, 'index']);
    Route::get('cashier/tables/{restaurantTable}/qr', [RestaurantTableController::class, 'qr']);
    Route::get('cashier/menu-items', [CashierMenuController::class, 'index']);
    Route::put('cashier/menu-items/{menuItem}/availability', [CashierMenuController::class, 'updateAvailability']);
    Route::get('cashier/reports/sales', [ReportController::class, 'sales']);
    Route::get('cashier/reports/transactions', [ReportController::class, 'transactions']);
    Route::get('cashier/reports/payments/summary', [ReportController::class, 'paymentSummary']);
    Route::get('cashier/reports/menu-items', [ReportController::class, 'menuItems']);
    Route::get('cashier/reports/inventory', [ReportController::class, 'inventory']);
    Route::get('cashier/orders', [CashierOrderController::class, 'index']);
    Route::get('cashier/orders/{order}', [CashierOrderController::class, 'show']);
    Route::post('cashier/orders/{order}/confirm', [CashierOrderController::class, 'confirm']);
    Route::post('cashier/orders/{order}/reject', [CashierOrderController::class, 'reject']);
    Route::get('kitchen-tickets', [KitchenTicketController::class, 'index']);
    Route::get('kitchen-tickets/{order}', [KitchenTicketController::class, 'show']);
    Route::post('kitchen-tickets/{order}/prepare', [KitchenTicketController::class, 'prepare']);
    Route::post('kitchen-tickets/{order}/complete', [KitchenTicketController::class, 'complete']);
    Route::get('kitchen-order-slips/{kitchenTicket}', [KitchenOrderSlipController::class, 'show']);
    Route::post('kitchen-order-slips/{kitchenTicket}/printed', [KitchenOrderSlipController::class, 'printed']);
    Route::get('cashier/transactions/{diningTransaction}/bill', [CashierBillingController::class, 'bill']);
    Route::post('cashier/transactions/{diningTransaction}/payments/cash', [CashierPaymentController::class, 'storeCash']);
    Route::post('cashier/transactions/{diningTransaction}/payments/gcash', [CashierPaymentController::class, 'storeGcash']);
    Route::get('cashier/transactions/{diningTransaction}/payments', [CashierPaymentController::class, 'index']);
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show']);
    Route::post('receipts/{receipt}/printed', [ReceiptController::class, 'printed']);
});
