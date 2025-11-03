<?php

namespace App\Http\Requests\Api\V1\Commune;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommuneRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422)
        );
    }

    public function rules(): array
    {
        return [
            'municipality_id' => 'required|exists:municipalities,id',
            'codigo' => 'required|string|max:20|unique:communes,codigo',
            'nombre' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ];
    }

    public function messages(): array
    {
        return [
            'municipality_id.required' => 'El municipio es obligatorio.',
            'municipality_id.exists' => 'El municipio seleccionado no existe.',
            'codigo.required' => 'El código es obligatorio.',
            'codigo.unique' => 'Este código ya está registrado.',
            'nombre.required' => 'El nombre es obligatorio.',
            'latitude.between' => 'La latitud debe estar entre -90 y 90.',
            'longitude.between' => 'La longitud debe estar entre -180 y 180.',
        ];
    }
}
