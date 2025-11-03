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
            $table->string('logo')->nullable()->after('metadata');
            $table->string('sidebar_bg_color')->default('#1f2937')->after('logo'); // Gray-800
            $table->string('sidebar_text_color')->default('#ffffff')->after('sidebar_bg_color'); // White
            $table->string('header_bg_color')->default('#ffffff')->after('sidebar_text_color'); // White
            $table->string('header_text_color')->default('#1f2937')->after('header_bg_color'); // Gray-800
            $table->string('content_bg_color')->default('#f9fafb')->after('header_text_color'); // Gray-50
            $table->string('content_text_color')->default('#1f2937')->after('content_bg_color'); // Gray-800
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'logo',
                'sidebar_bg_color',
                'sidebar_text_color',
                'header_bg_color',
                'header_text_color',
                'content_bg_color',
                'content_text_color'
            ]);
        });
    }
};
