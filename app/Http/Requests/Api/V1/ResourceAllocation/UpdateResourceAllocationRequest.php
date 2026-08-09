<?php

namespace App\Http\Requests\Api\V1\ResourceAllocation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResourceAllocationRequest extends FormRequest
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
            'leader_user_id' => 'sometimes|exists:users,id',
            'type' => 'sometimes|in:cash,material,service',
            // `status` faltaba, y sin él `validated()` lo descartaba: el ciclo
            // de inventario del controlador era inalcanzable (Spec 0056, H3).
            'status' => 'sometimes|in:pending,delivered,returned,cancelled',
            'cash_purpose' => 'sometimes|nullable|string',
            'descripcion' => 'sometimes|string',
            'amount' => 'sometimes|numeric|min:0',
            'fecha_asignacion' => 'sometimes|date',
        ];
    }
}
