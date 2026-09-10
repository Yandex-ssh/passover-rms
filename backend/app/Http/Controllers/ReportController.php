<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentReportRequest;
use App\Http\Requests\ReportDateRangeRequest;
use App\Http\Requests\TransactionReportRequest;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportingService $reporting) {}

    public function sales(ReportDateRangeRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->reporting->sales($request->input('from'), $request->input('to'))]);
    }

    public function transactions(TransactionReportRequest $request): JsonResponse
    {
        return $this->page($this->reporting->transactions($request->validated()));
    }

    public function payments(PaymentReportRequest $request): JsonResponse
    {
        return $this->page($this->reporting->payments($request->validated()));
    }

    public function paymentSummary(PaymentReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->reporting->paymentSummary($request->input('from'), $request->input('to'), $request->input('payment_method'))]);
    }

    public function menuItems(ReportDateRangeRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->reporting->menuItems($request->input('from'), $request->input('to'))]);
    }

    public function inventory(): JsonResponse
    {
        return response()->json(['data' => $this->reporting->inventory()]);
    }

    public function movements(ReportDateRangeRequest $request): JsonResponse
    {
        return $this->page($this->reporting->movements(array_merge($request->validated(), $request->validate(['inventory_id' => ['nullable', 'integer', 'exists:inventories,id']]))));
    }

    private function page($paginator): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
