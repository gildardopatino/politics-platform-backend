<?php

use App\Jobs\SyncSocialMediaJob;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronizar votantes desde asistentes - 2 veces al día (6am y 6pm)
Schedule::command('voters:sync')
    ->twiceDaily(6, 18)
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(config('mail.from.address'));

// Sincronizar redes sociales - cada 15 minutos para tenants con auto-sync habilitado
Schedule::call(function () {
    $tenants = Tenant::where('social_auto_sync_enabled', true)->get();
    
    foreach ($tenants as $tenant) {
        // Check if enough time has passed based on tenant's interval setting
        $interval = $tenant->social_sync_interval_minutes ?? 15;
        $lastSync = $tenant->social_last_synced_at;
        
        if (!$lastSync || $lastSync->addMinutes($interval)->isPast()) {
            SyncSocialMediaJob::dispatch($tenant->id);
        }
    }
})
    ->everyFifteenMinutes()
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->onOneServer();
