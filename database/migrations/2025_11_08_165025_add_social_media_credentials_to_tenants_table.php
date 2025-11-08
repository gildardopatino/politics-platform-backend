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
        Schema::table('tenants', function (Blueprint $table) {
            // Twitter/X credentials
            $table->boolean('twitter_enabled')->default(false)->after('s3_bucket');
            $table->text('twitter_bearer_token')->nullable()->after('twitter_enabled');
            $table->string('twitter_user_id')->nullable()->after('twitter_bearer_token');
            $table->string('twitter_username')->nullable()->after('twitter_user_id');
            
            // Facebook credentials
            $table->boolean('facebook_enabled')->default(false)->after('twitter_username');
            $table->text('facebook_access_token')->nullable()->after('facebook_enabled');
            $table->string('facebook_page_id')->nullable()->after('facebook_access_token');
            
            // Instagram credentials (uses Facebook Graph API)
            $table->boolean('instagram_enabled')->default(false)->after('facebook_page_id');
            $table->text('instagram_access_token')->nullable()->after('instagram_enabled');
            $table->string('instagram_user_id')->nullable()->after('instagram_access_token');
            $table->string('instagram_username')->nullable()->after('instagram_user_id');
            
            // YouTube credentials
            $table->boolean('youtube_enabled')->default(false)->after('instagram_username');
            $table->text('youtube_api_key')->nullable()->after('youtube_enabled');
            $table->string('youtube_channel_id')->nullable()->after('youtube_api_key');
            
            // Auto-sync configuration
            $table->boolean('social_auto_sync_enabled')->default(false)->after('youtube_channel_id');
            $table->integer('social_sync_interval_minutes')->default(15)->after('social_auto_sync_enabled');
            $table->timestamp('social_last_synced_at')->nullable()->after('social_sync_interval_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'twitter_enabled',
                'twitter_bearer_token',
                'twitter_user_id',
                'twitter_username',
                'facebook_enabled',
                'facebook_access_token',
                'facebook_page_id',
                'instagram_enabled',
                'instagram_access_token',
                'instagram_user_id',
                'instagram_username',
                'youtube_enabled',
                'youtube_api_key',
                'youtube_channel_id',
                'social_auto_sync_enabled',
                'social_sync_interval_minutes',
                'social_last_synced_at',
            ]);
        });
    }
};
