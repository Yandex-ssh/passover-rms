<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('restaurant_tables')
            ->whereNull('qr_token')
            ->orderBy('id')
            ->eachById(function (object $table): void {
                DB::table('restaurant_tables')
                    ->where('id', $table->id)
                    ->update(['qr_token' => (string) Str::uuid()]);
            });
    }

    public function down(): void
    {
        // Generated public tokens must remain stable once issued.
    }
};
