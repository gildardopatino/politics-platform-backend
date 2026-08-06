<?php

namespace App\Providers;

use App\Models\Campaign;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\ResourceAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Policies\CampaignPolicy;
use App\Policies\CommitmentPolicy;
use App\Policies\MeetingPolicy;
use App\Policies\ResourceAllocationPolicy;
use App\Policies\TenantPolicy;
use App\Policies\VoterPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Tenant::class => TenantPolicy::class,
        Meeting::class => MeetingPolicy::class,
        Campaign::class => CampaignPolicy::class,
        Commitment::class => CommitmentPolicy::class,
        ResourceAllocation::class => ResourceAllocationPolicy::class,
        Voter::class => VoterPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Bypass del super admin global (Spec 0005).
        //
        // No pertenece a ningún tenant, así que no tiene roles ni permisos
        // asignados: sin esto recibiría 403 en toda ruta con `permission:`.
        // Va en el Gate y no en cada middleware porque así cubre de una vez el
        // middleware de Spatie (usa `canAny()`), las policies y cualquier
        // `$user->can(...)` de los controllers.
        //
        // Devolver `null` en vez de `false` deja que la comprobación siga su
        // curso normal para el resto de usuarios.
        Gate::before(function (?User $user) {
            return $user?->is_super_admin ? true : null;
        });
    }
}
