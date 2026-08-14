<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Services\E14\ProyeccionService;
use App\Services\E14\RegistradosService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros de la proyección (Spec 0064 · RF-2).
 *
 * No hay `voting_place`: la proyección se lee de arriba abajo —global, municipio,
 * puesto— y el corte fino de un puesto concreto es la pregunta del cruce, no la
 * de «¿voy ganando?».
 */
class ProyeccionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', 'integer'],
            'nivel' => ['nullable', Rule::in(ProyeccionService::NIVELES)],
            'municipio' => ['nullable', 'string', 'max:255'],
            // La misma base de la 0076: si el cruce cuenta leads, la proyección
            // tiene que poder contarlos igual o los dos paneles no cuadran.
            'incluir' => ['nullable', Rule::in(RegistradosService::INCLUIR)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nivel.in' => 'La proyección se ve global, por municipio o por puesto.',
            'incluir.in' => 'La base se cuenta de votantes, de leads o de ambos.',
        ];
    }
}
