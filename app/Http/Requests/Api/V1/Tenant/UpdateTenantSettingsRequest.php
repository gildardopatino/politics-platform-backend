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
     * Prepare the data for validation - Convert FormData strings to proper types
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        // Convert boolean strings to actual booleans
        if ($this->has('auto_assign_hierarchy')) {
            $data['auto_assign_hierarchy'] = filter_var(
                $this->input('auto_assign_hierarchy'), 
                FILTER_VALIDATE_BOOLEAN, 
                FILTER_NULL_ON_FAILURE
            );
        }

        if ($this->has('require_hierarchy_config')) {
            $data['require_hierarchy_config'] = filter_var(
                $this->input('require_hierarchy_config'), 
                FILTER_VALIDATE_BOOLEAN, 
                FILTER_NULL_ON_FAILURE
            );
        }

        if ($this->has('send_logistics_notifications')) {
            $data['send_logistics_notifications'] = filter_var(
                $this->input('send_logistics_notifications'), 
                FILTER_VALIDATE_BOOLEAN, 
                FILTER_NULL_ON_FAILURE
            );
        }

        // Merge the converted data
        if (!empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Visual settings - Logo with file upload
            'logo' => 'nullable|file|image|max:2048',
            
            // Colors - Accept any string (validation in controller if needed)
            'sidebar_bg_color' => 'nullable|string|max:20',
            'sidebar_text_color' => 'nullable|string|max:20',
            'header_bg_color' => 'nullable|string|max:20',
            'header_text_color' => 'nullable|string|max:20',
            'content_bg_color' => 'nullable|string|max:20',
            'content_text_color' => 'nullable|string|max:20',
            
            // Hierarchy settings
            'hierarchy_mode' => 'nullable|string',
            'auto_assign_hierarchy' => 'nullable|boolean',
            'hierarchy_conflict_resolution' => 'nullable|string',
            'require_hierarchy_config' => 'nullable|boolean',
            
            // Notification settings
            'send_logistics_notifications' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom validation messages
     */
    public function messages(): array
    {
        return [
            'logo.image' => 'El archivo debe ser una imagen válida.',
            'logo.max' => 'La imagen no debe superar los 2MB.',
        ];
    }
}
