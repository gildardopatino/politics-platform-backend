<?php

namespace App\Services\Registraduria;

use App\Models\Voter;
use App\Models\VotingPlace;
use App\Services\E14\PuestoResolver;
use Illuminate\Support\Facades\Log;

/**
 * Escribe en el votante el puesto que devolvió la Registraduría (Spec 0091).
 *
 * Es la lógica que vivía dentro de `VoterController@actualizarRegistraduria`,
 * extraída para que la use el Job en vez de duplicarla. Lo que se conserva —y es
 * lo que importa— son los invariantes de la Spec 0075:
 *
 * - El puesto lo resuelve **el mismo `PuestoResolver` que el E-14**, no un
 *   `firstOrCreate` sobre el texto crudo. Cuando el webhook tenía el suyo, dos
 *   grafías del mismo colegio creaban dos renglones: el acta apuntaba a uno y el
 *   votante al otro, y el cruce fallaba en silencio.
 * - La consulta de Registraduría es el **censo oficial**, así que puede dar de
 *   alta el renglón que falte (con departamento). El alta manual del formulario
 *   no: eso sigue siendo solo-buscar.
 * - La dirección del puesto se siembra **solo si estaba vacía**: el catálogo es
 *   global y la que ya tenga pudo ponerla otra campaña o un acta.
 */
class RegistraduriaSyncService
{
    /** La mesa vive en `voters.mesa_votacion`, que es `string(20)`. */
    private const LARGO_MESA = 20;

    public function __construct(private readonly PuestoResolver $puestos) {}

    /**
     * @param  array<string, mixed>  $datos  columnas de `voters`, tal como las
     *                                       devuelve {@see RegistraduriaClient}
     */
    public function aplicar(Voter $voter, array $datos): void
    {
        $departamento = self::texto($datos['departamento_votacion'] ?? null);
        $municipio = self::texto($datos['municipio_votacion'] ?? null);
        $puesto = self::texto($datos['puesto_votacion'] ?? null);

        // Sin municipio o sin puesto no hay ubicación que escribir. Se sale sin
        // tocar al votante: dejarle nulos encima sería peor que no saber.
        if ($municipio === null || $puesto === null) {
            Log::warning('La Registraduría devolvió una ubicación incompleta', [
                'voter_id' => $voter->id,
            ]);

            return;
        }

        // El catálogo y los alias se cachean por instancia, y en la cola la
        // instancia vive lo que viva el worker: sin refrescar, un Job resolvería
        // con las fusiones que otro Job dejó cargadas —las de OTRA campaña—, que
        // es exactamente lo que el Art. III prohíbe.
        $this->puestos->refrescar();

        $puestoId = $this->puestos->resolverRegistraduria($departamento, $municipio, $puesto);

        $direccion = self::texto($datos['direccion_votacion'] ?? null);

        $this->sembrarDireccionDelPuesto($puestoId, $direccion);

        $voter->update([
            'departamento_votacion' => $departamento,
            'municipio_votacion' => $municipio,
            'puesto_votacion' => $puesto,
            'direccion_votacion' => $direccion,
            'mesa_votacion' => self::mesa($datos['mesa_votacion'] ?? null),
            'voting_place_id' => $puestoId,
        ]);
    }

    /**
     * El resolver decide **identidad** —a qué renglón del catálogo pertenece
     * este nombre—, no atributos, así que la dirección se rellena aquí.
     */
    private function sembrarDireccionDelPuesto(?int $puestoId, ?string $direccion): void
    {
        if ($puestoId === null || blank($direccion)) {
            return;
        }

        VotingPlace::where('id', $puestoId)
            ->whereNull('direccion_votacion')
            ->update(['direccion_votacion' => $direccion]);
    }

    /**
     * La mesa se guarda tal cual viene, recortada al ancho de la columna.
     *
     * El viejo webhook la validaba como entero (`nullable|integer`) mientras la
     * columna es `string(20)`: una mesa «12A» se rechazaba entera. Al retirar el
     * webhook se retira también esa validación, así que ahora entra —que es lo
     * que la columna siempre permitió—. El recorte está para que una respuesta
     * absurda del scraper no reviente el `INSERT`.
     */
    private static function mesa(mixed $valor): ?string
    {
        $mesa = self::texto($valor);

        return $mesa === null ? null : mb_substr($mesa, 0, self::LARGO_MESA);
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $limpio = trim((string) $valor);

        return $limpio === '' ? null : $limpio;
    }
}
