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
            'slug' => 'sometimes|string|max:255|unique:tenants,slug,'.$tenantId,
            'nombre' => 'sometimes|string|max:255',
            // `tipo_cargo` **no está** y no es un olvido (Spec 0093): es la
            // elección de la campaña, y de ella cuelga todo el escrutinio —las
            // actas cargadas, la elección donde vive «mi candidato», el cruce,
            // el consolidado—. Cambiarlo después dejaría un tenant de alcaldía
            // con actas de alcaldía diciendo que es de concejo, y el sistema
            // rechazándolas por no ser de «su» elección. Se fija al crear la
            // campaña (superadmin) y ahí se queda; quien necesite otra elección
            // abre otro tenant. Al no estar en las reglas, no llega a
            // `validated()` y el `update()` del controlador no lo ve.
            'identificacion' => 'sometimes|string|max:50|unique:tenants,identificacion,'.$tenantId,
            'email_contacto' => 'sometimes|email|max:255',
            'phone_contacto' => 'nullable|string|max:30',
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
