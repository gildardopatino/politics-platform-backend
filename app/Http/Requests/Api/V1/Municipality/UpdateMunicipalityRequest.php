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
            'latitud' => 'nullable|numeric|between:-90,90',
            'longitud' => 'nullable|numeric|between:-180,180',
            'path' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.exists' => 'El departamento seleccionado no existe.',
            'codigo.unique' => 'Este código ya está registrado.',
            'latitud.between' => 'La latitud debe estar entre -90 y 90.',
            'longitud.between' => 'La longitud debe estar entre -180 y 180.',
            'path.string' => 'El path debe ser una cadena de texto válida.',
            'metadata.array' => 'La metadata debe ser un objeto JSON válido.',
        ];
    }
}
