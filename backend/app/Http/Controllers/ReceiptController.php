<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReceiptResource;
use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receiptService
    ) {}

    public function show(Receipt $receipt): JsonResponse
    {
        return response()->json([
            'data' => new ReceiptResource($receipt),
            'success' => true,
        ]);
    }

    public function printed(Receipt $receipt): JsonResponse
    {
        $receipt = $this->receiptService->markPrinted($receipt);

        return response()->json([
            'data' => new ReceiptResource($receipt),
            'success' => true,
            'message' => 'Receipt print recorded.',
        ]);
    }
}
