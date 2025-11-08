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
        Schema::table('resource_allocations', function (Blueprint $table) {
            $table->foreignId('meeting_id')->nullable()->after('leader_user_id')->constrained('meetings')->onDelete('cascade');
            $table->string('title')->nullable()->after('meeting_id'); // Título descriptivo de la asignación
            $table->decimal('total_cost', 15, 2)->nullable()->after('amount'); // Costo total calculado de los items
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_allocations', function (Blueprint $table) {
            $table->dropForeign(['meeting_id']);
            $table->dropColumn(['meeting_id', 'title', 'total_cost']);
        });
    }
};
