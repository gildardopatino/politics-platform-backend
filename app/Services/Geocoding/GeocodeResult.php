<?php

namespace App\Services\Geocoding;

/**
 * Una dirección resuelta a un punto del mapa (Spec 0055).
 *
 * Es lo único que el controlador necesita saber, y es lo que aísla al resto del
 * sistema del proveedor: cambiar Nominatim por otro no cambia esta forma.
 */
class GeocodeResult
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $formattedAddress,
    ) {}

    /**
     * Para guardar en caché sin arrastrar la clase (un `unserialize` de objetos
     * en caché es más frágil que un array plano).
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'formatted_address' => $this->formattedAddress,
        ];
    }

    public static function fromArray(array $datos): self
    {
        return new self(
            (float) $datos['latitude'],
            (float) $datos['longitude'],
            (string) $datos['formatted_address'],
        );
    }
}
