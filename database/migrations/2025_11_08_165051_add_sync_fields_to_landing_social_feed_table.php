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
        Schema::table('landing_social_feed', function (Blueprint $table) {
            // External ID from the social network (to avoid duplicates)
            $table->string('external_id')->nullable()->after('tenant_id')->index();
            
            // Original URL of the post
            $table->text('external_url')->nullable()->after('external_id');
            
            // Timestamp of last sync
            $table->timestamp('last_synced_at')->nullable()->after('is_active');
            
            // Flag to indicate if it was synced or manually created
            $table->boolean('is_synced')->default(false)->after('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_social_feed', function (Blueprint $table) {
            $table->dropColumn([
                'external_id',
                'external_url',
                'last_synced_at',
                'is_synced',
            ]);
        });
    }
};
