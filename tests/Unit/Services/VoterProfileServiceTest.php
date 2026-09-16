<?php

namespace Tests\Unit\Services;

use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Services\VoterProfileService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Resolución alias→oficio canónico (Spec 0094, RF-3).
 */
class VoterProfileServiceTest extends TestCase
{
    private VoterProfileService $servicio;

    private Occupation $vigilante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = new VoterProfileService;

        $this->vigilante = Occupation::create(['nombre' => 'Vigilante', 'activo' => true]);
        OccupationAlias::create(['occupation_id' => $this->vigilante->id, 'alias' => 'celador']);
        OccupationAlias::create(['occupation_id' => $this->vigilante->id, 'alias' => 'guarda de seguridad']);
    }

    public function test_el_alias_resuelve_al_oficio_canonico(): void
    {
        $this->assertSame($this->vigilante->id, $this->servicio->resolverOficio('celador'));
    }

    public function test_el_nombre_canonico_tambien_resuelve(): void
    {
        $this->assertSame($this->vigilante->id, $this->servicio->resolverOficio('vigilante'));
    }

    public function test_normaliza_mayusculas_acentos_y_espacios(): void
    {
        foreach (['  CELADOR ', 'Celador', 'celadór', 'guarda   de  seguridad'] as $texto) {
            $this->assertSame(
                $this->vigilante->id,
                $this->servicio->resolverOficio($texto),
                "«{$texto}» debería resolver a Vigilante."
            );
        }
    }

    public function test_un_texto_sin_match_devuelve_null_y_no_revienta(): void
    {
        $this->assertNull($this->servicio->resolverOficio('astronauta'));
        $this->assertNull($this->servicio->resolverOficio(''));
        $this->assertNull($this->servicio->resolverOficio(null));
    }

    public function test_un_oficio_inactivo_no_resuelve(): void
    {
        $retirado = Occupation::create(['nombre' => 'Oficio retirado', 'activo' => false]);
        OccupationAlias::create(['occupation_id' => $retirado->id, 'alias' => 'retirado']);

        $this->assertNull($this->servicio->resolverOficio('retirado'));
    }

    public function test_el_catalogo_se_lee_en_una_sola_consulta_y_no_por_termino(): void
    {
        // Art. VI: resolver N términos no puede costar N consultas.
        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        foreach (['celador', 'vigilante', 'guarda de seguridad', 'astronauta'] as $texto) {
            $this->servicio->resolverOficio($texto);
        }

        $this->assertSame(1, $consultas, "Se esperaba una consulta al catálogo, hubo {$consultas}.");
    }
}
