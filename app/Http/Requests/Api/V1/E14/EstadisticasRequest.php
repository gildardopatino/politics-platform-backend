<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Acta;
use App\Services\E14\CruceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros de las estadísticas del escrutinio (Spec 0092 · Parte A).
 *
 * `tipo` era obligatorio en la 0092 —la página se abría eligiendo elección— y
 * desde la 0093 **se ignora**: la campaña tiene una sola elección y sale del
 * tenant. Se sigue aceptando para no romper a un cliente viejo, pero no cambia
 * la respuesta; mandar `?tipo=gobernacion` desde una campaña de alcaldía
 * devuelve alcaldía. Los demás son los mismos filtros del cruce, porque es el
 * mismo cálculo mirado de otra forma.
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
            'tipo' => ['nullable', Rule::in(E14Acta::TIPOS)],
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
            'tipo.in' => 'Esa elección no existe: '.implode(', ', E14Acta::TIPOS).'.',
            'incluir.in' => 'La base se cuenta de votantes, de leads o de ambos.',
        ];
    }
}
