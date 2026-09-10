<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('table_sessions', 'restaurant_table_id')) {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->foreignId('restaurant_table_id')
                    ->constrained('restaurant_tables')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('table_sessions', 'session_token')) {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->uuid('session_token')->unique();
            });
        }

        if (! Schema::hasColumn('table_sessions', 'status')) {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->string('status', 20)->default('active');
            });
        }

        if (! Schema::hasColumn('table_sessions', 'opened_at')) {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->timestamp('opened_at');
            });
        }

        if (! Schema::hasColumn('table_sessions', 'closed_at')) {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->timestamp('closed_at')->nullable();
            });
        }

        if (! Schema::hasIndex('table_sessions', ['restaurant_table_id', 'status'])) {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->index(['restaurant_table_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Preserve the canonical columns this migration repairs on existing databases.
    }
};
