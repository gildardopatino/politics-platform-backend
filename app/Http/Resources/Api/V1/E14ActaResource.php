<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un acta tal como la ve el cliente (Spec 0061 · Parte A).
 *
 * Las tres cifras del cuadre viajan siempre juntas, y el `estado` va con su
 * `observacion`: un acta rechazada sin el motivo obliga a rehacer la cuenta a
 * mano para saber qué le pasa.
 */
class E14ActaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'electoral_event_id' => $this->electoral_event_id,
            'evento' => $this->whenLoaded('electoralEvent', fn () => [
                'id' => $this->electoralEvent->id,
                'nombre' => $this->electoralEvent->nombre,
                'tipo' => $this->electoralEvent->tipo,
                'fecha' => $this->electoralEvent->fecha?->toDateString(),
            ]),
            'tipo' => $this->tipo,

            // Código y nombre viajan juntos (Spec 0074): el código sirve para
            // agrupar y el nombre para que alguien entienda qué está mirando.
            'departamento_code' => $this->departamento_code,
            'departamento' => $this->departamento,
            'municipio_code' => $this->municipio_code,
            'municipio' => $this->municipio,
            'zona' => $this->zona,
            'puesto' => $this->puesto,
            'mesa' => $this->mesa,
            'lugar' => $this->lugar,
            // El puesto canónico al que resolvió el `lugar` (Spec 0062). Nulo
            // mientras no haya con qué resolverlo: es lo que la pantalla de
            // conciliación necesita poder ver.
            'voting_place_id' => $this->voting_place_id,

            'archivo_nombre' => $this->archivo_nombre,
            'archivo_hash' => $this->archivo_hash,
            'upload_batch_id' => $this->upload_batch_id,
            // La ruta en el disco no sale: el PDF se alcanza con una URL firmada
            // que se emite al reclamar el acta, no con una ruta adivinable.
            'tiene_archivo' => filled($this->archivo_path),

            'estado' => $this->estado,
            'observacion' => $this->observacion,

            // Constancias de los jurados (página 2 del acta, Spec 0073). Van
            // sueltas y no dentro de `observacion` porque son cosas distintas:
            // la observación la escribe el servidor para explicar el estado, y
            // esto es lo que escribieron los jurados en la mesa.
            'hubo_recuento' => $this->hubo_recuento,
            'constancias' => $this->constancias,
            'recuento_solicitado_por' => $this->recuento_solicitado_por,
            'recuento_representacion' => $this->recuento_representacion,
            'tiene_constancias' => $this->tieneConstancias(),
            'fuente' => $this->fuente,
            'confianza' => $this->confianza,

            'suma_calculada' => $this->suma_calculada,
            'suma_declarada' => $this->suma_declarada,
            'votos_urna' => $this->votos_urna,
            // La urna cruda y el descuento, por separado (Spec 0088): quien lea
            // el acta tiene que poder rehacer la cuenta que la juzgó.
            'votos_incinerados' => $this->votos_incinerados,
            'votantes_e11' => $this->votantes_e11,
            'dif_nivelacion' => $this->dif_nivelacion,

            'votos_blanco' => $this->votos_blanco,
            'votos_nulos' => $this->votos_nulos,
            'votos_no_marcados' => $this->votos_no_marcados,

            'resultados' => $this->whenLoaded('resultados', fn () => $this->resultados
                ->sortBy('numero')
                ->values()
                ->map(fn ($resultado) => [
                    'numero' => $resultado->numero,
                    'nombre' => $resultado->nombre,
                    'votos' => $resultado->votos,
                    'e14_candidate_id' => $resultado->e14_candidate_id,
                ])
                ->all()
            ),

            // Corporación (Spec 0067): el resultado es de dos niveles y viaja
            // anidado. En un acta uninominal esta clave viene vacía, igual que
            // `resultados` en una de corporación: el `tipo` dice cuál mirar.
            'listas' => $this->whenLoaded('listas', fn () => $this->listas
                ->map(fn ($lista) => [
                    'lista_numero' => $lista->lista_numero,
                    'lista_nombre' => $lista->lista_nombre,
                    'votos_solo_lista' => $lista->votos_solo_lista,
                    'total_agrupacion' => $lista->total_agrupacion,
                    'con_voto_preferente' => $lista->con_voto_preferente,
                    // Lo que suman de verdad sus casillas: es lo que deja al
                    // panel señalar la lista que no cuadra sin rehacer la cuenta.
                    'suma_calculada' => $lista->sumaCalculada(),
                    'preferentes' => $lista->preferentes
                        ->map(fn ($preferente) => [
                            // Sin nombre: el acta de corporación no lo trae.
                            'numero' => $preferente->numero,
                            'votos' => $preferente->votos,
                        ])
                        ->all(),
                ])
                ->all()
            ),

            'intentos' => $this->intentos,
            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
