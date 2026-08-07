<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Genera o rota el secreto del webhook de Registraduría de un tenant
 * (Spec 0030).
 *
 * En la tabla solo queda el SHA-256, así que el valor en claro se imprime
 * **una sola vez**: si se pierde, hay que rotarlo y reconfigurar n8n.
 */
class GenerateRegistraduriaSecret extends Command
{
    protected $signature = 'registraduria:secret
                            {tenant : id o slug del tenant}
                            {--rotate : confirma el reemplazo de un secreto existente}';

    protected $description = 'Genera (o rota) el secreto del webhook de Registraduría de un tenant';

    public function handle(): int
    {
        $tenant = Tenant::where('id', $this->argument('tenant'))
            ->orWhere('slug', $this->argument('tenant'))
            ->first();

        if (! $tenant) {
            $this->error("No existe el tenant «{$this->argument('tenant')}».");

            return self::FAILURE;
        }

        if ($tenant->registraduria_secret_hash && ! $this->option('rotate')) {
            $this->error("El tenant «{$tenant->slug}» ya tiene secreto. Usa --rotate para reemplazarlo.");
            $this->line('Rotar invalida el anterior de inmediato: n8n dejará de sincronizar hasta que se actualice.');

            return self::FAILURE;
        }

        $secreto = $tenant->generarSecretoRegistraduria();

        $this->newLine();
        $this->info("Secreto de Registraduría para «{$tenant->nombre}» (slug: {$tenant->slug}, id: {$tenant->id}):");
        $this->newLine();
        $this->line("  {$secreto}");
        $this->newLine();
        $this->comment('Se muestra UNA sola vez: en la base solo queda su SHA-256.');
        $this->comment('Configúralo en n8n como cabecera:');
        $this->line('  X-Registraduria-Secret: <el valor de arriba>');
        $this->newLine();

        return self::SUCCESS;
    }
}
