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
        Schema::create('tenant_messaging_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Email credits
            $table->integer('emails_available')->default(0)->comment('Available email credits');
            $table->integer('emails_used')->default(0)->comment('Total emails sent');
            
            // WhatsApp credits
            $table->integer('whatsapp_available')->default(0)->comment('Available WhatsApp credits');
            $table->integer('whatsapp_used')->default(0)->comment('Total WhatsApp messages sent');
            
            // Totals for billing
            $table->decimal('total_email_cost', 12, 2)->default(0)->comment('Total spent on emails');
            $table->decimal('total_whatsapp_cost', 12, 2)->default(0)->comment('Total spent on WhatsApp');
            
            $table->timestamps();
            
            // Only one record per tenant
            $table->unique('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_messaging_credits');
    }
};
