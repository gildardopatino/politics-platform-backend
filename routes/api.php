<?php

use App\Http\Controllers\Api\V1\AttendeeHierarchyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BarrioController;
use App\Http\Controllers\Api\V1\CallController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CommitmentController;
use App\Http\Controllers\Api\V1\CommuneController;
use App\Http\Controllers\Api\V1\CorregimientoController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\GeocodeController;
use App\Http\Controllers\Api\V1\GeographicStatsController;
use App\Http\Controllers\Api\V1\GeographyController;
use App\Http\Controllers\Api\V1\LandingPageController;
use App\Http\Controllers\Api\V1\Landing\LandingBannerAdminController;
use App\Http\Controllers\Api\V1\Landing\LandingPropuestaAdminController;
use App\Http\Controllers\Api\V1\Landing\LandingEventoAdminController;
use App\Http\Controllers\Api\V1\Landing\LandingGaleriaAdminController;
use App\Http\Controllers\Api\V1\Landing\LandingTestimonioAdminController;
use App\Http\Controllers\Api\V1\Landing\LandingSocialFeedAdminController;
use App\Http\Controllers\Api\V1\Landing\BiografiaAdminController;
use App\Http\Controllers\Api\V1\MeetingAttendeeController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\Settings\SocialMediaSettingsController;
use App\Http\Controllers\Api\V1\MeetingTemplateController;
use App\Http\Controllers\Api\V1\MunicipalityController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PriorityController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ResourceAllocationController;
use App\Http\Controllers\Api\V1\ResourceItemController;
use App\Http\Controllers\Api\V1\ResourceAllocationItemController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SurveyController;
use App\Http\Controllers\Api\V1\SurveyQuestionController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VeredaController;
use App\Http\Controllers\Api\V1\VoterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    
    // Public routes
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/meetings/public/{qr_code}', [MeetingController::class, 'getPublicInfo']);
    Route::get('/meetings/check-in/{qr_code}', [MeetingController::class, 'showByQR']);
    Route::post('/meetings/check-in/{qr_code}', [MeetingController::class, 'checkIn']);
    Route::get('/barrios/search/by-name', [BarrioController::class, 'search']);
    Route::get('/verify-document', [VoterController::class, 'verifyDocument']);
    
    // Landing Page Public Routes
    Route::prefix('landingpage')->group(function () {
        Route::get('/banners', [LandingPageController::class, 'getBanners']);
        Route::get('/biografia', [LandingPageController::class, 'getBiografia']);
        Route::get('/propuestas', [LandingPageController::class, 'getPropuestas']);
        Route::get('/eventos', [LandingPageController::class, 'getEventos']);
        Route::get('/galeria', [LandingPageController::class, 'getGaleria']);
        Route::get('/testimonios', [LandingPageController::class, 'getTestimonios']);
        Route::get('/social-feed', [LandingPageController::class, 'getSocialFeed']);
        Route::post('/voluntarios', [LandingPageController::class, 'storeVoluntario']);
        Route::post('/contacto', [LandingPageController::class, 'storeContacto']);
    });

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
            
            // Roles & Permissions
            Route::apiResource('roles', RoleController::class);
            Route::post('/roles/{role}/assign-permissions', [RoleController::class, 'assignPermissions']);
            Route::get('/permissions', [PermissionController::class, 'index']);
            
            // Users
            Route::apiResource('users', UserController::class);
            Route::get('/users/{user}/team', [UserController::class, 'team']);
            
            // Meeting Templates
            Route::apiResource('meeting-templates', MeetingTemplateController::class);
            
            // Meetings
            Route::apiResource('meetings', MeetingController::class);
            Route::get('/meetings/hierarchy/tree', [MeetingController::class, 'getHierarchyTree']);
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
            
            // Resource Items (Catalog)
            Route::apiResource('resource-items', ResourceItemController::class);
            Route::get('/resource-items-low-stock', [ResourceItemController::class, 'lowStock']);
            
            // Resource Allocation Items (Control individual)
            Route::patch('/resource-allocation-items/{resourceAllocationItem}/status', [ResourceAllocationItemController::class, 'updateStatus']);
            Route::put('/resource-allocation-items/{resourceAllocationItem}', [ResourceAllocationItemController::class, 'update']);
            Route::delete('/resource-allocation-items/{resourceAllocationItem}', [ResourceAllocationItemController::class, 'destroy']);
            
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
            
            // Voters
            Route::apiResource('voters', VoterController::class);
            Route::get('/voters/search/by-cedula', [VoterController::class, 'searchByCedula']);
            Route::get('/voters-stats', [VoterController::class, 'stats']);
            
            // Surveys
            Route::apiResource('surveys', SurveyController::class);
            Route::post('/surveys/{survey}/activate', [SurveyController::class, 'activate']);
            Route::post('/surveys/{survey}/deactivate', [SurveyController::class, 'deactivate']);
            Route::post('/surveys/{survey}/clone', [SurveyController::class, 'cloneSurvey']);
            Route::get('/surveys-active', [SurveyController::class, 'active']);

            // Survey Questions (nested resource)
            Route::apiResource('surveys.questions', SurveyQuestionController::class)
                ->shallow()
                ->except(['index']);
            
            // Calls
            Route::apiResource('calls', CallController::class);
            Route::get('/voters/{voter}/calls', [CallController::class, 'byVoter']);
            Route::get('/calls-stats', [CallController::class, 'stats']);
            
            // Landing Page Admin Routes (Protected)
            Route::prefix('landingpage/admin')->group(function () {
                Route::apiResource('banners', LandingBannerAdminController::class);
                Route::apiResource('propuestas', LandingPropuestaAdminController::class);
                Route::apiResource('eventos', LandingEventoAdminController::class);
                Route::apiResource('galeria', LandingGaleriaAdminController::class);
                Route::apiResource('testimonios', LandingTestimonioAdminController::class);
                Route::apiResource('social-feed', LandingSocialFeedAdminController::class);
                
                // Biografia - special routes (updates JSON field in tenants table)
                Route::get('biografia', [BiografiaAdminController::class, 'show']);
                Route::put('biografia', [BiografiaAdminController::class, 'update']);
                Route::delete('biografia/imagen', [BiografiaAdminController::class, 'deleteImage']);
            });
            
            // Social Media Settings
            Route::prefix('settings/social-media')->group(function () {
                Route::get('/', [SocialMediaSettingsController::class, 'show']);
                Route::put('/twitter', [SocialMediaSettingsController::class, 'updateTwitter']);
                Route::put('/facebook', [SocialMediaSettingsController::class, 'updateFacebook']);
                Route::put('/instagram', [SocialMediaSettingsController::class, 'updateInstagram']);
                Route::put('/youtube', [SocialMediaSettingsController::class, 'updateYouTube']);
                Route::put('/auto-sync', [SocialMediaSettingsController::class, 'updateAutoSync']);
                Route::post('/sync', [SocialMediaSettingsController::class, 'syncAll']);
                Route::post('/sync/{platform}', [SocialMediaSettingsController::class, 'syncPlatform']);
            });
        });
    });
});
