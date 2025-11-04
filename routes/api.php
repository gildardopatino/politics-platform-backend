<?php

use App\Http\Controllers\Api\V1\AttendeeHierarchyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BarrioController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CommitmentController;
use App\Http\Controllers\Api\V1\CommuneController;
use App\Http\Controllers\Api\V1\CorregimientoController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\GeocodeController;
use App\Http\Controllers\Api\V1\GeographicStatsController;
use App\Http\Controllers\Api\V1\GeographyController;
use App\Http\Controllers\Api\V1\MeetingAttendeeController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MeetingTemplateController;
use App\Http\Controllers\Api\V1\MunicipalityController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PriorityController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ResourceAllocationController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VeredaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    
    // Public routes
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/meetings/check-in/{qr_code}', [MeetingController::class, 'showByQR']);
    Route::post('/meetings/check-in/{qr_code}', [MeetingController::class, 'checkIn']);

    // Protected routes
    Route::middleware('jwt.auth')->group(function () {
        
        // Auth routes
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);

        // Super Admin only routes
        Route::middleware('superadmin')->group(function () {
            Route::post('/register', [AuthController::class, 'register']);
            Route::apiResource('tenants', TenantController::class);
        });

        // Tenant-scoped routes
        Route::middleware('tenant')->group(function () {
            
            // Tenant Settings (for current tenant to update their own settings)
            Route::get('/tenant/settings', [TenantSettingsController::class, 'show']);
            Route::put('/tenant/settings', [TenantSettingsController::class, 'update']);
            Route::get('/tenant/hierarchy-config/check', [TenantSettingsController::class, 'checkHierarchyConfig']);
            
            // Geocoding
            Route::post('/geocode', [GeocodeController::class, 'geocode']);
            
            // Roles
            Route::get('/roles', [RoleController::class, 'index']);
            
            // Users
            Route::apiResource('users', UserController::class);
            Route::get('/users/{user}/team', [UserController::class, 'team']);
            
            // Meeting Templates
            Route::apiResource('meeting-templates', MeetingTemplateController::class);
            
            // Meetings
            Route::apiResource('meetings', MeetingController::class);
            Route::post('/meetings/{meeting}/complete', [MeetingController::class, 'complete']);
            Route::post('/meetings/{meeting}/cancel', [MeetingController::class, 'cancel']);
            Route::get('/meetings/{meeting}/qr-code', [MeetingController::class, 'getQRCode']);
            
            // Meeting Attendees
            Route::get('/meetings/{meeting}/attendees', [MeetingAttendeeController::class, 'index']);
            Route::post('/meetings/{meeting}/attendees', [MeetingAttendeeController::class, 'store']);
            Route::get('/attendees/{attendee}', [MeetingAttendeeController::class, 'show']);
            Route::put('/attendees/{attendee}', [MeetingAttendeeController::class, 'update']);
            Route::delete('/attendees/{attendee}', [MeetingAttendeeController::class, 'destroy']);
            
            // Campaigns
            Route::apiResource('campaigns', CampaignController::class);
            Route::post('/campaigns/{campaign}/send', [CampaignController::class, 'send']);
            Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
            Route::get('/campaigns/{campaign}/recipients', [CampaignController::class, 'recipients']);
            
            // Commitments
            Route::get('/meetings/{meeting}/commitments', [CommitmentController::class, 'byMeeting']);
            Route::apiResource('commitments', CommitmentController::class);
            Route::post('/commitments/{commitment}/complete', [CommitmentController::class, 'complete']);
            Route::get('/commitments/overdue', [CommitmentController::class, 'overdue']);
            
            // Priorities
            Route::apiResource('priorities', PriorityController::class);
            
            // Dashboard & Calendar
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('calendar', [DashboardController::class, 'calendar']);
            
            // Organization Structure
            Route::get('organization/tree', [OrganizationController::class, 'tree']);
            Route::get('organization/list', [OrganizationController::class, 'list']);
            Route::get('organization/my-team', [OrganizationController::class, 'myTeam']);
            Route::get('organization/chain-of-command', [OrganizationController::class, 'chainOfCommand']);
            Route::get('organization/potential-supervisors', [OrganizationController::class, 'potentialSupervisors']);
            
            // Attendee Hierarchies
            Route::get('attendee-hierarchies/tree', [AttendeeHierarchyController::class, 'tree']);
            Route::get('attendee-hierarchies/relationships', [AttendeeHierarchyController::class, 'relationships']);
            Route::get('attendee-hierarchies/stats', [AttendeeHierarchyController::class, 'stats']);
            Route::put('attendee-hierarchies/{attendeeHierarchy}', [AttendeeHierarchyController::class, 'update']);
            Route::delete('attendee-hierarchies/{attendeeHierarchy}', [AttendeeHierarchyController::class, 'destroy']);
            
            // Resource Allocations
            Route::apiResource('resource-allocations', ResourceAllocationController::class);
            Route::get('/resource-allocations/by-meeting/{meeting}', [ResourceAllocationController::class, 'byMeeting']);
            Route::get('/resource-allocations/by-leader/{user}', [ResourceAllocationController::class, 'byLeader']);
            
            // Geography
            Route::get('/departments', [GeographyController::class, 'departments']);
            Route::get('/departments/{department}/municipalities', [GeographyController::class, 'municipalities']);
            Route::get('/municipalities/{municipality}/communes', [GeographyController::class, 'communes']);
            Route::get('/municipalities/{municipality}/barrios', [GeographyController::class, 'barriosByMunicipality']);
            Route::get('/communes/{commune}/barrios', [GeographyController::class, 'barriosByCommune']);
            Route::get('/municipalities/{municipality}/corregimientos', [GeographyController::class, 'corregimientos']);
            Route::get('/corregimientos/{corregimiento}/veredas', [GeographyController::class, 'veredasByCorregimiento']);
            Route::get('/municipalities/{municipality}/veredas', [GeographyController::class, 'veredasByMunicipality']);
            
            // Geography CRUD
            Route::apiResource('municipalities', MunicipalityController::class);
            Route::apiResource('communes', CommuneController::class);
            Route::apiResource('barrios', BarrioController::class);
            Route::apiResource('corregimientos', CorregimientoController::class);
            Route::apiResource('veredas', VeredaController::class);
            
            // Geographic Statistics
            Route::get('/geographic-stats', [GeographicStatsController::class, 'index']);
            
            // Reports
            Route::get('/reports/meetings', [ReportController::class, 'meetings']);
            Route::get('/reports/campaigns', [ReportController::class, 'campaigns']);
            Route::get('/reports/commitments', [ReportController::class, 'commitments']);
            Route::get('/reports/resources', [ReportController::class, 'resources']);
            Route::get('/reports/team-performance', [ReportController::class, 'teamPerformance']);
        });
    });
});
