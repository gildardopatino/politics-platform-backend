<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credencial de servicio para el lector de actas (Spec 0061 · Parte A).
 *
 * El lector de E-14 corre en la máquina de alguien, sin navegador y sin sesión:
 * no puede hacer login ni renovar un JWT cada hora. Necesita una credencial de
 * larga vida.
 *
 * El token **autentica e identifica**: cuelga de un usuario de servicio que
 * pertenece a un tenant, así que el tenant sale de la credencial y no de una
 * cabecera que el cliente pueda elegir. Es la misma decisión que se tomó para el
 * webhook de Registraduría (Spec 0030), con una vuelta más: al haber usuario
 * real, los permisos (`manage_e14`) y la auditoría funcionan igual que en las
 * rutas con sesión, sin un camino paralelo que se olvide de revisar.
 *
 * En la tabla solo queda el SHA-256. El valor en claro se muestra una vez, al
 * generarlo, y se rota con `php artisan e14:token <tenant> --rotate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e14_service_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('nombre')->default('lector e14');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e14_service_tokens');
    }
};
