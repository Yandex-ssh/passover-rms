<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('tracking_token')->nullable()->unique()->after('order_number');
        });

        DB::table('orders')
            ->whereNull('tracking_token')
            ->orderBy('id')
            ->eachById(function (object $order): void {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['tracking_token' => (string) Str::uuid()]);
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['tracking_token']);
            $table->dropColumn('tracking_token');
        });
    }
};
