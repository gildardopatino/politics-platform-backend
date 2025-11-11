<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind SMS Service based on configuration
        $this->app->bind(
            \App\Services\SMS\SMSInterface::class,
            function () {
                $provider = config('sms.provider', 'log');
                
                return match ($provider) {
                    'twilio' => new \App\Services\SMS\TwilioSMS(),
                    default => new \App\Services\SMS\LogSMS(),
                };
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register observers
        \App\Models\MeetingAttendee::observe(\App\Observers\MeetingAttendeeObserver::class);
    }
}
