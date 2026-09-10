<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(DashboardRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->summary($request->integer('limit') ?: null)]);
    }
}
