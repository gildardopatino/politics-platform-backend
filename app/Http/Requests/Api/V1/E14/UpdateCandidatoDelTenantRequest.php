<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Candidate;
use App\Models\Tenant;
use App\Services\E14\EventoResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Fijar el número del candidato de la campaña (Spec 0080).
 *
 * Lo que se manda es **solo** el número (y la agrupación, si el tarjetón no la
 * trae). El nombre no viaja: la identidad es la del tenant, y volver a pedirla
 * aquí es lo que permitía que la ficha del escrutinio dijera una cosa y la
 * configuración de la campaña, otra.
 *
 * `numero` admite `null` a propósito: es la forma de deshacer una configuración
 * mal puesta. Quien la borra deja el cruce sin fila que mirar, pero eso es mejor
 * que dejarlo apuntando a un número que no es.
 */
class UpdateCandidatoDelTenantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero' => ['present', 'nullable', 'integer', 'min:1', 'max:999'],
            'agrupacion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Dos comprobaciones, en este orden.
     *
     * **El cargo primero.** Sin un `tipo_cargo` que mapee a un tipo de elección
     * no hay dónde escribir el número, y adivinarlo lo pondría en la elección
     * equivocada — que es peor que no configurarlo. Se responde 422 diciendo qué
     * falta.
     *
     * **Los dos momentos después** (regla de la 0062, intacta). Antes de la
     * primera acta no hay catálogo contra el que comprobar y el número se acepta
     * a ciegas: se sabe semanas antes de que haya un acta que leer, y obligar a
     * esperar dejaría la campaña sin configurar justo cuando tiene tiempo de
     * hacerlo. En cuanto el catálogo existe, un número que no está en él es un
     * error de dedo: apuntaría el cruce a una fila que ninguna acta va a traer.
     *
     * Aquí la elección se **busca**, no se crea: una petición que va a fallar no
     * puede dejar una elección dada de alta detrás.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tenant = app()->bound('tenant') ? app('tenant') : null;

            if (! $tenant instanceof Tenant) {
                return;
            }

            $resolver = app(EventoResolver::class);

            if ($resolver->tipoDelCargo($tenant->tipo_cargo) === null) {
                $validator->errors()->add(
                    'cargo',
                    'La campaña no tiene un cargo de elección popular configurado '
                    .'(«'.($tenant->tipo_cargo ?: 'sin cargo').'»), así que no hay elección '
                    .'donde fijar el número. Configura el cargo de la campaña primero.'
                );

                return;
            }

            $numero = $this->input('numero');
            $evento = $resolver->delCargo($tenant);

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
