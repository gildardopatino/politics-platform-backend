<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Acta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que el lector envía por cada acta (Spec 0061 · Parte A).
 *
 * `estado`, `suma_calculada` y `dif_nivelacion` se aceptan pero **no se
 * guardan tal cual**: el servidor los recalcula desde los números crudos. Se
 * reciben para poder compararlos, que es distinto de creerlos.
 *
 * Un acta que el lector no pudo transcribir se publica con
 * `estado=revision_manual` y sin cifras: así la mesa aparece en la cola de
 * revisión del panel en vez de quedar como si nadie la hubiera intentado.
 */
class StoreE14ActaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El permiso lo aplica `permission:manage_e14` en la ruta.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ilegible = $this->input('estado') === E14Acta::ESTADO_REVISION_MANUAL;
        $cifra = $ilegible ? 'nullable' : 'required';

        return [
            'tipo' => ['required', Rule::in(E14Acta::TIPOS)],
            'electoral_event_id' => 'nullable|integer',
            'evento_nombre' => 'nullable|string|max:255',
            'evento_fecha' => 'nullable|date',

            'departamento_code' => 'nullable|string|max:10',
            'municipio_code' => 'nullable|string|max:10',
            'zona' => 'required|string|max:10',
            'puesto' => 'required|string|max:10',
            'mesa' => 'required|string|max:10',
            'lugar' => 'nullable|string|max:255',

            'archivo_nombre' => 'nullable|string|max:255',
            'archivo_hash' => 'nullable|string|size:64|regex:/^[0-9a-f]{64}$/',

            // Solo los estados de un acta ya leída: la cola (`cargada`,
            // `pendiente`, `procesando`) la mueve el servidor, no el cliente.
            'estado' => ['nullable', Rule::in(E14Acta::ESTADOS_LEIDA)],
            'fuente' => ['nullable', Rule::in([E14Acta::FUENTE_VISION, E14Acta::FUENTE_MANUAL])],

            'suma_declarada' => $cifra.'|integer|min:0',
            'votos_urna' => $cifra.'|integer|min:0',
            'votantes_e11' => 'nullable|integer|min:0',
            'votos_blanco' => 'nullable|integer|min:0',
            'votos_nulos' => 'nullable|integer|min:0',
            'votos_no_marcados' => 'nullable|integer|min:0',
            'suma_calculada' => 'nullable|integer|min:0',
            'dif_nivelacion' => 'nullable|integer',

            'confianza' => 'nullable|numeric|between:0,100',
            'observacion' => 'nullable|string|max:2000',

            // Constancias de los jurados (Spec 0073). Se aceptan también por
            // esta puerta: el lector de carpeta local lee la misma página 2 que
            // el worker, y perderlas según por dónde entre el acta sería una
            // asimetría que nadie recordaría después.
            'hubo_recuento' => 'sometimes|nullable|boolean',
            'constancias' => 'sometimes|nullable|string|max:5000',
            'recuento_solicitado_por' => 'sometimes|nullable|string|max:255',
            'recuento_representacion' => 'sometimes|nullable|string|max:255',

            'resultados' => $ilegible ? 'nullable|array' : 'required|array|min:1',
            'resultados.*.numero' => 'required|integer|min:0|max:999|distinct',
            'resultados.*.nombre' => 'nullable|string|max:255',
            'resultados.*.votos' => 'required|integer|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zona.required' => 'Falta la zona de la mesa.',
            'puesto.required' => 'Falta el puesto de votación.',
            'mesa.required' => 'Falta el número de mesa.',
            'archivo_hash.regex' => 'El hash del archivo debe ser un SHA-256 en hexadecimal.',
            'resultados.required' => 'Un acta legible tiene que traer los votos por candidato.',
            'resultados.*.numero.distinct' => 'Un candidato no puede aparecer dos veces en la misma acta.',
            'resultados.*.votos.required' => 'Cada candidato del acta necesita su número de votos.',
        ];
    }
}
