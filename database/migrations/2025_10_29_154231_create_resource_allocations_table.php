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
        Schema::create('resource_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('assigned_to_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_by_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('leader_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('type', ['cash', 'material', 'service']);
            $table->decimal('amount', 15, 2)->nullable();
            $table->json('details')->nullable();
            $table->date('allocation_date');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'delivered', 'returned', 'cancelled'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['assigned_to_user_id', 'status']);
            $table->index('allocation_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_allocations');
    }
};
