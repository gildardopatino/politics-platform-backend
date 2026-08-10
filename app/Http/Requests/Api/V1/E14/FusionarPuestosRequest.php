<?php

namespace App\Http\Requests\Api\V1\E14;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fusionar un nombre de puesto en otro (Spec 0062 · Parte A).
 *
 * El origen viene de una de dos formas, que son los dos casos de la lista de
 * pendientes: un renglón del catálogo (`origen_id`) o un nombre suelto que nunca
 * llegó a tener renglón (`municipio` + `puesto`).
 */
class FusionarPuestosRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $existe = fn () => Rule::exists('voting_places', 'id')->whereNull('deleted_at');

        return [
            'destino_id' => ['required', 'integer', $existe()],
            'origen_id' => ['nullable', 'integer', 'different:destino_id', $existe()],
            'municipio' => ['nullable', 'string', 'max:255', 'required_without:origen_id'],
            'puesto' => ['nullable', 'string', 'max:255', 'required_without:origen_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destino_id.required' => 'Elige a qué puesto se fusiona.',
            'destino_id.exists' => 'El puesto de destino no existe.',
            'origen_id.exists' => 'El puesto que quieres fusionar no existe.',
            'origen_id.different' => 'Un puesto no se puede fusionar consigo mismo.',
            'municipio.required_without' => 'Indica el municipio del nombre que quieres fusionar.',
            'puesto.required_without' => 'Indica el nombre del puesto que quieres fusionar.',
        ];
    }
}
