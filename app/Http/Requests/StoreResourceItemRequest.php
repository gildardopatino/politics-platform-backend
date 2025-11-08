<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|in:cash,furniture,vehicle,equipment,personnel,material,service,other',
            'unit' => 'required|string|max:50',
            'unit_cost' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'stock_quantity' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'supplier_contact' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del recurso es obligatorio',
            'category.required' => 'La categoría es obligatoria',
            'category.in' => 'La categoría debe ser una de: cash, furniture, vehicle, equipment, personnel, material, service, other',
            'unit.required' => 'La unidad de medida es obligatoria',
            'unit_cost.required' => 'El costo unitario es obligatorio',
            'unit_cost.numeric' => 'El costo unitario debe ser un número',
        ];
    }
}
