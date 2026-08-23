<?php

namespace Tests\Feature\Voters;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * n8n retirado (Spec 0091 · RF-B6).
 *
 * La Spec 0030 dejó los webhooks autenticados con un secreto por tenant; la 0091
 * los deja sin razón de ser: la consulta a Registraduría ya no la hace un tercero
 * que pide pendientes y escribe de vuelta, sino la propia campaña, de salida y en
 * cola. Se eliminan en vez de dejarlos deprecados —una ruta pública que nadie
 * mira es superficie de ataque gratis— y esta prueba fija que no vuelvan.
 */
class RetiroWebhookN8nTest extends TestCase
{
    public function test_la_ruta_de_pendientes_ya_no_existe(): void
    {
        $this->getJson('/api/v1/webhook/political/registraduria/pendientes')->assertStatus(404);
    }

    public function test_la_ruta_de_actualizar_ya_no_existe(): void
    {
        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [
            'id' => 1,
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ])->assertStatus(404);
    }

    public function test_la_columna_del_secreto_ya_no_esta_en_la_base(): void
    {
        // La suite corre `migrate:fresh`: si la migración de baja no fuera limpia,
        // esto fallaría aquí y no en producción (Art. VIII).
        $this->assertFalse(Schema::hasColumn('tenants', 'registraduria_secret_hash'));
    }

    public function test_el_comando_del_secreto_ya_no_esta_registrado(): void
    {
        $this->assertArrayNotHasKey(
            'registraduria:secret',
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->all()
        );
    }

    public function test_el_alias_del_middleware_del_webhook_ya_no_existe(): void
    {
        $this->assertArrayNotHasKey('webhook.registraduria', $this->app['router']->getMiddleware());
    }

    public function test_el_comando_de_backfill_ocupa_su_lugar(): void
    {
        // Lo que reemplaza al `pendientes` de n8n.
        $this->assertArrayHasKey(
            'voters:consultar-puestos',
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->all()
        );
    }
}
