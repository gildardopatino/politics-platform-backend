<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->foreignId('tenant_id')->after('id')->nullable()->constrained('tenants')->onDelete('cascade');
        });
        
        // Update existing records to have tenant_id from their meeting
        DB::statement('
            UPDATE meeting_attendees 
            SET tenant_id = (
                SELECT tenant_id 
                FROM meetings 
                WHERE meetings.id = meeting_attendees.meeting_id
            )
        ');
        
        // Make the column NOT NULL after updating values
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
            $table->index(['tenant_id', 'meeting_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'meeting_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
