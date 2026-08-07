<?php

namespace App\Rules;

use App\Models\Meeting;
use App\Models\MeetingTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Los `extra_fields` de una asistencia, contra los campos que declara la
 * plantilla de la reunión (Spec 0023, hallazgo F2 de la caracterización 0010).
 *
 * Antes `extra_fields` se validaba como `nullable|array` y nada más: la
 * obligatoriedad la aplicaba solo el formulario del frontend, así que llamar a
 * la API directamente permitía inventarse un campo u omitir uno requerido. Esta
 * regla es la **única** fuente de esa validación y la comparten las dos vías de
 * alta: el check-in público por QR y el alta autenticada desde el panel.
 *
 * Reglas, dada `meeting_templates.fields`:
 *
 * - **Sin plantilla, sin contrato.** Una reunión sin `template_id` acepta
 *   cualquier `extra_fields`, como hasta ahora. Una plantilla que existe pero no
 *   declara campos sí es un contrato: cualquier clave sobra.
 * - **La clave vale por `name` o por `label`.** Las plantillas guardan los dos y
 *   el frontend ha usado ambos según la pantalla; admitir solo uno invalidaría
 *   la asistencia ya capturada.
 * - **Obligatorio es tener valor**, no tener la clave: `""`, `null` y `[]` no
 *   cumplen un `required`. `false` y `0` sí (un checkbox sin marcar es una
 *   respuesta).
 * - **Las opciones se comprueban** en `select`, `radio` y `checkbox` cuando la
 *   plantilla las declara. Un `checkbox` admite varias: se valida cada una.
 *
 * Los mensajes van en español y nombran el campo por su etiqueta (Art. IX).
 */
class CamposDeLaPlantilla implements ValidationRule
{
    /**
     * Tipos cuyo valor tiene que salir de `options`.
     */
    private const TIPOS_CON_OPCIONES = ['select', 'radio', 'checkbox'];

    /**
     * Implícita: sin esto Laravel no correría la regla cuando `extra_fields` no
     * viene en la petición, y omitir la clave entera es justo la forma más
     * cómoda de saltarse un campo obligatorio.
     */
    public bool $implicit = true;

    public function __construct(private ?MeetingTemplate $plantilla) {}

    /**
     * La plantilla la fija la reunión —el QR en el flujo público, el binding de
     * ruta en el autenticado—, nunca el payload.
     */
    public static function paraLaReunion(?Meeting $reunion): self
    {
        return new self($reunion?->template);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->plantilla === null) {
            return;
        }

        if ($value !== null && ! is_array($value)) {
            // Lo reporta la regla `array`; aquí no hay nada que comprobar.
            return;
        }

        $respuestas = $value ?? [];
        $campos = $this->camposDeclarados();

        foreach (array_keys($respuestas) as $clave) {
            if (! $this->estaDeclarada((string) $clave, $campos)) {
                $fail("El campo «{$clave}» no está declarado en la plantilla de la reunión.");
            }
        }

        foreach ($campos as $campo) {
            $this->validarCampo($campo, $respuestas, $fail);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function camposDeclarados(): array
    {
        return array_values(array_filter(
            (array) ($this->plantilla->fields ?? []),
            fn ($campo) => is_array($campo)
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $campos
     */
    private function estaDeclarada(string $clave, array $campos): bool
    {
        foreach ($campos as $campo) {
            if (in_array($clave, $this->clavesDe($campo), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $campo
     * @param  array<string, mixed>  $respuestas
     */
    private function validarCampo(array $campo, array $respuestas, Closure $fail): void
    {
        $etiqueta = $this->etiquetaDe($campo);
        $obligatorio = filter_var($campo['required'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $clave = null;

        foreach ($this->clavesDe($campo) as $candidata) {
            if (array_key_exists($candidata, $respuestas)) {
                $clave = $candidata;
                break;
            }
        }

        $valor = $clave === null ? null : $respuestas[$clave];

        if ($this->estaVacio($valor)) {
            if ($obligatorio) {
                $fail("El campo «{$etiqueta}» es obligatorio.");
            }

            return;
        }

        $this->validarOpciones($campo, $etiqueta, $valor, $fail);
    }

    /**
     * @param  array<string, mixed>  $campo
     */
    private function validarOpciones(array $campo, string $etiqueta, mixed $valor, Closure $fail): void
    {
        $tipo = strtolower((string) ($campo['type'] ?? ''));

        if (! in_array($tipo, self::TIPOS_CON_OPCIONES, true)) {
            return;
        }

        $opciones = array_values(array_filter(
            (array) ($campo['options'] ?? []),
            fn ($opcion) => is_scalar($opcion)
        ));

        if ($opciones === []) {
            return;
        }

        $permitidas = array_map(fn ($opcion) => (string) $opcion, $opciones);

        // Un checkbox de selección múltiple llega como lista; el resto, suelto.
        foreach (is_array($valor) ? $valor : [$valor] as $elegido) {
            if (is_scalar($elegido) && in_array((string) $elegido, $permitidas, true)) {
                continue;
            }

            $texto = is_scalar($elegido) ? (string) $elegido : 'El valor enviado';

            $fail("«{$texto}» no es una opción válida de «{$etiqueta}». Opciones: ".implode(', ', $permitidas).'.');
        }
    }

    /**
     * `false` y `0` son respuestas; `""`, `null` y `[]` son la ausencia de una.
     */
    private function estaVacio(mixed $valor): bool
    {
        return $valor === null || $valor === '' || $valor === [];
    }

    /**
     * @param  array<string, mixed>  $campo
     * @return array<int, string>
     */
    private function clavesDe(array $campo): array
    {
        $claves = [];

        foreach (['name', 'label'] as $atributo) {
            $clave = $campo[$atributo] ?? null;

            if (is_scalar($clave) && (string) $clave !== '') {
                $claves[] = (string) $clave;
            }
        }

        return array_values(array_unique($claves));
    }

    /**
     * Lo que se le enseña a quien rellena el formulario: el `label` si existe.
     *
     * @param  array<string, mixed>  $campo
     */
    private function etiquetaDe(array $campo): string
    {
        foreach (['label', 'name'] as $atributo) {
            $etiqueta = $campo[$atributo] ?? null;

            if (is_scalar($etiqueta) && (string) $etiqueta !== '') {
                return (string) $etiqueta;
            }
        }

        return 'campo dinámico';
    }
}
