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
        if (! Schema::hasColumn('departments', 'path')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->text('path')->nullable()->after('longitud');
            });
        }

        if (! Schema::hasColumn('municipalities', 'path')) {
            Schema::table('municipalities', function (Blueprint $table) {
                $table->text('path')->nullable()->after('longitud');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('departments', 'path')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropColumn('path');
            });
        }

        if (Schema::hasColumn('municipalities', 'path')) {
            Schema::table('municipalities', function (Blueprint $table) {
                $table->dropColumn('path');
            });
        }
    }
};
