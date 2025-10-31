<?php

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
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
        $tenantId = app('tenant')->id;

        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'unique:users,email,NULL,id,tenant_id,' . $tenantId
            ],
            // Password is optional - will be auto-generated if not provided
            'phone' => 'nullable|string|max:20',
            'cedula' => 'nullable|string|max:20',
            'is_team_leader' => 'nullable|boolean',
            'reports_to' => 'nullable|exists:users,id',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
        ];
    }
}
