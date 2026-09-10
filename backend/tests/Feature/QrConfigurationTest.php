<?php

namespace Tests\Feature;

use App\Models\RestaurantTable;
use App\Services\QrUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_url_uses_configured_frontend_url_without_duplicate_slash(): void
    {
        config()->set('app.frontend_url', 'LAN_HOST/');
        $table = RestaurantTable::create(['table_number' => 'LAN-1', 'capacity' => 4, 'status' => 'available']);
        $tokenBefore = $table->qr_token;

        $this->assertSame(
            "LAN_HOST/order/{$tokenBefore}",
            app(QrUrlService::class)->forTable($table)
        );
        $this->assertSame($tokenBefore, $table->fresh()->qr_token);
    }

    public function test_qr_url_is_environment_configurable_and_does_not_use_app_url(): void
    {
        config()->set('app.frontend_url', 'LAN_FRONTEND_HOST');
        config()->set('app.url', 'WRONG_APP_HOST');
        $table = RestaurantTable::create(['table_number' => 'LAN-2', 'capacity' => 4, 'status' => 'available']);

        $this->assertStringStartsWith(
            'LAN_FRONTEND_HOST/order/',
            app(QrUrlService::class)->forTable($table)
        );
    }

    public function test_production_qr_generation_fails_clearly_when_frontend_url_is_missing(): void
    {
        config()->set('app.frontend_url', null);
        $this->app['env'] = 'production';
        $table = RestaurantTable::create(['table_number' => 'LAN-3', 'capacity' => 4, 'status' => 'available']);

        $this->expectException(\RuntimeException::class);
        app(QrUrlService::class)->forTable($table);
    }
}
