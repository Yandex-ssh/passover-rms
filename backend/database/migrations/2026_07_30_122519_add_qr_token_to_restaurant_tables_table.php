<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->uuid('qr_token')
                ->nullable()
                ->after('id');
        });

        // Backfill UUID for existing records so unique index can be created
        $tables = DB::table('restaurant_tables')->whereNull('qr_token')->get();
        foreach ($tables as $t) {
            DB::table('restaurant_tables')
                ->where('id', $t->id)
                ->update(['qr_token' => (string) Str::uuid()]);
        }

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->unique('qr_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn('qr_token');
        });
    }
};