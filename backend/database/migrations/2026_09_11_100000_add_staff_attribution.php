<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('orders')->whereNotNull('confirmed_by')->whereNotIn('confirmed_by', DB::table('users')->select('id'))->exists()) {
            throw new RuntimeException('Resolve orphaned orders.confirmed_by references before applying staff attribution. Existing data has not been changed.');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreign('confirmed_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('processed_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('processed_by'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropForeign(['confirmed_by']));
    }
};
