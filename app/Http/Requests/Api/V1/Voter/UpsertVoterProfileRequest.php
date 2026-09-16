<?php

namespace App\Http\Requests\Api\V1\Voter;

use App\Models\VoterOccupation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertVoterProfileRequest extends FormRequest
{
    /**
     * El permiso `manage_voter_profiles` lo exige la ruta; el ámbito del votante
     * lo da el binding, ya acotado por `TenantScope`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'busca_empleo' => 'sometimes|boolean',
            'disponibilidad' => 'nullable|string|max:100',
            'nivel_educativo' => 'nullable|string|max:100',
            // 70 años de experiencia ya es un dato mal tecleado, no una vida
            // laboral.
            'anios_experiencia' => 'nullable|integer|min:0|max:70',
            'notas' => 'nullable|string|max:2000',
            'autoriza_tratamiento_datos' => 'sometimes|boolean',

            'oficios' => 'sometimes|array',
            // `exists` y no `firstOrCreate`: el formulario del votante nunca da
            // de alta un renglón del catálogo global (Spec 0094 §9).
            'oficios.*.occupation_id' => 'required|integer|exists:occupations,id',
            'oficios.*.relacion' => ['required', 'string', Rule::in(VoterOccupation::relaciones())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'oficios.*.occupation_id.exists' => 'El oficio seleccionado no existe en el catálogo.',
            'oficios.*.relacion.in' => 'La relación con el oficio debe ser «busca» o «experiencia».',
            'anios_experiencia.max' => 'Los años de experiencia deben estar entre 0 y 70.',
        ];
    }
}
