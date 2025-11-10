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
        Schema::create('messaging_config', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('email_price or whatsapp_price');
            $table->decimal('value', 10, 2)->comment('Price per unit in COP');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messaging_config');
    }
};
