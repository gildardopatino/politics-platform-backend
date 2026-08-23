<?php

namespace App\Services\Registraduria;

use App\Models\Voter;
use App\Models\VotingPlace;
use App\Services\E14\PuestoResolver;

/**
 * Escribe en el votante lo que devolvió Registraduría (Spec 0091).
 *
 * Es la lógica que vivía dentro de `VoterController@actualizarRegistraduria`
 * (Spec 0030), extraída para que la use la cola: el webhook de n8n desapareció,
 * pero **la forma de guardar no cambia**. Sigue siendo el lado autoritativo de
 * la Spec 0075: el mismo `PuestoResolver` del E-14 decide a qué renglón del
 * catálogo pertenece el puesto —alias del tenant → catálogo normalizado → alta
 * con departamento—, así que dos grafías del mismo colegio no vuelven a partir
 * el cruce en dos.
 */
class RegistraduriaSyncService
{
    /**
     * El resolver va inyectado porque su gracia es cachear catálogo y alias del
     * tenant una sola vez; instanciarlo por votante sería el N+1 que la 0075
     * prohíbe.
     */
    public function __construct(private readonly PuestoResolver $puestos) {}

    /**
     * Traduce una fila del servicio Python a los campos del votante.
     *
     * El contrato de la Parte A viene en mayúsculas
     * (`{NUIP, DEPARTAMENTO, MUNICIPIO, PUESTO, DIRECCION, MESA}`). Sin municipio
     * o sin puesto no hay nada que guardar: son la llave del catálogo.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed> vacío si la fila no sirve
     */
    public static function mapear(array $fila): array
    {
        $municipio = trim((string) ($fila['MUNICIPIO'] ?? ''));
        $puesto = trim((string) ($fila['PUESTO'] ?? ''));

        if ($municipio === '' || $puesto === '') {
            return [];
        }

        return [
            'departamento_votacion' => trim((string) ($fila['DEPARTAMENTO'] ?? '')) ?: null,
            'municipio_votacion' => $municipio,
            'puesto_votacion' => $puesto,
            'direccion_votacion' => trim((string) ($fila['DIRECCION'] ?? '')) ?: null,
            // La columna es `string(20)`: una mesa «12A» se guarda tal cual.
            'mesa_votacion' => mb_substr(trim((string) ($fila['MESA'] ?? '')), 0, 20) ?: null,
        ];
    }

    /**
     * Escribe los cinco campos y liga el votante al puesto canónico.
     *
     * `refrescar()` al entrar porque el catálogo pudo crecer —o alguien pudo
     * fusionar dos grafías— desde que se cargó la instancia; y porque en la cola
     * el proceso vive mucho más que una petición.
     *
     * @param  array<string, mixed>  $datos  ya mapeados por `mapear()`
     */
    public function aplicar(Voter $voter, array $datos): void
    {
        if ($datos === []) {
            return;
        }

        $this->puestos->refrescar();

        $puestoId = $this->puestos->resolverRegistraduria(
            $datos['departamento_votacion'] ?? null,
            $datos['municipio_votacion'] ?? null,
            $datos['puesto_votacion'] ?? null,
        );

        $this->sembrarDireccionDelPuesto($puestoId, $datos['direccion_votacion'] ?? null);

        $voter->update([
            'departamento_votacion' => $datos['departamento_votacion'] ?? null,
            'municipio_votacion' => $datos['municipio_votacion'] ?? null,
            'puesto_votacion' => $datos['puesto_votacion'] ?? null,
            'direccion_votacion' => $datos['direccion_votacion'] ?? null,
            'mesa_votacion' => $datos['mesa_votacion'] ?? null,
            'voting_place_id' => $puestoId,
        ]);
    }

    /**
     * Completa la dirección del renglón que acabó de resolverse (Spec 0075).
     *
     * El resolver decide **identidad** —a qué puesto pertenece este nombre— y no
     * atributos, así que la dirección se rellena aquí. Solo si está vacía: el
     * catálogo es global y la dirección que ya tenga pudo ponerla otra campaña o
     * un acta.
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
}
