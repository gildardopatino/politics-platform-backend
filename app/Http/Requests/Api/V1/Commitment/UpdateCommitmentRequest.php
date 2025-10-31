<?php

namespace App\Http\Requests\Api\V1\Commitment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommitmentRequest extends FormRequest
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
            'meeting_id' => 'sometimes|exists:meetings,id',
            'assigned_user_id' => 'sometimes|exists:users,id',
            'priority_id' => 'sometimes|exists:priorities,id',
            'description' => 'sometimes|string',
            'due_date' => 'sometimes|date',
            'status' => 'sometimes|in:scheduled,pending,in_progress,completed,cancelled,no_conmpleted',
            'notes' => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'meeting_id.exists' => 'La reunión seleccionada no existe',
            'assigned_user_id.exists' => 'El usuario seleccionado no existe',
            'priority_id.exists' => 'La prioridad seleccionada no existe',
            'due_date.date' => 'La fecha de vencimiento debe ser una fecha válida',
        ];
    }
}
