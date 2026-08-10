<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use App\Models\VotingPlace;

/**
 * A qué puesto del catálogo pertenece un acta o un votante (Spec 0062 · Parte A).
 *
 * El cruce «registrados vs votos» necesita una llave común, y los dos lados
 * describen el mismo sitio de formas distintas: el votante trae municipio y
 * puesto **por nombre** y mesa sin ceros; el acta trae el lugar impreso y mesa
 * con ceros. Ninguno trae el código del puesto.
 *
 * La llave es entonces el renglón de `voting_places`: ambos lados resuelven ahí y
 * el cruce se hace por `voting_place_id` + mesa normalizada, **exacto**. Lo que
 * casa por normalización se une solo; lo que no, se lista para que una persona lo
 * fusione. Nunca se une por parecido.
 *
 * ### Qué normaliza `norm()` y qué no
 *
 * Mayúsculas, acentos y espacios de más: son las diferencias que no cambian de
 * qué sitio se habla. **No** toca puntuación ni abreviaturas: «COL. SAN SIMON» y
 * «COLEGIO SAN SIMON» son dos renglones distintos hasta que alguien diga que son
 * el mismo. Unirlos aquí sería adivinar, y adivinar en el escrutinio es
 * exactamente lo que la spec prohíbe.
 */
class PuestoResolver
{
    /**
     * Acentos y la eñe. Se traduce con tabla y no con `iconv('//TRANSLIT')`
     * porque el resultado de esa depende del locale del sistema, y aquí el
     * resultado tiene que ser el mismo en Windows, en el contenedor y en CI.
     *
     * La eñe entra a propósito: quien teclea «LA PENA» y quien teclea «LA PEÑA»
     * están hablando del mismo colegio.
     */
    private const TRADUCCION = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /**
     * El catálogo indexado por `norm(municipio)|norm(puesto)`.
     *
     * Se arma una vez por instancia: resolver acta por acta con un `SELECT` por
     * nombre normalizado obligaría a normalizar en SQL, que es justo lo que la
     * 0019 dejó dicho que no se hace (cada motor lo escribe distinto).
     *
     * @var array<string, int>|null
     */
    private ?array $catalogo = null;

    /**
     * Sin mayúsculas, sin acentos y con un solo espacio entre palabras.
     */
    public static function norm(?string $texto): string
    {
        $limpio = mb_strtolower(trim((string) $texto), 'UTF-8');
        $limpio = strtr($limpio, self::TRADUCCION);

        return (string) preg_replace('/\s+/u', ' ', $limpio);
    }

    /**
     * La mesa como entero: `005`, ` 5 ` y `5` son la misma mesa.
     *
     * Devuelve `null` cuando no hay número que leer —incluido el `0`, que no es
     * una mesa sino una casilla que se quedó sin llenar—, para que el cruce por
     * mesa no invente un grupo con todo lo que no se pudo leer.
     */
    public static function mesa(string|int|null $mesa): ?int
    {
        if (blank($mesa)) {
            return null;
        }

        $numero = (int) trim((string) $mesa);

        return $numero > 0 ? $numero : null;
    }

    /**
     * Clave de agrupación por nombre, para los dos lados del cruce.
     */
    public static function clave(?string $municipio, ?string $puesto): ?string
    {
        if (blank($municipio) || blank($puesto)) {
            return null;
        }

        return self::norm($municipio).'|'.self::norm($puesto);
    }

    /**
     * El puesto de un acta, creándolo en el catálogo si hace falta.
     *
     * Crear es correcto aquí y no en el lado del votante: el acta es el
     * documento oficial del puesto, así que si su lugar no está en el catálogo lo
     * que falta es el renglón, no el dato. Al votante solo se le busca — dar de
     * alta un puesto por un nombre que alguien escribió a mano en un formulario
     * llenaría el catálogo global de basura.
     */
    public function resolverActa(E14Acta $acta): ?int
    {
        return $this->resolver($acta->departamento, $acta->municipio, $acta->lugar, crear: true);
    }

    /**
     * Resolver de respaldo del votante: solo busca, nunca crea (Spec 0062).
     */
    public function buscarPorNombre(?string $municipio, ?string $puesto): ?int
    {
        return $this->resolver(null, $municipio, $puesto, crear: false);
    }

    /**
     * Resuelve el puesto de las actas que se guardaron antes de que existiera
     * esta columna, o antes de que su lugar estuviera en el catálogo.
     *
     * Lo llama el cruce antes de contar: el alternativa sería una migración de
     * datos que se queda corta con el primer acta que llegue después. Es
     * idempotente y solo mira las que están sin resolver.
     *
     * @return int cuántas quedaron resueltas
     */
    public function conciliarActas(?int $eventoId = null): int
    {
        $pendientes = E14Acta::query()
            ->whereNull('voting_place_id')
            ->whereNotNull('municipio')
            ->whereNotNull('lugar')
            ->when($eventoId, fn ($q) => $q->where('electoral_event_id', $eventoId))
            ->get(['id', 'departamento', 'municipio', 'lugar']);

        $resueltas = 0;

        foreach ($pendientes as $acta) {
            $puesto = $this->resolverActa($acta);

            if ($puesto === null) {
                continue;
            }

            // `saveQuietly` no: el cambio de puesto de un acta es un dato del
            // escrutinio y su auditoría vale lo que cuesta.
            $acta->voting_place_id = $puesto;
            $acta->save();
            $resueltas++;
        }

        return $resueltas;
    }

    private function resolver(?string $departamento, ?string $municipio, ?string $puesto, bool $crear): ?int
    {
        $clave = self::clave($municipio, $puesto);

        if ($clave === null) {
            return null;
        }

        $catalogo = $this->catalogo();

        if (isset($catalogo[$clave])) {
            return $catalogo[$clave];
        }

        // Sin departamento no se puede dar de alta: es parte de la clave natural
        // del catálogo y no se rellena con un placeholder. El acta se queda sin
        // puesto y aparece en la cobertura del cruce, que es lo honesto.
        if (! $crear || blank($departamento)) {
            return null;
        }

        $lugar = VotingPlace::firstOrCreate([
            'departamento_votacion' => $departamento,
            'municipio_votacion' => $municipio,
            'puesto_votacion' => $puesto,
        ]);

        $this->catalogo[$clave] = $lugar->id;

        return $lugar->id;
    }

    /**
     * @return array<string, int>
     */
    private function catalogo(): array
    {
        if ($this->catalogo !== null) {
            return $this->catalogo;
        }

        $this->catalogo = [];

        // El catálogo es global (sin `tenant_id`): a propósito, es el mismo mapa
        // de puestos del país para todas las campañas. Lo que es por tenant son
        // los votantes y las actas que apuntan a él.
        VotingPlace::query()
            ->select(['id', 'municipio_votacion', 'puesto_votacion'])
            ->orderBy('id')
            ->each(function (VotingPlace $lugar) {
                $clave = self::clave($lugar->municipio_votacion, $lugar->puesto_votacion);

                // El primero gana: dos renglones que normalizan igual son
                // justamente lo que la pantalla de conciliación resuelve, y
                // mientras nadie los fusione hay que elegir uno de forma estable.
                if ($clave !== null && ! isset($this->catalogo[$clave])) {
                    $this->catalogo[$clave] = $lugar->id;
                }
            });

        return $this->catalogo;
    }
}
