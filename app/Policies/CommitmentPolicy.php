<?php

namespace App\Policies;

use App\Models\Commitment;
use App\Models\User;
use App\Support\Permissions;

class CommitmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::VIEW_COMMITMENTS);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Commitment $commitment): bool
    {
        return $user->tenant_id === $commitment->tenant_id && $user->hasPermissionTo(Permissions::VIEW_COMMITMENTS);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::CREATE_COMMITMENTS);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Commitment $commitment): bool
    {
        return $user->tenant_id === $commitment->tenant_id &&
               ($user->hasPermissionTo(Permissions::EDIT_COMMITMENTS) || $commitment->assigned_user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Commitment $commitment): bool
    {
        return $user->tenant_id === $commitment->tenant_id && $user->hasPermissionTo(Permissions::DELETE_COMMITMENTS);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Commitment $commitment): bool
    {
        return $user->tenant_id === $commitment->tenant_id && $user->hasPermissionTo(Permissions::DELETE_COMMITMENTS);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Commitment $commitment): bool
    {
        return $user->tenant_id === $commitment->tenant_id && $user->hasPermissionTo(Permissions::DELETE_COMMITMENTS);
    }
}
