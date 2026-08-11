<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Acta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Lo que el worker devuelve de un acta que reclamó (Spec 0071).
 *
 * Igual que en la ingesta directa, `estado` y `suma_calculada` se aceptan pero
 * no se guardan tal cual: el servidor rehace la cuenta. La diferencia es que
 * aquí el acta ya existe —alguien subió su PDF— y lo que llega es de qué mesa
 * resultó ser y qué decía.
 */
class StoreE14ResultadoRequest extends FormRequest
{
    use ValidaListasDeCorporacion;

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

        // Aquí el tipo no viaja en el cuerpo —el acta ya existe y el tipo es
        // suyo, lo eligió quien subió el PDF—, así que se lee de la ruta. Es lo
        // que decide si lo que llega son candidatos o agrupaciones (Spec 0067).
        $acta = $this->route('acta');
        $corporacion = $acta instanceof E14Acta && $acta->es_corporacion;

        return [
            // El worker solo puede decir dos cosas: «esto leí» o «no pude».
            'estado' => ['nullable', Rule::in([
                E14Acta::ESTADO_PROCESADA,
                E14Acta::ESTADO_INCONSISTENTE,
                E14Acta::ESTADO_REVISION_MANUAL,
            ])],

            'departamento_code' => 'nullable|string|max:10',
            'departamento' => 'nullable|string|max:255',
            'municipio_code' => 'nullable|string|max:10',
            'municipio' => 'nullable|string|max:255',
            'zona' => $cifra.'|string|max:10',
            'puesto' => $cifra.'|string|max:10',
            'mesa' => $cifra.'|string|max:10',
            'lugar' => 'nullable|string|max:255',

            'fuente' => ['nullable', Rule::in([E14Acta::FUENTE_VISION, E14Acta::FUENTE_MANUAL])],
            'confianza' => 'nullable|numeric|between:0,100',
            'observacion' => 'nullable|string|max:2000',

            // Constancias de los jurados, página 2 del acta (Spec 0073). Son
            // informativas: se guardan aunque el resto no se haya podido leer, y
            // no entran en el cuadre.
            'hubo_recuento' => 'sometimes|nullable|boolean',
            'constancias' => 'sometimes|nullable|string|max:5000',
            'recuento_solicitado_por' => 'sometimes|nullable|string|max:255',
            'recuento_representacion' => 'sometimes|nullable|string|max:255',

            // En corporación esa casilla no existe en el papel (Spec 0067).
            'suma_declarada' => ($corporacion ? 'nullable' : $cifra).'|integer|min:0',
            'votos_urna' => $cifra.'|integer|min:0',
            'votantes_e11' => 'nullable|integer|min:0',
            'votos_blanco' => 'nullable|integer|min:0',
            'votos_nulos' => 'nullable|integer|min:0',
            'votos_no_marcados' => 'nullable|integer|min:0',
            'suma_calculada' => 'nullable|integer|min:0',
            'dif_nivelacion' => 'nullable|integer',

            // Uninominal: un candidato por renglón (Spec 0061).
            'resultados' => $ilegible || $corporacion ? 'nullable|array' : 'required|array|min:1',
            'resultados.*.numero' => 'required|integer|min:0|max:999|distinct',
            'resultados.*.nombre' => 'nullable|string|max:255',
            'resultados.*.votos' => 'required|integer|min:0',

            // Corporación: agrupaciones con voto preferente (Spec 0067).
            ...$this->reglasDeListas($ilegible, $corporacion),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after($this->validarPreferentesPorLista(...));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mesa.required' => 'Falta el número de mesa que leyó el acta.',
            'resultados.required' => 'Un acta legible tiene que traer los votos por candidato.',
            'resultados.*.numero.distinct' => 'Un candidato no puede aparecer dos veces en la misma acta.',
            ...$this->mensajesDeListas(),
        ];
    }
}
