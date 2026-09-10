<?php

namespace App\Services;

use App\Models\RestaurantTable;
use RuntimeException;

class QrUrlService
{
    public function forTable(RestaurantTable $restaurantTable): string
    {
        $baseUrl = config('app.frontend_url');
        $fallback = rtrim((string) config('app.url'), '/') . ':8080';

        if (app()->environment('production') && (blank($baseUrl) || $baseUrl === $fallback)) {
            throw new RuntimeException('FRONTEND_URL must be explicitly configured to a deployment-reachable address in production.');
        }

        if (blank($baseUrl)) {
            throw new RuntimeException('FRONTEND_URL must be configured before generating QR codes.');
        }

        return rtrim($baseUrl, '/') . '/order/' . $restaurantTable->qr_token;
    }
}
