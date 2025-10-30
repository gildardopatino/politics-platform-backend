<?php

namespace App\Providers;

use App\Models\Campaign;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\ResourceAllocation;
use App\Models\Tenant;
use App\Policies\CampaignPolicy;
use App\Policies\CommitmentPolicy;
use App\Policies\MeetingPolicy;
use App\Policies\ResourceAllocationPolicy;
use App\Policies\TenantPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

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
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
