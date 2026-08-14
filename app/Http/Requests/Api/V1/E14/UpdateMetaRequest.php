<?php

namespace App\Http\Requests\Api\V1\E14;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fijar la meta de votos (Spec 0064 · RF-1).
 *
 * Las dos mitades son **opcionales por separado**: mandar solo `puestos` deja la
 * global como estaba, y al revés. Es un `PUT` parcial a propósito — la pantalla
 * edita una casilla a la vez, y obligar a reenviar todo convertiría cualquier
 * ajuste en una oportunidad de pisar lo que otro acababa de escribir.
 *
 * `meta_votos: null` **sí** viaja y significa «quítala»: la ausencia de la clave y
 * el nulo explícito son cosas distintas.
 */
class UpdateMetaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', 'integer'],
            // `present` no: si no viene, no se toca. El tope de 10 millones es
            // un cinturón contra el dedazo, no un límite electoral.
            'meta_votos' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'puestos' => ['nullable', 'array', 'max:5000'],
            // El catálogo de puestos es global (no lleva `tenant_id`), así que
            // el `exists` va sin acotar: lo que acota la escritura es la
            // elección, que sí es del tenant.
            'puestos.*.voting_place_id' => ['required', 'integer', 'exists:voting_places,id'],
            'puestos.*.meta_votos' => ['present', 'nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meta_votos.min' => 'La meta de votos no puede ser negativa.',
            'meta_votos.max' => 'Esa meta de votos no parece real; revisa el número.',
            'puestos.*.voting_place_id.exists' => 'Ese puesto de votación no está en el catálogo.',
            'puestos.*.meta_votos.present' => 'Manda la meta del puesto (o `null` para quitarla).',
            'puestos.*.meta_votos.min' => 'La meta de un puesto no puede ser negativa.',
        ];
    }
}
