<?php

namespace App\Http\Requests\Api\V1\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only authenticated users with a tenant can update settings
        return $this->user() && $this->user()->tenant_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Visual settings
            'logo' => 'nullable|string|max:255',
            'sidebar_bg_color' => 'nullable|string|regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            'sidebar_text_color' => 'nullable|string|regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            'header_bg_color' => 'nullable|string|regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            'header_text_color' => 'nullable|string|regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            'content_bg_color' => 'nullable|string|regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            'content_text_color' => 'nullable|string|regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            
            // Hierarchy settings
            'hierarchy_mode' => 'nullable|in:disabled,simple_tree,multiple_supervisors,context_based',
            'auto_assign_hierarchy' => 'nullable|boolean',
            'hierarchy_conflict_resolution' => 'nullable|in:last_assignment,most_active,manual_review',
            'require_hierarchy_config' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            // Visual settings
            'logo' => 'logo',
            'sidebar_bg_color' => 'color de fondo del sidebar',
            'sidebar_text_color' => 'color de texto del sidebar',
            'header_bg_color' => 'color de fondo del header',
            'header_text_color' => 'color de texto del header',
            'content_bg_color' => 'color de fondo del contenido',
            'content_text_color' => 'color de texto del contenido',
            
            // Hierarchy settings
            'hierarchy_mode' => 'modo de jerarquía',
            'auto_assign_hierarchy' => 'asignación automática de jerarquía',
            'hierarchy_conflict_resolution' => 'resolución de conflictos de jerarquía',
            'require_hierarchy_config' => 'requerir configuración de jerarquía',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            '*.regex' => 'El :attribute debe ser un color hexadecimal válido (ej: #ffffff o #fff).',
        ];
    }
}
