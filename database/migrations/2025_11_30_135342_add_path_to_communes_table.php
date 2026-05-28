<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Idempotent: an earlier migration may already have added `path`.
        if (! Schema::hasColumn('communes', 'path')) {
            Schema::table('communes', function (Blueprint $table) {
                $table->text('path')->nullable()->after('longitude');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('communes', 'path')) {
            Schema::table('communes', function (Blueprint $table) {
                $table->dropColumn('path');
            });
        }
    }
};
