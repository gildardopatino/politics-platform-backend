<?php

namespace App\Http\Requests\Api\V1\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
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
        $tenantId = $this->route('tenant')->id;

        return [
            'slug' => 'sometimes|string|max:255|unique:tenants,slug,' . $tenantId,
            'nombre' => 'sometimes|string|max:255',
            'tipo_cargo' => 'sometimes|in:alcalde,gobernador,senador,representante,concejal,otro',
            'identificacion' => 'sometimes|string|max:50|unique:tenants,identificacion,' . $tenantId,
            'metadata' => 'nullable|array',
        ];
    }
}
