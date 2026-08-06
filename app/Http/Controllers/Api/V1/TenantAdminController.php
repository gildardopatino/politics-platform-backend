<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super-admin management of a tenant's administrator users.
 * Operates cross-tenant (bypasses TenantScope); mounted under the superadmin
 * route group, so no tenant context is bound by middleware.
 */
class TenantAdminController extends Controller
{
    use ApiResponse;

    public function index(int $tenantId): JsonResponse
    {
        $this->resolveTenant($tenantId);

        $admins = User::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->orderBy('name')
            ->get();

        return $this->respondData($admins->map(fn (User $u) => $this->present($u)));
    }

    public function store(Request $request, int $tenantId): JsonResponse
    {
        $this->resolveTenant($tenantId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // hashed by the model cast
            'is_super_admin' => false,
        ]);

        $user->assignRole($this->tenantAdminRole($tenantId));

        return $this->respondData($this->present($user), 'Administrador creado', 201);
    }

    public function update(Request $request, int $tenantId, int $userId): JsonResponse
    {
        $this->resolveTenant($tenantId);
        $user = $this->resolveAdmin($tenantId, $userId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password']; // hashed by cast
        }
        unset($data['password']);
        $user->fill($data)->save();

        return $this->respondData($this->present($user), 'Administrador actualizado');
    }

    public function destroy(int $tenantId, int $userId): JsonResponse
    {
        $this->resolveTenant($tenantId);
        $user = $this->resolveAdmin($tenantId, $userId);

        // Never leave a tenant without an administrator.
        $adminCount = User::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->count();

        if ($adminCount <= 1) {
            return $this->respondError('No puedes eliminar el único administrador del tenant.', 422);
        }

        $user->delete();

        return $this->respondMessage('Administrador eliminado');
    }

    /* ------------------------------------------------------------------ */

    private function resolveTenant(int $tenantId): Tenant
    {
        $tenant = Tenant::withoutGlobalScope(TenantScope::class)->find($tenantId);

        abort_if(! $tenant, 404, 'Tenant no encontrado.');

        return $tenant;
    }

    private function resolveAdmin(int $tenantId, int $userId): User
    {
        $user = User::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('id', $userId)
            ->first();

        abort_if(! $user, 404, 'Usuario no encontrado en este tenant.');

        return $user;
    }

    /**
     * Get the tenant's "admin" role, provisioning the per-tenant role set if a
     * legacy tenant doesn't have it yet.
     */
    private function tenantAdminRole(int $tenantId): Role
    {
        $role = Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('name', 'admin')
            ->where('guard_name', 'api')
            ->first();

        if (! $role) {
            // `Role::query()->create()` y no `Role::create()`: el de Spatie
            // rechaza cualquier rol cuyo par (name, guard_name) ya exista,
            // ignorando `tenant_id`. Aquí no hay contexto de tenant enlazado
            // (grupo superadmin), así que `TenantScope` no filtra y esa
            // comprobación encontraba el `admin` GLOBAL: crear el admin de un
            // tenant reventaba con RoleAlreadyExists. La clave única real es
            // (tenant_id, name, guard_name) — ver `fix_roles_unique_for_tenants`.
            $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api', 'tenant_id' => $tenantId]);
            $role->syncPermissions(Permission::where('guard_name', 'api')->get());
        }

        return $role;
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
