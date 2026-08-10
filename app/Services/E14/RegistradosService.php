<?php

namespace App\Services\E14;

use App\Models\Lead;
use App\Models\Voter;

/**
 * Cuántos registrados hay en cada puesto, y qué nombres no resolvieron
 * (Spec 0062 · Parte A).
 *
 * Es el lado «potencial» del cruce y a la vez la materia prima de la pantalla de
 * conciliación: las dos preguntas se contestan con el mismo recuento, así que vive
 * aquí en vez de duplicarse en los dos servicios.
 */
class RegistradosService
{
    /** Qué cuenta como «registrado». `voters` es el censo propio del tenant. */
    public const INCLUIR = ['voters', 'leads', 'ambos'];

    public function __construct(private readonly PuestoResolver $puestos) {}

    /**
     * @param  'puesto'|'mesa'  $nivel
     * @return array{
     *     grupos: array<string, array<string, mixed>>,
     *     sin_conciliar: int,
     *     nombres: array<string, array<string, mixed>>
     * }
     */
    public function contar(string $nivel, string $incluir): array
    {
        // Una fusión hecha hace un momento tiene que contar ya (ver
        // `PuestoResolver::refrescar()`).
        $this->puestos->refrescar();

        $grupos = [];
        $sinConciliar = 0;
        $nombres = [];

        $porMesa = $nivel === CruceService::NIVEL_MESA;

        $acumular = function (?int $puesto, ?string $municipio, ?string $nombrePuesto, ?int $mesa, int $total) use (&$grupos, &$sinConciliar, &$nombres, $porMesa): void {
            // El votante que no trae `voting_place_id` —captura vieja, o el
            // webhook— se mapea por nombre. Solo se busca: dar de alta un puesto
            // por lo que alguien escribió en un formulario llenaría el catálogo
            // global de basura.
            $puesto ??= $this->puestos->buscarPorNombre($municipio, $nombrePuesto);

            if ($puesto === null) {
                $sinConciliar += $total;

                $clave = PuestoResolver::clave($municipio, $nombrePuesto) ?? '|';
                $nombres[$clave] ??= [
                    'municipio' => $municipio,
                    'puesto' => $nombrePuesto,
                    'registrados' => 0,
                ];
                $nombres[$clave]['registrados'] += $total;

                return;
            }

            $clave = $puesto.'|'.($porMesa ? $mesa ?? '' : '');
            $grupos[$clave] ??= [
                'voting_place_id' => $puesto,
                'mesa' => $porMesa ? $mesa : null,
                'registrados' => 0,
            ];
            $grupos[$clave]['registrados'] += $total;
        };

        if ($incluir !== 'leads') {
            $consulta = Voter::query()
                ->selectRaw('voting_place_id')
                ->selectRaw('municipio_votacion')
                ->selectRaw('puesto_votacion')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('voting_place_id', 'municipio_votacion', 'puesto_votacion');

            if ($porMesa) {
                $consulta->addSelect('mesa_votacion')->groupBy('mesa_votacion');
            }

            foreach ($consulta->get() as $fila) {
                $acumular(
                    $fila->voting_place_id !== null ? (int) $fila->voting_place_id : null,
                    $fila->municipio_votacion,
                    $fila->puesto_votacion,
                    PuestoResolver::mesa($fila->mesa_votacion ?? null),
                    (int) $fila->total,
                );
            }
        }

        // `leads` no tiene `voting_place_id`: son contactos a los que nadie ha
        // hecho la consulta de Registraduría, así que van siempre por nombre.
        if ($incluir !== 'voters') {
            $consulta = Lead::query()
                ->selectRaw('municipio_votacion')
                ->selectRaw('puesto_votacion')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('municipio_votacion', 'puesto_votacion');

            if ($porMesa) {
                $consulta->addSelect('mesa_votacion')->groupBy('mesa_votacion');
            }

            foreach ($consulta->get() as $fila) {
                $acumular(
                    null,
                    $fila->municipio_votacion,
                    $fila->puesto_votacion,
                    PuestoResolver::mesa($fila->mesa_votacion ?? null),
                    (int) $fila->total,
                );
            }
        }

        return ['grupos' => $grupos, 'sin_conciliar' => $sinConciliar, 'nombres' => $nombres];
    }

    public static function normalizarIncluir(mixed $incluir): string
    {
        return in_array($incluir, self::INCLUIR, true) ? $incluir : 'voters';
    }
}
