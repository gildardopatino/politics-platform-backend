<?php

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')->id;
        $tenantId = app('tenant')->id;

        return [
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                'unique:users,email,' . $userId . ',id,tenant_id,' . $tenantId
            ],
            'password' => 'sometimes|string|min:6',
            'phone' => 'nullable|string|max:20',
            'cedula' => 'nullable|string|max:20',
            'is_team_leader' => 'nullable|boolean',
            'reports_to' => 'nullable|exists:users,id',
            'department_id' => 'nullable|exists:departments,id',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'commune_id' => 'nullable|exists:communes,id',
            'barrio_id' => 'nullable|exists:barrios,id',
            'corregimiento_id' => 'nullable|exists:corregimientos,id',
            'vereda_id' => 'nullable|exists:veredas,id',
            'role_id' => 'nullable|integer|exists:roles,id',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'El nombre no puede exceder 255 caracteres.',
            'email.email' => 'El correo electrónico debe ser válido.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'phone.max' => 'El teléfono no puede exceder 20 caracteres.',
            'cedula.max' => 'La cédula no puede exceder 20 caracteres.',
            'reports_to.exists' => 'El supervisor seleccionado no existe.',
            'department_id.exists' => 'El departamento seleccionado no existe.',
            'municipality_id.exists' => 'El municipio seleccionado no existe.',
            'commune_id.exists' => 'La comuna seleccionada no existe.',
            'barrio_id.exists' => 'El barrio seleccionado no existe.',
            'corregimiento_id.exists' => 'El corregimiento seleccionado no existe.',
            'vereda_id.exists' => 'La vereda seleccionada no existe.',
            'role_id.exists' => 'El rol seleccionado no existe.',
            'role_id.integer' => 'El rol debe ser un número válido.',
            'roles.array' => 'Los roles deben ser un array.',
            'roles.*.exists' => 'Uno o más roles seleccionados no existen.',
        ];
    }
}
