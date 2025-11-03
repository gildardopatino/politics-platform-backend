<?php

namespace App\Http\Requests\Api\V1\Corregimiento;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCorregimientoRequest extends FormRequest
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
            'municipality_id' => 'sometimes|required|exists:municipalities,id',
            'codigo' => 'sometimes|required|string|max:20|unique:corregimientos,codigo,' . $this->route('corregimiento')->id,
            'nombre' => 'sometimes|required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ];
    }

    public function messages(): array
    {
        return [
            'municipality_id.required' => 'El municipio es obligatorio.',
            'codigo.required' => 'El código es obligatorio.',
            'codigo.unique' => 'Este código ya está registrado.',
            'nombre.required' => 'El nombre es obligatorio.',
        ];
    }
}
