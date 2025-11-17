<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsAppInstanceRequest extends FormRequest
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
        $instance = $this->route('whatsapp_instance') ?? $this->route('instance');
        $instanceId = $instance ? $instance->id : null;
        $tenantId = $instance ? $instance->tenant_id : $this->input('tenant_id');

        return [
            'phone_number' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                'regex:/^\+?[1-9]\d{1,14}$/',
                'unique:tenant_whatsapp_instances,phone_number,' . $instanceId . ',id,tenant_id,' . $tenantId . ',deleted_at,NULL',
            ],
            'instance_name' => 'sometimes|required|string|max:255',
            'evolution_api_key' => 'sometimes|required|string',
            'evolution_api_url' => 'nullable|url',
            'daily_message_limit' => 'sometimes|required|integer|min:1|max:100000',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'phone_number.regex' => 'El número de teléfono debe estar en formato internacional (ej: +573001234567)',
            'phone_number.unique' => 'Este número de teléfono ya está registrado para este tenant',
            'daily_message_limit.min' => 'El límite diario debe ser al menos 1',
            'daily_message_limit.max' => 'El límite diario no puede exceder 100,000 mensajes',
        ];
    }
}
