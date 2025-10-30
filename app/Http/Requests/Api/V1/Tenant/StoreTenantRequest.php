<?php

namespace App\Http\Requests\Api\V1\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
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
            'slug' => 'required|string|max:255|unique:tenants,slug',
            'nombre' => 'required|string|max:255',
            'tipo_cargo' => 'required|in:alcalde,gobernador,senador,representante,concejal,otro',
            'identificacion' => 'required|string|max:50|unique:tenants,identificacion',
            'metadata' => 'nullable|array',
        ];
    }
}
