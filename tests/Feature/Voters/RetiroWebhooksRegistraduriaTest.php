<?php

namespace Tests\Feature\Voters;

use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\TestCase;

/**
 * Los webhooks de Registraduría de n8n ya no existen (Spec 0091).
 *
 * La 0030 los blindó con un secreto por tenant; la 0091 los retira entera y
 * directamente, porque el flujo se invirtió: ya no hay una pieza externa que
 * pregunte por los pendientes y escriba de vuelta, sino Laravel llamando al
 * servicio de scraping desde una cola.
 *
 * Esta prueba existe para que el retiro no se deshaga por accidente. Una ruta
 * pública que escribe en `voters` es superficie de ataque, y la que había ya se
 * cerró una vez a la mala (Spec 0030): que vuelva a aparecer tiene que romper la
 * suite, no descubrirse en producción.
 */
class RetiroWebhooksRegistraduriaTest extends TestCase
{
    public function test_las_rutas_del_webhook_ya_no_resuelven(): void
    {
        $this->getJson('/api/v1/webhook/political/registraduria/pendientes')->assertStatus(404);
        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', ['id' => 1])->assertStatus(404);
    }

    public function test_el_alias_del_middleware_ya_no_esta_registrado(): void
    {
        $alias = app('router')->getMiddleware();

        $this->assertArrayNotHasKey('webhook.registraduria', $alias);
    }

    public function test_el_comando_del_secreto_ya_no_existe(): void
    {
        $this->expectException(CommandNotFoundException::class);

        $this->artisan('registraduria:secret', ['tenant' => 1]);
    }

    public function test_la_columna_del_secreto_ya_no_esta_en_tenants(): void
    {
        // `migrate:fresh` corre en cada prueba (RefreshDatabase): si la migración
        // de baja no fuera limpia, esto no llegaría a ejecutarse (Art. VIII).
        $this->assertFalse(Schema::hasColumn('tenants', 'registraduria_secret_hash'));
    }

    public function test_el_modelo_tenant_ya_no_sabe_de_secretos(): void
    {
        // Los helpers se fueron con la columna: dejarlos escribiría en una
        // columna que no existe y fallaría en tiempo de ejecución, no de compilación.
        $this->assertFalse(method_exists(\App\Models\Tenant::class, 'generarSecretoRegistraduria'));
        $this->assertFalse(method_exists(\App\Models\Tenant::class, 'porSecretoRegistraduria'));
        $this->assertFalse(method_exists(\App\Models\Tenant::class, 'hashSecretoRegistraduria'));
    }
}
