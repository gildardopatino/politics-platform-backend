<?php

namespace App\Policies;

use App\Models\ResourceAllocation;
use App\Models\User;
use App\Support\Permissions;

class ResourceAllocationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::VIEW_RESOURCES);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ResourceAllocation $resourceAllocation): bool
    {
        return $user->tenant_id === $resourceAllocation->tenant_id && $user->hasPermissionTo(Permissions::VIEW_RESOURCES);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::CREATE_RESOURCES);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ResourceAllocation $resourceAllocation): bool
    {
        return $user->tenant_id === $resourceAllocation->tenant_id &&
               ($user->hasPermissionTo(Permissions::EDIT_RESOURCES) || $resourceAllocation->allocated_by_user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ResourceAllocation $resourceAllocation): bool
    {
        return $user->tenant_id === $resourceAllocation->tenant_id && $user->hasPermissionTo(Permissions::DELETE_RESOURCES);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ResourceAllocation $resourceAllocation): bool
    {
        return $user->tenant_id === $resourceAllocation->tenant_id && $user->hasPermissionTo(Permissions::DELETE_RESOURCES);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ResourceAllocation $resourceAllocation): bool
    {
        return $user->tenant_id === $resourceAllocation->tenant_id && $user->hasPermissionTo(Permissions::DELETE_RESOURCES);
    }
}
