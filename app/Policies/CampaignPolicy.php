<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Support\Permissions;

class CampaignPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::VIEW_CAMPAIGNS);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return $user->tenant_id === $campaign->tenant_id && $user->hasPermissionTo(Permissions::VIEW_CAMPAIGNS);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::CREATE_CAMPAIGNS);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        return $user->tenant_id === $campaign->tenant_id &&
               ($user->hasPermissionTo(Permissions::EDIT_CAMPAIGNS) || $campaign->created_by_user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->tenant_id === $campaign->tenant_id && $user->hasPermissionTo(Permissions::DELETE_CAMPAIGNS);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Campaign $campaign): bool
    {
        return $user->tenant_id === $campaign->tenant_id && $user->hasPermissionTo(Permissions::DELETE_CAMPAIGNS);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Campaign $campaign): bool
    {
        return $user->tenant_id === $campaign->tenant_id && $user->hasPermissionTo(Permissions::DELETE_CAMPAIGNS);
    }
}
