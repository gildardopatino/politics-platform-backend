<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El candidato de la campaña (Spec 0080).
 *
 * Un tenant es un candidato a un cargo, así que aquí no hay lista: hay una ficha.
 * El nombre y el cargo salen del `Tenant` —de solo lectura, no se teclean en una
 * pantalla de escrutinio— y lo único configurable es el número del tarjetón, que
 * vive en la elección del cargo.
 *
 * El catálogo del tarjetón viaja **con** la ficha y no en un endpoint aparte, por
 * lo mismo que en la 0062: la pantalla lo necesita siempre —es de donde se elige
 * el número— y pedirlo en dos viajes solo abriría la ventana para mostrar un
 * selector vacío mientras llega el segundo.
 */
class CandidatoDelTenantResource extends JsonResource
{
    /**
     * El cargo con sus tildes. Es solo ortografía: el enum de `tenants` se
     * escribió sin acentos y lo que se muestra al usuario va en español (Art. IX).
     *
     * @var array<string, string>
     */
    private const ETIQUETA_CARGO = [
        'alcaldia' => 'Alcaldía',
        'gobernacion' => 'Gobernación',
        'concejo' => 'Concejo',
        'congresista' => 'Congresista',
        'diputado' => 'Diputado',
        'representante' => 'Representante',
        'otro' => 'Otro',
    ];

    public function __construct(
        Tenant $tenant,
        private readonly ?ElectoralEvent $evento,
        private readonly ?string $tipoEleccion,
    ) {
        parent::__construct($tenant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Identidad: del tenant, de solo lectura.
            'nombre' => $this->nombre,
            'cargo' => $this->tipo_cargo,
            'cargo_label' => $this->etiquetaDelCargo(),

            // Lo único configurable.
            'numero' => $this->evento?->candidato_propio_numero,
            'agrupacion' => $this->evento?->candidato_propio_agrupacion,
            'configurado' => $this->evento?->tieneCandidatoPropio() ?? false,

            'eleccion' => $this->evento === null ? null : [
                'id' => $this->evento->id,
                'nombre' => $this->evento->nombre,
                'tipo' => $this->evento->tipo,
                'fecha' => $this->evento->fecha?->toDateString(),
                // Es lo que le dice al frontend si el número se escribe a mano o
                // se elige de una lista: la misma regla que valida el servidor.
                'tiene_actas' => $this->evento->actas()->exists(),
            ],

            'candidatos' => $this->evento === null ? [] : $this->evento->candidates
                ->sortBy('numero')
                ->values()
                ->map(fn ($candidato) => [
                    'numero' => $candidato->numero,
                    'nombre' => $candidato->nombre,
                    'agrupacion' => $candidato->agrupacion,
                    'cargo' => $candidato->cargo,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'cargo_mapeado' => $this->tipoEleccion !== null,
                'tipo_eleccion' => $this->tipoEleccion,
                'aviso' => $this->aviso(),
            ],
        ];
    }

    /**
     * Qué falta configurar, dicho claro.
     *
     * Un cargo que no es de elección popular —o que el E-14 todavía no sabe
     * leer— no tiene elección donde fijar el número. La pantalla necesita
     * distinguirlo de «aún no lo has configurado», porque la acción que resuelve
     * cada caso está en un sitio distinto.
     */
    private function aviso(): ?string
    {
        if ($this->tipoEleccion === null) {
            return 'La campaña no tiene un cargo de elección popular configurado '
                .'(«'.($this->tipo_cargo ?: 'sin cargo').'»). Configura el cargo de la '
                .'campaña para poder fijar el número del tarjetón.';
        }

        return null;
    }

    private function etiquetaDelCargo(): ?string
    {
        $clave = mb_strtolower(trim((string) $this->tipo_cargo));

        return self::ETIQUETA_CARGO[$clave] ?? $this->tipo_cargo;
    }
}
