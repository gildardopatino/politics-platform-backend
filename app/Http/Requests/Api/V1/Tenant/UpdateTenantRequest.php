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
            'tipo_cargo' => 'sometimes|in:Gobernacion,Alcaldia,Concejo,Congresista,Diputado,Otro',
            'identificacion' => 'sometimes|string|max:50|unique:tenants,identificacion,' . $tenantId,
            'metadata' => 'nullable|array',
            'start_date' => 'nullable|date',
            'expiration_date' => 'nullable|date|after:start_date',
            
            // Theme colors
            'sidebar_bg_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'sidebar_text_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'header_bg_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'header_text_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'content_bg_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'content_text_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            
            // Hierarchy settings
            'hierarchy_mode' => 'nullable|in:disabled,simple_tree,multiple_supervisors,context_based',
            'auto_assign_hierarchy' => 'nullable|boolean',
            'hierarchy_conflict_resolution' => 'nullable|in:last_assignment,most_active,manual_review',
            'require_hierarchy_config' => 'nullable|boolean',
        ];
    }
}
