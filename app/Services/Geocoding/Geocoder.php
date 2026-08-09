<?php

namespace App\Services\Geocoding;

/**
 * Proveedor de geocodificación (Spec 0055).
 *
 * El contrato es deliberadamente pequeño —una dirección entra, un punto sale o
 * no sale— para que cambiar de proveedor sea cambiar una línea de config y no
 * tocar el controlador.
 */
interface Geocoder
{
    /**
     * Resuelve una dirección. Devuelve `null` cuando el proveedor responde bien
     * pero no encuentra nada; si el proveedor falla, lanza excepción.
     */
    public function resolve(string $address): ?GeocodeResult;
}
