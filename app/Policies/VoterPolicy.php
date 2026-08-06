<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voter;
use App\Support\Permissions;

class VoterPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::VIEW_VOTERS);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Voter $voter): bool
    {
        return $user->tenant_id === $voter->tenant_id && $user->hasPermissionTo(Permissions::VIEW_VOTERS);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::VIEW_VOTERS);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Voter $voter): bool
    {
        return $user->tenant_id === $voter->tenant_id && $user->hasPermissionTo(Permissions::VIEW_VOTERS);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Voter $voter): bool
    {
        return $user->tenant_id === $voter->tenant_id && $user->hasPermissionTo(Permissions::VIEW_VOTERS);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Voter $voter): bool
    {
        return $user->tenant_id === $voter->tenant_id && $user->hasPermissionTo(Permissions::VIEW_VOTERS);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Voter $voter): bool
    {
        return $user->tenant_id === $voter->tenant_id && $user->hasPermissionTo(Permissions::VIEW_VOTERS);
    }
}
