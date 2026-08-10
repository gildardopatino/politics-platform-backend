<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Services\E14\RendimientoLideresService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros del scorecard de líderes (Spec 0063 · Parte A).
 *
 * No hay `nivel`: el proxy solo tiene sentido por mesa. A nivel de puesto la
 * gente de un líder se diluye entre todas las mesas del colegio y el número
 * deja de decir nada de él.
 */
class RendimientoLideresRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', 'integer'],
            'order' => ['nullable', Rule::in(RendimientoLideresService::ORDENES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order.in' => 'El ranking se ordena por déficit o por actividad.',
        ];
    }
}
