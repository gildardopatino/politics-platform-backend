<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Candidate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Fijar el candidato propio de una elección (Spec 0062 · Parte 0).
 *
 * `numero` admite `null` a propósito: es la forma de deshacer una configuración
 * mal puesta. Quien la borra deja el cruce sin fila que mirar, pero eso es mejor
 * que dejarlo apuntando a un número que no es.
 */
class UpdateCandidatoPropioRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero' => ['present', 'nullable', 'integer', 'min:1', 'max:999'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'agrupacion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Con actas cargadas el número tiene que existir en el tarjetón.
     *
     * Antes de la primera acta no hay catálogo contra el que comprobar y se
     * acepta a ciegas: el número del tarjetón se sabe semanas antes de que haya
     * un acta que leer, y obligar a esperar sería dejar la campaña sin configurar
     * justo cuando tiene tiempo de hacerlo. En cuanto el catálogo existe, un
     * número que no está en él es un error de dedo, no una elección: apuntaría el
     * cruce a una fila que ninguna acta va a traer.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $numero = $this->input('numero');
            $evento = $this->route('evento');

            if ($numero === null || $evento === null || $evento->actas()->doesntExist()) {
                return;
            }

            $existe = E14Candidate::where('electoral_event_id', $evento->id)
                ->where('numero', (int) $numero)
                ->exists();

            if (! $existe) {
                $validator->errors()->add(
                    'numero',
                    "El número {$numero} no aparece en el tarjetón de esta elección. "
                    .'Elígelo del catálogo de candidatos.'
                );
            }
        });
    }
}
