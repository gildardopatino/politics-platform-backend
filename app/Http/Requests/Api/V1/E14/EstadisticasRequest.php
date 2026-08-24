<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Acta;
use App\Services\E14\CruceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros de las estadísticas del escrutinio (Spec 0092 · Parte A).
 *
 * `tipo` es obligatorio y no tiene valor por defecto: una campaña puede tener
 * alcaldía y concejo cargados a la vez, y «la última elección» sería una
 * respuesta correcta a una pregunta que nadie hizo. Los demás son los mismos
 * filtros del cruce, porque es el mismo cálculo mirado de otra forma.
 *
 * `nivel` **no** se acepta: la fila de esta página es la mesa, y de ahí hacia
 * arriba pliega el cliente.
 */
class EstadisticasRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(E14Acta::TIPOS)],
            'event' => ['nullable', 'integer'],
            'municipio' => ['nullable', 'string', 'max:255'],
            'voting_place' => ['nullable', 'string', 'max:255'],
            'incluir' => ['nullable', Rule::in(CruceService::INCLUIR)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Indica de qué elección quieres las estadísticas.',
            'tipo.in' => 'Esa elección no existe: '.implode(', ', E14Acta::TIPOS).'.',
            'incluir.in' => 'La base se cuenta de votantes, de leads o de ambos.',
        ];
    }
}
