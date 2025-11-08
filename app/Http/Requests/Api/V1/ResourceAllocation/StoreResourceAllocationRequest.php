<?php

namespace App\Http\Requests\Api\V1\ResourceAllocation;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceAllocationRequest extends FormRequest
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
            'meeting_id' => 'nullable|exists:meetings,id',
            'leader_user_id' => 'required|exists:users,id',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            
            // Campos nuevos (recomendados)
            'title' => 'nullable|string|max:255',
            'allocation_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.resource_item_id' => 'required_with:items|exists:resource_items,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
            'items.*.metadata' => 'nullable|array',
            
            // Campos legacy (compatibilidad hacia atrás)
            'type' => 'nullable|in:cash,material,service',
            'descripcion' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'fecha_asignacion' => 'nullable|date',
            'details' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'meeting_id.exists' => 'La reunión especificada no existe',
            'leader_user_id.required' => 'El líder es obligatorio',
            'leader_user_id.exists' => 'El líder especificado no existe',
            'items.*.resource_item_id.required_with' => 'Cada item debe tener un resource_item_id',
            'items.*.resource_item_id.exists' => 'El recurso especificado no existe en el catálogo',
            'items.*.quantity.required_with' => 'Cada item debe tener una cantidad',
            'items.*.quantity.numeric' => 'La cantidad debe ser un número',
        ];
    }
}
