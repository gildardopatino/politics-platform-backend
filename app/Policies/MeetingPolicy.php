<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;
use App\Support\Permissions;

class MeetingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::VIEW_MEETINGS);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Meeting $meeting): bool
    {
        return $user->tenant_id === $meeting->tenant_id && $user->hasPermissionTo(Permissions::VIEW_MEETINGS);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::CREATE_MEETINGS);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Meeting $meeting): bool
    {
        return $user->tenant_id === $meeting->tenant_id &&
               ($user->hasPermissionTo(Permissions::EDIT_MEETINGS) || $meeting->planned_by_user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Meeting $meeting): bool
    {
        return $user->tenant_id === $meeting->tenant_id &&
               ($user->hasPermissionTo(Permissions::DELETE_MEETINGS) || $meeting->planned_by_user_id === $user->id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Meeting $meeting): bool
    {
        return $user->tenant_id === $meeting->tenant_id && $user->hasPermissionTo(Permissions::DELETE_MEETINGS);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Meeting $meeting): bool
    {
        return $user->tenant_id === $meeting->tenant_id && $user->hasPermissionTo(Permissions::DELETE_MEETINGS);
    }
}
