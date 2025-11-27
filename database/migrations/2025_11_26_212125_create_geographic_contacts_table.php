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
        Schema::create('geographic_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Polimorfic relationship - puede ser Department, Municipality, Commune, Barrio, Corregimiento, Vereda
            $table->morphs('contactable');
            
            // Datos del enlace
            $table->string('identificacion', 20);
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('telefono', 20);
            $table->text('direccion')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['tenant_id', 'contactable_type', 'contactable_id']);
            $table->index('identificacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geographic_contacts');
    }
};
