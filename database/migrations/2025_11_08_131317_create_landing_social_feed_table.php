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
        Schema::create('landing_social_feed', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->enum('plataforma', ['twitter', 'facebook', 'instagram']);
            $table->string('usuario');
            $table->text('contenido');
            $table->dateTime('fecha');
            $table->integer('likes')->default(0);
            $table->integer('compartidos')->default(0);
            $table->integer('comentarios')->default(0);
            $table->string('imagen')->nullable(); // S3/Wasabi key
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['tenant_id', 'is_active', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_social_feed');
    }
};
