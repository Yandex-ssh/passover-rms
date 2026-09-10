<?php

namespace App\Http\Controllers;

use App\Http\Resources\RestaurantTableResource;
use App\Models\RestaurantTable;
use App\Services\QrUrlService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\Response;

class RestaurantTableController extends Controller
{
    public function __construct(
        private readonly QrUrlService $qrUrlService
    ) {
    }
    /**
     * Display a listing of all restaurant tables.
     */
    public function index(): AnonymousResourceCollection
    {
        $tables = RestaurantTable::latest()->get();

        return RestaurantTableResource::collection($tables);
    }

    /**
     * Store a newly created restaurant table in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_number' => ['required', 'string', 'max:50', 'unique:restaurant_tables,table_number'],
            'capacity'     => ['required', 'integer', 'min:1'],
            'status'       => ['nullable', 'string', Rule::in(['available', 'occupied', 'reserved'])],
        ]);

        $table = RestaurantTable::create($validated);

        return (new RestaurantTableResource($table))
            ->additional([
                'success' => true,
                'message' => 'Restaurant table created successfully.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified restaurant table.
     */
    public function show(RestaurantTable $restaurantTable): RestaurantTableResource
    {
        return new RestaurantTableResource($restaurantTable);
    }

    /**
     * Update the specified restaurant table in storage.
     */
    public function update(Request $request, RestaurantTable $restaurantTable): JsonResponse
    {
        $validated = $request->validate([
            'table_number' => [
                'sometimes', 
                'required', 
                'string', 
                'max:50', 
                Rule::unique('restaurant_tables', 'table_number')->ignore($restaurantTable->id)
            ],
            'capacity'     => ['sometimes', 'required', 'integer', 'min:1'],
            'status'       => ['sometimes', 'required', 'string', Rule::in(['available', 'occupied', 'reserved'])],
        ]);

        $restaurantTable->update($validated);

        return (new RestaurantTableResource($restaurantTable))
            ->additional([
                'success' => true,
                'message' => 'Restaurant table updated successfully.',
            ])
            ->response();
    }

    /**
     * Remove the specified restaurant table from storage.
     */
    public function destroy(RestaurantTable $restaurantTable): JsonResponse
    {
        $restaurantTable->delete();

        return response()->json([
            'success' => true,
            'message' => 'Restaurant table deleted successfully.',
        ], 200);
    }

        /**
     * Generate a QR code for the specified restaurant table.
     */
    public function qr(RestaurantTable $restaurantTable): Response
    {
        $orderingUrl = $this->qrUrlService->forTable($restaurantTable);

        $qrCode = QrCode::format('svg')
            ->size(300)
            ->margin(2)
            ->generate($orderingUrl);

        return response($qrCode, 200)
            ->header('Content-Type', 'image/svg+xml');
    }
}