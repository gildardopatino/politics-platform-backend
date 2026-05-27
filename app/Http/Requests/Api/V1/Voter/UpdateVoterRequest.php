<?php

namespace App\Http\Requests\Api\V1\Voter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVoterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route-model-bound voter is authorized in the controller via the policy.
        return $this->user()?->can('update', $this->route('voter')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $voterId = $this->route('voter')?->id;

        return [
            'cedula' => [
                'required', 'string', 'max:20',
                Rule::unique('voters', 'cedula')
                    ->ignore($voterId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'telefono' => 'nullable|string|max:20',
            'direccion' => 'nullable|string|max:500',
            'barrio_id' => 'nullable|exists:barrios,id',
            'corregimiento_id' => 'nullable|exists:corregimientos,id',
            'vereda_id' => 'nullable|exists:veredas,id',
            'meeting_id' => 'nullable|exists:meetings,id',
            'departamento_votacion' => 'nullable|string|max:255',
            'municipio_votacion' => 'nullable|string|max:255',
            'puesto_votacion' => 'nullable|string|max:255',
            'direccion_votacion' => 'nullable|string|max:500',
            'mesa_votacion' => 'nullable|string|max:20',
            'tipo_votante_id' => 'nullable|exists:tipo_votante,id',
            'has_multiple_records' => 'nullable|boolean',
        ];
    }
}
