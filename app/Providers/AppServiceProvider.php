<?php

namespace App\Providers;

use App\Models\MeetingAttendee;
use App\Observers\MeetingAttendeeObserver;
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
                    'twilio' => new \App\Services\SMS\TwilioSMS,
                    default => new \App\Services\SMS\LogSMS,
                };
            }
        );

        // Proveedor de geocodificación (Spec 0055). Mismo patrón que el de SMS:
        // el resto del sistema depende de la interfaz, así que cambiar de
        // proveedor —o auto-hospedar Nominatim— no toca ningún controlador.
        $this->app->bind(
            \App\Services\Geocoding\Geocoder::class,
            function () {
                $provider = config('services.geocoding.provider', 'nominatim');

                return match ($provider) {
                    default => new \App\Services\Geocoding\NominatimGeocoder,
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
        MeetingAttendee::observe(MeetingAttendeeObserver::class);
    }
}
