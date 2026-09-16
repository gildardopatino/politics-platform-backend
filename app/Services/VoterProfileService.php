<?php

namespace App\Services;

use App\Models\Occupation;
use App\Models\Voter;
use App\Models\VoterOccupation;
use App\Models\VoterProfile;
use App\Services\E14\PuestoResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Perfil laboral del votante y bolsa de empleo (Spec 0094).
 *
 * Dos trabajos que comparten una pieza: escribir el perfil —reemplazando el
 * conjunto de oficios, no acumulándolo— y encontrarlo después aunque quien
 * buscara escribiera «celador» donde el catálogo dice «vigilante».
 *
 * ### Los sinónimos, en una sola consulta
 *
 * La normalización (mayúsculas, acentos, espacios) se hace en PHP y no en SQL,
 * por la misma razón que en `PuestoResolver`: cada motor la escribe distinto y
 * el resultado tiene que ser el mismo en SQLite y en Postgres. Eso obliga a
 * traer el catálogo para comparar, así que se trae **entero y una vez** —nombres
 * y alias en un solo `LEFT JOIN`— y se indexa en memoria. Resolver término a
 * término con un `SELECT` por fila sería el N+1 que el Artículo VI prohíbe, y el
 * catálogo de oficios es de decenas de filas, no de miles.
 */
class VoterProfileService
{
    /**
     * `norm(nombre|alias)` → id del oficio canónico.
     *
     * @var array<string, int>|null
     */
    private ?array $mapa = null;

    /**
     * Vuelve a leer el catálogo en el siguiente uso (el seeder acaba de correr,
     * por ejemplo). El servicio puede vivir más de una petición dentro del mismo
     * proceso.
     */
    public function refrescar(): void
    {
        $this->mapa = null;
    }

    /**
     * El oficio canónico de un texto, resolviendo alias. `null` si no hay match:
     * una búsqueda que no casa devuelve vacío, no un error.
     */
    public function resolverOficio(?string $texto): ?int
    {
        $clave = PuestoResolver::norm($texto);

        if ($clave === '') {
            return null;
        }

        return $this->mapa()[$clave] ?? null;
    }

    /**
     * Crea o actualiza el perfil y **reemplaza** el conjunto de oficios.
     *
     * @param  array<string, mixed>  $datos  ya validados por el FormRequest
     */
    public function guardar(Voter $voter, array $datos, ?int $usuarioId = null): VoterProfile
    {
        return DB::transaction(function () use ($voter, $datos, $usuarioId) {
            $oficios = $datos['oficios'] ?? null;
            unset($datos['oficios']);

            $perfil = VoterProfile::where('voter_id', $voter->id)->first();

            $datos['autorizado_at'] = $this->selloDeAutorizacion($datos, $perfil);

            if ($perfil === null) {
                $perfil = VoterProfile::create($datos + [
                    'voter_id' => $voter->id,
                    'created_by' => $usuarioId,
                ]);
            } else {
                $perfil->update($datos);
            }

            // Sin la clave no se tocan los oficios; con ella —aunque venga vacía—
            // el conjunto enviado es el conjunto final.
            if ($oficios !== null) {
                $this->reemplazarOficios($voter, $oficios);
            }

            return $perfil->load('occupations');
        });
    }

    /**
     * La consulta de la bolsa de empleo, ya acotada al tenant por `TenantScope`.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function bolsa(array $filtros): Builder
    {
        $query = VoterProfile::query()
            ->with(['voter', 'occupations'])
            // Una sola consulta agregada para toda la página: saber quién tiene
            // hoja de vida no puede costar una consulta por fila (Spec 0096).
            ->withCount('resumes')
            // El votante borrado (soft delete) no sale en la bolsa: `whereHas`
            // sobre la relación ya excluye los `trashed`.
            ->whereHas('voter');

        if (array_key_exists('oficio', $filtros) && filled($filtros['oficio'])) {
            $oficioId = $this->oficioDelFiltro($filtros['oficio']);

            // Texto que no resuelve a ningún oficio ni alias → lista vacía.
            if ($oficioId === null) {
                return $query->whereRaw('1 = 0');
            }

            $relacion = $filtros['relacion'] ?? null;

            $query->whereHas('occupations', fn ($o) => $o
                ->where('occupations.id', $oficioId)
                ->when($relacion, fn ($q) => $q->where('voter_occupations.relacion', $relacion))
            );
        } elseif (! empty($filtros['relacion'])) {
            $query->whereHas('occupations', fn ($o) => $o
                ->where('voter_occupations.relacion', $filtros['relacion'])
            );
        }

        if (array_key_exists('busca_empleo', $filtros) && $filtros['busca_empleo'] !== null) {
            $query->where('busca_empleo', (bool) $filtros['busca_empleo']);
        }

        if (! empty($filtros['q'])) {
            $query->where('notas', 'like', '%'.$filtros['q'].'%');
        }

        return $query->latest('voter_profiles.id');
    }

    /**
     * El filtro `oficio` acepta el id del selector o el texto que alguien tecleó.
     */
    private function oficioDelFiltro(mixed $oficio): ?int
    {
        if (is_numeric($oficio)) {
            return (int) $oficio;
        }

        return $this->resolverOficio((string) $oficio);
    }

    /**
     * Marcar la casilla sella la fecha; desmarcarla la borra (Ley 1581). Una
     * autorización que ya estaba sellada conserva su fecha original: es la que
     * habría que poder probar.
     *
     * @param  array<string, mixed>  $datos
     */
    private function selloDeAutorizacion(array $datos, ?VoterProfile $perfil): ?string
    {
        $autoriza = array_key_exists('autoriza_tratamiento_datos', $datos)
            ? (bool) $datos['autoriza_tratamiento_datos']
            : (bool) $perfil?->autoriza_tratamiento_datos;

        if (! $autoriza) {
            return null;
        }

        return ($perfil?->autorizado_at ?? now())->toDateTimeString();
    }

    /**
     * @param  array<int, array<string, mixed>>  $oficios
     */
    private function reemplazarOficios(Voter $voter, array $oficios): void
    {
        VoterOccupation::where('voter_id', $voter->id)->delete();

        $vistos = [];

        foreach ($oficios as $oficio) {
            $clave = $oficio['occupation_id'].'|'.$oficio['relacion'];

            // El mismo par repetido en el payload no es un error del usuario,
            // pero sí violaría el unique de la tabla.
            if (isset($vistos[$clave])) {
                continue;
            }

            $vistos[$clave] = true;

            VoterOccupation::create([
                'voter_id' => $voter->id,
                'occupation_id' => $oficio['occupation_id'],
                'relacion' => $oficio['relacion'],
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function mapa(): array
    {
        if ($this->mapa !== null) {
            return $this->mapa;
        }

        $this->mapa = [];

        $filas = Occupation::query()
            ->leftJoin('occupation_aliases', 'occupation_aliases.occupation_id', '=', 'occupations.id')
            ->where('occupations.activo', true)
            ->select([
                'occupations.id as id',
                'occupations.nombre as nombre',
                'occupation_aliases.alias as alias',
            ])
            ->toBase()
            ->get();

        foreach ($filas as $fila) {
            foreach ([$fila->nombre, $fila->alias] as $texto) {
                $clave = PuestoResolver::norm($texto);

                if ($clave !== '') {
                    $this->mapa[$clave] = (int) $fila->id;
                }
            }
        }

        return $this->mapa;
    }
}
