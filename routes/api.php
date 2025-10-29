<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CommitmentController;
use App\Http\Controllers\Api\V1\GeographyController;
use App\Http\Controllers\Api\V1\MeetingAttendeeController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MeetingTemplateController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ResourceAllocationController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UserController;
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
    Route::middleware('auth:api')->group(function () {
        
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
            Route::apiResource('commitments', CommitmentController::class);
            Route::post('/commitments/{commitment}/complete', [CommitmentController::class, 'complete']);
            Route::get('/commitments/overdue', [CommitmentController::class, 'overdue']);
            
            // Resource Allocations
            Route::apiResource('resource-allocations', ResourceAllocationController::class);
            Route::get('/resource-allocations/by-meeting/{meeting}', [ResourceAllocationController::class, 'byMeeting']);
            Route::get('/resource-allocations/by-leader/{user}', [ResourceAllocationController::class, 'byLeader']);
            
            // Geography
            Route::get('/departments', [GeographyController::class, 'departments']);
            Route::get('/departments/{department}/cities', [GeographyController::class, 'cities']);
            Route::get('/cities/{city}/communes', [GeographyController::class, 'communes']);
            Route::get('/communes/{commune}/barrios', [GeographyController::class, 'barrios']);
            
            // Reports
            Route::get('/reports/meetings', [ReportController::class, 'meetings']);
            Route::get('/reports/campaigns', [ReportController::class, 'campaigns']);
            Route::get('/reports/commitments', [ReportController::class, 'commitments']);
            Route::get('/reports/resources', [ReportController::class, 'resources']);
            Route::get('/reports/team-performance', [ReportController::class, 'teamPerformance']);
        });
    });
});
