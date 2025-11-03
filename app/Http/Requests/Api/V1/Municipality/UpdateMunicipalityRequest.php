<?php

namespace App\Http\Requests\Api\V1\Municipality;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMunicipalityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $municipalityId = $this->route('municipality')->id;
        
        return [
            'department_id' => 'sometimes|exists:departments,id',
            'codigo' => 'sometimes|string|max:20|unique:municipalities,codigo,' . $municipalityId,
            'nombre' => 'sometimes|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.exists' => 'El departamento seleccionado no existe.',
            'codigo.unique' => 'Este código ya está registrado.',
            'latitude.between' => 'La latitud debe estar entre -90 y 90.',
            'longitude.between' => 'La longitud debe estar entre -180 y 180.',
        ];
    }
}
