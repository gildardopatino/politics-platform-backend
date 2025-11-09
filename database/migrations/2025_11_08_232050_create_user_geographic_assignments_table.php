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
        Schema::create('user_geographic_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Polymorphic relationship to handle all geographic types
            $table->string('assignable_type'); // Department, Municipality, Commune, Barrio, Corregimiento, Vereda
            $table->unsignedBigInteger('assignable_id');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['assignable_type', 'assignable_id']);
            $table->index(['user_id', 'tenant_id']);
            $table->index(['tenant_id', 'assignable_type']);
            
            // Unique constraint: a user can't be assigned to the same location twice
            $table->unique(['user_id', 'assignable_type', 'assignable_id'], 'user_geographic_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_geographic_assignments');
    }
};
