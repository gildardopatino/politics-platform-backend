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
            'meeting_id' => 'required|exists:meetings,id',
            'leader_user_id' => 'required|exists:users,id',
            'type' => 'required|in:cash,material,service',
            'descripcion' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'fecha_asignacion' => 'required|date',
        ];
    }
}
