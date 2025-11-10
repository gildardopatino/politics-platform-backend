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
        Schema::create('messaging_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->enum('type', ['email', 'whatsapp'])->comment('Type of messaging service');
            $table->enum('transaction_type', ['purchase', 'consumption', 'refund', 'adjustment'])->comment('Type of transaction');
            $table->integer('quantity')->comment('Number of credits (positive for add, negative for consume)');
            $table->decimal('unit_price', 10, 2)->comment('Price per unit at time of transaction');
            $table->decimal('total_cost', 12, 2)->comment('Total cost of transaction');
            $table->string('reference')->nullable()->comment('External reference or reason');
            $table->text('notes')->nullable()->comment('Additional notes');
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed'])->default('completed');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->onDelete('set null')->comment('User who requested the credits');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null')->comment('Superadmin who approved');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            
            // Indexes for queries
            $table->index(['tenant_id', 'type', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messaging_credit_transactions');
    }
};
