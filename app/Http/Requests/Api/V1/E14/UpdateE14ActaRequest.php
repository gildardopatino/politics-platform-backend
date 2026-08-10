<?php

namespace App\Http\Requests\Api\V1\E14;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corrección manual de un acta (Spec 0061 · Parte A).
 *
 * Es lo que hace una persona después de mirar el papel. Al aplicarse queda
 * `fuente=manual` y el cuadre se vuelve a evaluar con las cifras corregidas: si
 * ahora cuadra, el acta entra al consolidado; si no, sigue fuera. Corregir no es
 * lo mismo que aprobar.
 */
class UpdateE14ActaRequest extends FormRequest
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
        return [
            'departamento_code' => 'sometimes|nullable|string|max:10',
            'departamento' => 'sometimes|nullable|string|max:255',
            'municipio_code' => 'sometimes|nullable|string|max:10',
            'municipio' => 'sometimes|nullable|string|max:255',
            'lugar' => 'sometimes|nullable|string|max:255',

            'suma_declarada' => 'sometimes|integer|min:0',
            'votos_urna' => 'sometimes|integer|min:0',
            'votantes_e11' => 'sometimes|integer|min:0',
            'votos_blanco' => 'sometimes|integer|min:0',
            'votos_nulos' => 'sometimes|integer|min:0',
            'votos_no_marcados' => 'sometimes|integer|min:0',

            'observacion' => 'sometimes|nullable|string|max:2000',

            // Lo que los jurados dejaron escrito. En revisión manual se puede
            // completar: es habitual que la visión no acierte con la letra y la
            // persona que mira el papel sí (Spec 0073).
            'hubo_recuento' => 'sometimes|nullable|boolean',
            'constancias' => 'sometimes|nullable|string|max:5000',
            'recuento_solicitado_por' => 'sometimes|nullable|string|max:255',
            'recuento_representacion' => 'sometimes|nullable|string|max:255',

            'resultados' => 'sometimes|array|min:1',
            'resultados.*.numero' => 'required_with:resultados|integer|min:0|max:999|distinct',
            'resultados.*.nombre' => 'nullable|string|max:255',
            'resultados.*.votos' => 'required_with:resultados|integer|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resultados.*.numero.distinct' => 'Un candidato no puede aparecer dos veces en la misma acta.',
        ];
    }
}
