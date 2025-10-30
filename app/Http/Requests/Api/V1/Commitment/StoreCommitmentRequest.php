<?php

namespace App\Http\Requests\Api\V1\Commitment;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommitmentRequest extends FormRequest
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
            'meeting_id' => 'required|exists:meetings,id',
            'assigned_user_id' => 'required|exists:users,id',
            'priority_id' => 'required|exists:priorities,id',
            'description' => 'required|string',
            'due_date' => 'required|date',
            'status' => 'sometimes|in:pending,in_progress,completed,cancelled',
            'notes' => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'meeting_id.required' => 'El ID de la reunión es obligatorio',
            'meeting_id.exists' => 'La reunión seleccionada no existe',
            'assigned_user_id.required' => 'Debe asignar el compromiso a un usuario',
            'assigned_user_id.exists' => 'El usuario seleccionado no existe',
            'priority_id.required' => 'La prioridad es obligatoria',
            'priority_id.exists' => 'La prioridad seleccionada no existe',
            'description.required' => 'La descripción del compromiso es obligatoria',
            'due_date.required' => 'La fecha de vencimiento es obligatoria',
            'due_date.date' => 'La fecha de vencimiento debe ser una fecha válida',
        ];
    }
}
