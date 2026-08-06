<?php

namespace Tests\Feature\Permissions;

use Tests\TestCase;

/**
 * Regresión de nombres (Spec 0002): los permisos en español quedaron retirados.
 *
 * Sustituye al "grep final" manual: si alguien reintroduce `ver_electores` o
 * `gestion_enlaces` en el backend, la suite lo dice.
 */
class PermissionNamingTest extends TestCase
{
    /**
     * @var array<string, string> viejo => nuevo
     */
    private const RENOMBRADOS = [
        'ver_electores' => 'view_voters',
        'gestion_enlaces' => 'manage_liaisons',
    ];

    /**
     * Directorios de código propio donde puede aparecer un nombre de permiso.
     *
     * @var array<int, string>
     */
    private const RUTAS = ['app', 'routes', 'database', 'config'];

    public function test_no_queda_ningun_nombre_de_permiso_en_espanol_en_el_codigo(): void
    {
        foreach (self::RENOMBRADOS as $viejo => $nuevo) {
            $ocurrencias = $this->buscarEnCodigo($viejo);

            $this->assertSame(
                [],
                $ocurrencias,
                "`{$viejo}` fue renombrado a `{$nuevo}` (Spec 0002). Todavía aparece en:\n"
                .implode("\n", $ocurrencias)
            );
        }
    }

    /**
     * @return array<int, string> "ruta:linea" por cada coincidencia
     */
    private function buscarEnCodigo(string $aguja): array
    {
        $ocurrencias = [];

        foreach (self::RUTAS as $directorio) {
            $ruta = base_path($directorio);

            if (! is_dir($ruta)) {
                continue;
            }

            $archivos = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($ruta, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($archivos as $archivo) {
                if ($archivo->getExtension() !== 'php') {
                    continue;
                }

                foreach (file($archivo->getPathname()) as $numero => $linea) {
                    if (str_contains($linea, $aguja)) {
                        $relativo = str_replace(base_path().DIRECTORY_SEPARATOR, '', $archivo->getPathname());
                        $ocurrencias[] = $relativo.':'.($numero + 1);
                    }
                }
            }
        }

        return $ocurrencias;
    }
}
