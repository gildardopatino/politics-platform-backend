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
        $tenantId = app()->bound('tenant') ? app('tenant')->id : null;

        $emailRule = ['sometimes', 'email'];
        if ($tenantId) {
            $emailRule[] = 'unique:users,email,' . $userId . ',id,tenant_id,' . $tenantId;
        } else {
            $emailRule[] = 'unique:users,email,' . $userId;
        }

        return [
            'name' => 'sometimes|string|max:255',
            'email' => $emailRule,
            'password' => 'sometimes|string|min:6',
            'phone' => 'nullable|string|max:20',
            'cedula' => 'nullable|string|max:20',
            'is_team_leader' => 'nullable|boolean',
            'reports_to' => 'nullable|exists:users,id',
            // OLD: Single geographic assignments (backward compatibility)
            'department_id' => 'nullable|exists:departments,id',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'commune_id' => 'nullable|exists:communes,id',
            'barrio_id' => 'nullable|exists:barrios,id',
            'corregimiento_id' => 'nullable|exists:corregimientos,id',
            'vereda_id' => 'nullable|exists:veredas,id',
            // NEW: Multiple geographic assignments
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
            'municipality_ids' => 'nullable|array',
            'municipality_ids.*' => 'integer|exists:municipalities,id',
            'commune_ids' => 'nullable|array',
            'commune_ids.*' => 'integer|exists:communes,id',
            'barrio_ids' => 'nullable|array',
            'barrio_ids.*' => 'integer|exists:barrios,id',
            'corregimiento_ids' => 'nullable|array',
            'corregimiento_ids.*' => 'integer|exists:corregimientos,id',
            'vereda_ids' => 'nullable|array',
            'vereda_ids.*' => 'integer|exists:veredas,id',
            // Roles
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
            // NEW: Multiple geographic assignments
            'department_ids.array' => 'Los departamentos deben ser un array.',
            'department_ids.*.exists' => 'Uno o más departamentos seleccionados no existen.',
            'municipality_ids.array' => 'Los municipios deben ser un array.',
            'municipality_ids.*.exists' => 'Uno o más municipios seleccionados no existen.',
            'commune_ids.array' => 'Las comunas deben ser un array.',
            'commune_ids.*.exists' => 'Una o más comunas seleccionadas no existen.',
            'barrio_ids.array' => 'Los barrios deben ser un array.',
            'barrio_ids.*.exists' => 'Uno o más barrios seleccionados no existen.',
            'corregimiento_ids.array' => 'Los corregimientos deben ser un array.',
            'corregimiento_ids.*.exists' => 'Uno o más corregimientos seleccionados no existen.',
            'vereda_ids.array' => 'Las veredas deben ser un array.',
            'vereda_ids.*.exists' => 'Una o más veredas seleccionadas no existen.',
        ];
    }
}
