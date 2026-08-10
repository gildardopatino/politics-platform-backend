<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Services\E14\CruceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros del cruce (Spec 0062 · Parte A).
 *
 * `voting_place` acepta el id o un trozo del nombre: son las dos formas de
 * preguntar por lo mismo —la lista y la casilla de búsqueda—, igual que los
 * filtros de ubicación de la 0074.
 */
class CruceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', 'integer'],
            'nivel' => ['nullable', Rule::in([CruceService::NIVEL_PUESTO, CruceService::NIVEL_MESA])],
            'municipio' => ['nullable', 'string', 'max:255'],
            'voting_place' => ['nullable', 'string', 'max:255'],
            'incluir' => ['nullable', Rule::in(CruceService::INCLUIR)],
        ];
    }

    public function messages(): array
    {
        return [
            'nivel.in' => 'El cruce se puede ver por puesto o por mesa.',
            'incluir.in' => 'Los registrados se cuentan de votantes, de leads o de ambos.',
        ];
    }
}
