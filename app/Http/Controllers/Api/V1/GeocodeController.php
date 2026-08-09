<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Geocoding\Geocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GeocodeController extends Controller
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /**
     * Dirección → coordenadas.
     *
     * El proveedor vive detrás de `Geocoder` (Spec 0055): aquí solo se valida,
     * se traduce el resultado a la forma que el frontend ya consume y se decide
     * el código de estado. Cambiar de Google a Nominatim —o auto-hospedarlo— no
     * toca este archivo.
     */
    public function geocode(Request $request): JsonResponse
    {
        $request->validate([
            'address' => 'required|string|max:500',
        ], [
            'address.required' => 'La dirección es obligatoria.',
            'address.max' => 'La dirección no puede exceder 500 caracteres.',
        ]);

        $address = $request->input('address');

        try {
            $resultado = $this->geocoder->resolve($address);
        } catch (\Throwable $e) {
            // El detalle se registra pero no viaja al cliente: antes se devolvía
            // el mensaje de la excepción, que expone el proveedor y su error.
            Log::error('Geocoding error', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al procesar la geocodificación.',
            ], 500);
        }

        if ($resultado === null) {
            Log::warning('Geocoding failed', ['address' => $address]);

            return response()->json([
                'message' => 'No se pudo geocodificar la dirección.',
                'address' => $address,
            ], 404);
        }

        return response()->json([
            'data' => [
                'latitude' => $resultado->latitude,
                'longitude' => $resultado->longitude,
                'formatted_address' => $resultado->formattedAddress,
                'original_address' => $address,
            ],
        ]);
    }
}
