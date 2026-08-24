<?php

namespace Tests\Unit\Models;

use App\Models\E14Acta;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * La elección de una campaña (Spec 0093 · RF-B1).
 *
 * Un tenant sirve a **una** elección, y este mapa es el que lo dice. De él
 * cuelga todo el escrutinio —qué actas se pueden cargar, qué cruce se sirve, qué
 * acta se rechaza por ajena—, así que si se mueve una fila se mueve el producto
 * entero. Esta prueba es la que lo fija.
 */
class TenantEleccionTest extends TestCase
{
    private function tenant(string $cargo): Tenant
    {
        return Tenant::factory()->make(['tipo_cargo' => $cargo]);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function cargos(): array
    {
        return [
            'gobernación' => ['Gobernacion', 'gobernacion'],
            'alcaldía' => ['Alcaldia', 'alcaldia'],
            'concejo' => ['Concejo', 'concejo'],
            // Los dos que traducir por texto fallaría: un congresista se elige
            // en el senado y un diputado, en la asamblea.
            'congresista → senado' => ['Congresista', 'senado'],
            'diputado → asamblea' => ['Diputado', 'asamblea_departamental'],
            // `Otro` no es un cargo de elección popular: no escruta.
            'otro → sin elección' => ['Otro', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cargos')]
    public function test_cada_cargo_tiene_su_eleccion(string $cargo, ?string $esperado): void
    {
        $this->assertSame($esperado, Tenant::eleccionDelCargo($cargo));
        $this->assertSame($esperado, $this->tenant($cargo)->tipoEleccion());
    }

    public function test_el_mapa_cubre_exactamente_los_cargos_del_enum(): void
    {
        // Un cargo nuevo sin elección asignada dejaría a esa campaña sin
        // escrutinio y sin que nadie lo notara hasta la noche de la elección.
        $this->assertEqualsCanonicalizing(
            Tenant::TIPOS_CARGO,
            array_keys(Tenant::ELECCION_POR_CARGO),
            'El mapa de elecciones y `Tenant::TIPOS_CARGO` divergieron.'
        );
    }

    public function test_toda_eleccion_del_mapa_existe_en_el_vocabulario_del_e14(): void
    {
        foreach (Tenant::ELECCION_POR_CARGO as $cargo => $tipo) {
            if ($tipo === null) {
                continue;
            }

            $this->assertContains($tipo, E14Acta::TIPOS, "El cargo {$cargo} apunta a un tipo que el E-14 no conoce.");
        }
    }

    public function test_la_caja_y_los_espacios_no_cambian_la_eleccion(): void
    {
        // El enum guarda `Alcaldia`, pero un dato viejo con otra caja no puede
        // dejar a la campaña sin escrutinio.
        $this->assertSame('alcaldia', Tenant::eleccionDelCargo('  ALCALDIA '));
        $this->assertSame('senado', Tenant::eleccionDelCargo('congresista'));
    }

    public function test_un_cargo_que_no_existe_no_se_adivina(): void
    {
        // Fuera de la tabla no hay elección: `Representante` salió del enum en
        // la 0084 porque no hay actas de Cámara, y aquí no se le inventa una.
        $this->assertNull(Tenant::eleccionDelCargo('Representante'));
        $this->assertNull(Tenant::eleccionDelCargo(''));
        $this->assertNull(Tenant::eleccionDelCargo(null));
    }
}
