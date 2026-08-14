<?php

namespace App\Services\E14;

use App\Models\E14MetaPuesto;
use App\Models\ElectoralEvent;
use App\Models\VotingPlace;
use Illuminate\Validation\ValidationException;

/**
 * «¿Voy ganando?»: meta vs base identificada vs votos reales (Spec 0064).
 *
 * Es el tablero que abre la analítica de campaña, y su valor está en poner tres
 * números **distintos** uno al lado del otro sin fundirlos:
 *
 * 1. **La meta**, que se fija a mano (RF-1). No sale de ningún histórico ni del
 *    censo: es a lo que la campaña decidió aspirar.
 * 2. **La base identificada** (0076), que es el piso de trabajo — y **no** es
 *    voto asegurado. Confundir las dos cosas fue el error que la 0076 corrigió.
 * 3. **Los votos reales** (0062/0083), que son la verdad, pero solo existen
 *    donde ya hay acta.
 *
 * ### Orquesta, no recalcula
 *
 * La base sale de `RegistradosService` y los votos de `VotosService` — los mismos
 * servicios que alimentan el cruce y el rendimiento de líderes, sin tocarlos. Si
 * la proyección recalculara por su cuenta, el día que alguien cambie qué estados
 * de acta cuentan tendríamos dos tableros contradiciéndose y ninguna forma de
 * saber cuál miente. Por eso también se lee **siempre por puesto** y se pliega
 * después: municipio y global son agregaciones de lo mismo, no otra consulta.
 *
 * ### El semáforo es honesto o no sirve
 *
 * Se enciende sobre los **votos reales** donde los hay y sobre la **base** donde
 * no, y en los dos casos dice cuál de las dos está mirando (`base_del_semaforo`).
 * Un verde sobre base identificada y un verde sobre votos escrutados no
 * significan lo mismo, y un tablero que no los distinga le vende a la campaña una
 * certeza que no tiene.
 */
class ProyeccionService
{
    /** Toda la campaña en una fila. */
    public const NIVEL_GLOBAL = 'global';

    /** Una fila por municipio, agregando sus puestos. */
    public const NIVEL_MUNICIPIO = 'municipio';

    /** Una fila por puesto de votación: el nivel en el que se trabaja. */
    public const NIVEL_PUESTO = 'puesto';

    public const NIVELES = [self::NIVEL_GLOBAL, self::NIVEL_MUNICIPIO, self::NIVEL_PUESTO];

    public const SEMAFORO_VERDE = 'verde';

    public const SEMAFORO_AMBAR = 'ambar';

    public const SEMAFORO_ROJO = 'rojo';

    /** Sin meta no hay color: nadie fijó contra qué medirse. */
    public const SEMAFORO_SIN_META = 'sin_meta';

    /** El semáforo se juzgó sobre votos escrutados. */
    public const SOBRE_REAL = 'real';

    /** El semáforo se juzgó sobre la base identificada, que no es voto. */
    public const SOBRE_BASE = 'base';

    /** La meta del ámbito la fijó la campaña como global. */
    public const ORIGEN_GLOBAL = 'global';

    /** La meta del ámbito se agregó de las de sus puestos. */
    public const ORIGEN_PUESTOS = 'suma_de_puestos';

    /**
     * El texto que viaja con el dato, como el aviso del proxy en la 0063.
     *
     * La base identificada es el piso de trabajo de la campaña, no una promesa de
     * voto: va en `meta` para que la limitación llegue junto al número y no se
     * quede en la cabeza de quien lo leyó una vez.
     */
    public const AVISO_BASE = 'La base identificada es la gente que la campaña tiene ubicada, '
        .'no es voto asegurado: puede rendir de más o de menos (Spec 0076). Donde ya hay actas, '
        .'el avance y el semáforo se calculan sobre los votos reales.';

    public function __construct(
        private readonly PuestoResolver $puestos,
        private readonly RegistradosService $registrados,
        private readonly VotosService $votos,
    ) {}

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function calcular(ElectoralEvent $evento, array $filtros = []): array
    {
        if (! $evento->tieneCandidatoPropio()) {
            // El mismo mensaje que el cruce y el rendimiento: es la misma
            // configuración la que falta, y dos redacciones distintas para la
            // misma causa mandan al usuario a buscar dos cosas.
            throw ValidationException::withMessages([
                'event' => 'Esta elección todavía no tiene candidato propio. '
                    .'Configúralo para poder cruzar tu base identificada con los votos reales.',
            ]);
        }

        $nivel = in_array($filtros['nivel'] ?? null, self::NIVELES, true)
            ? $filtros['nivel']
            : self::NIVEL_PUESTO;

        $incluir = RegistradosService::normalizarIncluir($filtros['incluir'] ?? null);

        // Igual que el cruce: las actas guardadas sin puesto canónico se resuelven
        // aquí, para que los votos y la base se encuentren en la misma llave.
        $this->puestos->conciliarActas($evento->id);

        $porPuesto = $this->filtrar($this->porPuesto($evento, $incluir), $filtros);

        return [
            'data' => $this->plegar($evento, $porPuesto, $nivel, $filtros),
            'meta' => [
                'electoral_event_id' => $evento->id,
                'nivel' => $nivel,
                'incluir' => $incluir,
                'candidato' => $evento->resumenDelCandidato(),
                'totales' => $this->totales($evento, $porPuesto, $filtros),
                'umbrales' => [
                    'verde' => $this->umbral('umbral_verde', 90),
                    'ambar' => $this->umbral('umbral_ambar', 70),
                ],
                'aviso_base' => self::AVISO_BASE,
            ],
        ];
    }

    /**
     * El nivel de trabajo: una fila por puesto con los tres números crudos.
     *
     * Entra todo puesto que tenga **alguna** de las tres cosas. Un puesto con meta
     * y sin base es un objetivo que la campaña no está trabajando —justo lo que el
     * tablero tiene que enseñar—, y uno con acta y sin meta son votos que llegaron
     * de donde nadie se propuso nada.
     *
     * @return array<int, array<string, mixed>>
     */
    private function porPuesto(ElectoralEvent $evento, string $incluir): array
    {
        $base = $this->registrados->contar(CruceService::NIVEL_PUESTO, $incluir)['grupos'];
        $actas = $this->votos->actasProcesadas($evento, CruceService::NIVEL_PUESTO);
        $votos = $this->votos->deMiCandidato($evento, CruceService::NIVEL_PUESTO);

        $metas = E14MetaPuesto::query()
            ->where('electoral_event_id', $evento->id)
            ->pluck('meta_votos', 'voting_place_id')
            ->all();

        $identificados = [];

        foreach ($base as $grupo) {
            $identificados[$grupo['voting_place_id']] = $grupo['base'];
        }

        foreach (array_keys($actas + $votos) as $clave) {
            [$puesto] = PuestoResolver::partirClaveMesa($clave);
            $identificados[$puesto] ??= 0;
        }

        foreach (array_keys($metas) as $puesto) {
            $identificados[(int) $puesto] ??= 0;
        }

        $lugares = VotingPlace::query()
            ->whereIn('id', array_keys($identificados))
            ->get(['id', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion'])
            ->keyBy('id');

        $filas = [];

        foreach ($identificados as $puesto => $cuenta) {
            $clave = PuestoResolver::claveMesa($puesto, null);
            $lugar = $lugares->get($puesto);
            $cuantasActas = $actas[$clave] ?? 0;

            $filas[] = [
                'voting_place_id' => $puesto,
                'departamento' => $lugar?->departamento_votacion,
                'municipio' => $lugar?->municipio_votacion,
                'puesto' => $lugar?->puesto_votacion,
                'meta' => isset($metas[$puesto]) ? (int) $metas[$puesto] : null,
                'identificados' => $cuenta,
                // `null` y no `0`: un puesto sin escrutar no es un puesto donde
                // mi candidato sacó cero. Con acta, el cero es un cero real.
                'votos_reales' => $cuantasActas > 0 ? ($votos[$clave] ?? 0) : null,
                'tiene_actas' => $cuantasActas > 0,
                'actas' => $cuantasActas,
            ];
        }

        return $filas;
    }

    /**
     * El filtro de municipio, sobre las filas ya armadas.
     *
     * Va aquí y no en las consultas por lo mismo que en el cruce: las filas se
     * arman uniendo agregaciones de tablas distintas, y repetir el criterio en
     * cada una obligaría a mantener tres copias iguales para llegar al mismo
     * sitio.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function filtrar(array $filas, array $filtros): array
    {
        $municipio = $filtros['municipio'] ?? null;

        if (blank($municipio)) {
            return $filas;
        }

        return array_values(array_filter(
            $filas,
            fn (array $fila) => str_contains(
                PuestoResolver::norm($fila['municipio']),
                PuestoResolver::norm($municipio)
            )
        ));
    }

    /**
     * Pliega las filas de puesto al nivel pedido y les calcula lo derivado.
     *
     * @param  array<int, array<string, mixed>>  $porPuesto
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function plegar(ElectoralEvent $evento, array $porPuesto, string $nivel, array $filtros): array
    {
        if ($nivel === self::NIVEL_PUESTO) {
            return $this->ordenar(array_map(
                fn (array $fila) => $fila + $this->derivadas($fila['meta'], $fila['identificados'], $fila['votos_reales']),
                $porPuesto
            ));
        }

        if ($nivel === self::NIVEL_GLOBAL) {
            [$meta, $origen] = $this->metaDelAmbito($evento, $porPuesto, $filtros);
            $sumas = $this->sumar($porPuesto);

            return [
                ['meta' => $meta, 'meta_origen' => $origen]
                + $sumas
                + $this->derivadas($meta, $sumas['identificados'], $sumas['votos_reales']),
            ];
        }

        $grupos = [];

        foreach ($porPuesto as $fila) {
            $clave = PuestoResolver::norm($fila['departamento']).'|'.PuestoResolver::norm($fila['municipio']);
            $grupos[$clave][] = $fila;
        }

        $filas = [];

        foreach ($grupos as $delMunicipio) {
            // La meta del municipio **se agrega de sus puestos** y no se prorratea
            // desde la global: repartir la global por su cuenta inventaría un
            // objetivo que nadie fijó, y una tabla de metas por municipio
            // conviviendo con las de sus puestos obligaría a decidir cuál gana
            // cuando no cuadran — pregunta sin respuesta buena.
            $meta = $this->sumarMetas($delMunicipio);
            $sumas = $this->sumar($delMunicipio);

            $filas[] = [
                'departamento' => $delMunicipio[0]['departamento'],
                'municipio' => $delMunicipio[0]['municipio'],
                'meta' => $meta,
            ]
                + $sumas
                + $this->derivadas($meta, $sumas['identificados'], $sumas['votos_reales']);
        }

        return $this->ordenar($filas);
    }

    /**
     * Lo que se calcula a partir de los tres números, en un solo sitio.
     *
     * @return array<string, mixed>
     */
    private function derivadas(?int $meta, int $identificados, ?int $votos): array
    {
        // Una meta en cero es «no la fijé», no «mi objetivo es cero votos»: con
        // ella no hay porcentaje que calcular ni nada que reclamar.
        $hayMeta = $meta !== null && $meta > 0;

        $avanceBase = $hayMeta ? round($identificados / $meta * 100, 2) : null;
        $avanceReal = $hayMeta && $votos !== null ? round($votos / $meta * 100, 2) : null;

        // El semáforo mira lo cierto cuando existe. Sin actas se conforma con la
        // base, y lo dice: son dos afirmaciones de distinto peso.
        $sobre = ! $hayMeta ? null : ($votos !== null ? self::SOBRE_REAL : self::SOBRE_BASE);

        return [
            'avance_base' => $avanceBase,
            'avance_real' => $avanceReal,
            // Lo que falta se mide contra lo cierto donde lo hay. Nunca negativo:
            // pasarse de la meta no es «faltan −20», es que no falta nada.
            'faltante' => $hayMeta ? max(0, $meta - ($votos ?? $identificados)) : null,
            'semaforo' => self::semaforo($sobre === self::SOBRE_REAL ? $avanceReal : $avanceBase),
            'base_del_semaforo' => $sobre,
        ];
    }

    /**
     * El color, a partir del avance. Público porque es **la** regla del tablero y
     * se prueba aparte de todo lo demás.
     *
     * Los cortes son inclusive por abajo: llegar justo al umbral es cumplirlo.
     */
    public static function semaforo(?float $avance): string
    {
        if ($avance === null) {
            return self::SEMAFORO_SIN_META;
        }

        if ($avance >= (float) config('e14.proyeccion.umbral_verde', 90)) {
            return self::SEMAFORO_VERDE;
        }

        return $avance >= (float) config('e14.proyeccion.umbral_ambar', 70)
            ? self::SEMAFORO_AMBAR
            : self::SEMAFORO_ROJO;
    }

    /**
     * @param  array<int, array<string, mixed>>  $porPuesto
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function totales(ElectoralEvent $evento, array $porPuesto, array $filtros): array
    {
        [$meta, $origen] = $this->metaDelAmbito($evento, $porPuesto, $filtros);
        $sumas = $this->sumar($porPuesto);

        $sinMeta = array_filter(
            $porPuesto,
            fn (array $fila) => $fila['meta'] === null || $fila['meta'] === 0
        );

        return [
            'meta' => $meta,
            'meta_origen' => $origen,
            // Cuánto de la meta ya está repartido entre los puestos. Que sea
            // menor que la global es el estado normal, no un error.
            'meta_asignada' => (int) $this->sumarMetas($porPuesto),
            'puestos' => count($porPuesto),
            // Un tablero medio configurado no se puede leer como uno completo.
            'puestos_sin_meta' => count($sinMeta),
        ]
            + $sumas
            + $this->derivadas($meta, $sumas['identificados'], $sumas['votos_reales']);
    }

    /**
     * La meta de todo lo que se está mirando, y de dónde salió.
     *
     * Con un filtro de municipio puesto, la meta global de la campaña ya no es la
     * del ámbito —comparar la base de un municipio contra la meta de todos diría
     * cualquier cosa—, así que ahí manda la suma de los puestos mostrados. El
     * `meta_origen` viaja siempre para que el panel pueda rotularlo.
     *
     * @param  array<int, array<string, mixed>>  $porPuesto
     * @param  array<string, mixed>  $filtros
     * @return array{0: int|null, 1: string}
     */
    private function metaDelAmbito(ElectoralEvent $evento, array $porPuesto, array $filtros): array
    {
        $suma = $this->sumarMetas($porPuesto);

        if (filled($filtros['municipio'] ?? null)) {
            return [$suma, self::ORIGEN_PUESTOS];
        }

        return $evento->meta_votos > 0
            ? [$evento->meta_votos, self::ORIGEN_GLOBAL]
            : [$suma, self::ORIGEN_PUESTOS];
    }

    /**
     * Σ de las metas fijadas. `null` cuando no hay ninguna: un cero ahí se leería
     * como «la meta es cero» en vez de «nadie la fijó».
     *
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function sumarMetas(array $filas): ?int
    {
        $metas = array_filter(array_column($filas, 'meta'), fn (?int $meta) => $meta !== null);

        return $metas === [] ? null : (int) array_sum($metas);
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function sumar(array $filas): array
    {
        $actas = (int) array_sum(array_column($filas, 'actas'));

        return [
            'identificados' => (int) array_sum(array_column($filas, 'identificados')),
            // Sin una sola acta en todo el ámbito no hay votos que sumar: sumar
            // ceros diría «sacó cero» donde lo que pasa es que no se ha escrutado.
            'votos_reales' => $actas > 0
                ? (int) array_sum(array_map(fn (array $fila) => $fila['votos_reales'] ?? 0, $filas))
                : null,
            'tiene_actas' => $actas > 0,
            'actas' => $actas,
            'puestos' => count($filas),
        ];
    }

    /**
     * Lo accionable arriba: primero lo que más lejos está de su meta, como el
     * ranking de líderes de la 0063. Lo que no tiene meta va al final —no hay
     * distancia que medir— y dentro de cada grupo manda el orden geográfico, que
     * es estable entre llamadas.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, array<string, mixed>>
     */
    private function ordenar(array $filas): array
    {
        usort($filas, fn (array $a, array $b) => [
            $a['faltante'] === null ? 1 : 0,
            -($a['faltante'] ?? 0),
            $a['municipio'] ?? '',
            $a['puesto'] ?? '',
        ] <=> [
            $b['faltante'] === null ? 1 : 0,
            -($b['faltante'] ?? 0),
            $b['municipio'] ?? '',
            $b['puesto'] ?? '',
        ]);

        return $filas;
    }

    private function umbral(string $clave, float $porDefecto): float
    {
        return (float) config("e14.proyeccion.{$clave}", $porDefecto);
    }
}
