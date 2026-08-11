<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Acta;
use App\Models\E14Candidate;
use App\Models\Tenant;
use App\Services\E14\CatalogoDeCorporacion;
use App\Services\E14\EventoResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Fijar el candidato de la campaña (Specs 0080 y 0082).
 *
 * Lo que se manda depende del cargo:
 *
 * - **Uninominal** (alcaldía, gobernación): el `numero` del tarjetón y ya.
 * - **Corporación** (concejo, asamblea, senado): el par `lista_numero` +
 *   `numero`, donde el segundo es el **número de preferencia** dentro de esa
 *   lista. Un preferente suelto no identifica a nadie — el 5 existe en todas las
 *   listas—, así que la lista es obligatoria.
 *
 * El nombre nunca viaja: la identidad es la del tenant, y volver a pedirla aquí
 * es lo que permitía que la ficha del escrutinio dijera una cosa y la
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
            // Hasta cinco cifras: los números de lista llegan a 5170 o 6497.
            // Solo aplica a corporación; en uninominal se ignora.
            'lista_numero' => ['nullable', 'integer', 'min:1', 'max:99999'],
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

            // Borrar no se valida: es la salida de una configuración mal puesta
            // y tiene que funcionar aunque lo guardado ya no exista.
            if ($numero === null) {
                return;
            }

            if (E14Acta::esCorporacion($resolver->tipoDelCargo($tenant->tipo_cargo))) {
                $this->validarCorporacion($validator, $evento, (int) $numero);

                return;
            }

            if ($evento === null || $evento->actas()->doesntExist()) {
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

    /**
     * El par `(lista, preferente)` de una corporación (Spec 0082).
     *
     * La lista va primero porque sin ella el preferente no identifica a nadie, y
     * porque el error tiene que señalar el campo que falla: si la lista no
     * existe, decir «ese preferente no está» mandaría a buscar en el sitio
     * equivocado.
     *
     * Los **dos momentos** de la 0062, al nivel del par: sin catálogo se aceptan
     * a ciegas —el número de lista y el de preferencia se saben semanas antes de
     * la primera acta— y en cuanto hay catálogo, un par que no está en él es un
     * error de dedo que dejaría el cruce mirando una fila que ninguna acta trae.
     */
    private function validarCorporacion(Validator $validator, $evento, int $numero): void
    {
        $lista = $this->input('lista_numero');

        if ($lista === null || $lista === '') {
            $validator->errors()->add(
                'lista_numero',
                'En una corporación el candidato es una persona dentro de una lista: '
                .'indica también el número de la lista.'
            );

            return;
        }

        $catalogo = app(CatalogoDeCorporacion::class);
        $tarjeton = $catalogo->paraEvento($evento);

        // Sin actas procesadas no hay contra qué comprobar: primer momento.
        if ($tarjeton === []) {
            return;
        }

        if (! $catalogo->tieneLista($tarjeton, (int) $lista)) {
            $validator->errors()->add(
                'lista_numero',
                "La lista {$lista} no aparece en el tarjetón de esta elección. "
                .'Elígela del catálogo de listas.'
            );

            return;
        }

        if (! $catalogo->tienePreferente($tarjeton, (int) $lista, $numero)) {
            $validator->errors()->add(
                'numero',
                "El número de preferencia {$numero} no aparece en la lista {$lista}. "
                .'Elígelo del catálogo de esa lista.'
            );
        }
    }
}
