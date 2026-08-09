<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Geocodificación contra Nominatim, el servicio de OpenStreetMap (Spec 0055).
 *
 * No pide clave, pero su política de uso sí pide portarse bien, y las tres
 * exigencias están aquí porque son parte del proveedor, no del controlador:
 *
 * 1. **Identificarse** con un User-Agent propio (nombre de la app + contacto).
 *    Un User-Agent genérico es motivo de bloqueo.
 * 2. **Máximo una petición por segundo** al servicio público. Se espera lo justo
 *    entre llamadas reales; una respuesta en caché no cuenta y no espera.
 * 3. **No repetir consultas**: la caché guarda por dirección normalizada, y
 *    guarda también los fallos, porque una dirección mal escrita se reintenta
 *    varias veces y cada intento sería una petición.
 *
 * Auto-hospedar Nominatim es cambiar `GEOCODING_NOMINATIM_URL`: el resto sigue
 * igual (y ahí el límite de una por segundo deja de tener sentido, pero tampoco
 * estorba).
 */
class NominatimGeocoder implements Geocoder
{
    /** Marca del último envío real, para respetar el límite de 1 req/s. */
    private const CLAVE_ULTIMA_PETICION = 'geocode:nominatim:ultima-peticion';

    private const ESPERA_MINIMA_MS = 1000;

    public function resolve(string $address): ?GeocodeResult
    {
        $normalizada = $this->normalizar($address);

        if ($normalizada === '') {
            return null;
        }

        $cacheado = Cache::remember(
            'geocode:nominatim:'.sha1($normalizada),
            (int) config('services.geocoding.cache_ttl', 60 * 60 * 24 * 30),
            fn () => $this->consultar($normalizada) ?? ['sin_resultado' => true],
        );

        if (isset($cacheado['sin_resultado'])) {
            return null;
        }

        return GeocodeResult::fromArray($cacheado);
    }

    /**
     * Una consulta real a Nominatim. Devuelve el resultado en array plano —lo
     * que va a la caché— o `null` si el servicio no encontró nada.
     */
    private function consultar(string $address): ?array
    {
        $this->esperarTurno();

        $url = rtrim((string) config('services.geocoding.nominatim_url'), '/');

        $respuesta = Http::withHeaders(['User-Agent' => $this->userAgent()])
            ->timeout((int) config('services.geocoding.timeout', 8))
            ->get($url.'/search', [
                'q' => $address,
                'format' => 'jsonv2',
                'limit' => 1,
                'addressdetails' => 1,
                'accept-language' => 'es',
                'countrycodes' => 'co',
            ])
            ->throw();

        $primero = $respuesta->json()[0] ?? null;

        if (! is_array($primero) || ! isset($primero['lat'], $primero['lon'])) {
            return null;
        }

        return [
            'latitude' => (float) $primero['lat'],
            'longitude' => (float) $primero['lon'],
            'formatted_address' => (string) ($primero['display_name'] ?? $address),
        ];
    }

    /**
     * Deja pasar como mucho una petición por segundo. La marca vive en caché
     * para que el límite valga entre procesos (varias peticiones web a la vez),
     * no solo dentro de uno.
     */
    private function esperarTurno(): void
    {
        $ultima = (float) Cache::get(self::CLAVE_ULTIMA_PETICION, 0);
        $ahora = microtime(true) * 1000;
        $transcurrido = $ahora - $ultima;

        if ($ultima > 0 && $transcurrido < self::ESPERA_MINIMA_MS) {
            usleep((int) ((self::ESPERA_MINIMA_MS - $transcurrido) * 1000));
        }

        Cache::put(self::CLAVE_ULTIMA_PETICION, microtime(true) * 1000, 60);
    }

    /** Nombre de la app + contacto, que es lo que Nominatim pide ver. */
    private function userAgent(): string
    {
        $nombre = (string) config('services.geocoding.user_agent', 'PoliticsPlatform/1.0');
        $contacto = trim((string) config('services.geocoding.contact_email', ''));

        return $contacto === '' ? $nombre : "{$nombre} (+{$contacto})";
    }

    private function normalizar(string $address): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $address)));
    }
}
