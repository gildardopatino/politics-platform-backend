<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodeController extends Controller
{
    /**
     * Geocode an address to get latitude and longitude
     */
    public function geocode(Request $request): JsonResponse
    {
        $request->validate([
            'address' => 'required|string|max:500'
        ], [
            'address.required' => 'La dirección es obligatoria.',
            'address.max' => 'La dirección no puede exceder 500 caracteres.'
        ]);

        $address = $request->input('address');
        $apiKey = config('services.google_maps.api_key');

        if (!$apiKey) {
            return response()->json([
                'message' => 'Google Maps API key not configured.'
            ], 500);
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'key' => $apiKey,
            ]);

            $data = $response->json();

            if ($data['status'] !== 'OK') {
                Log::warning('Geocoding failed', [
                    'address' => $address,
                    'status' => $data['status']
                ]);

                return response()->json([
                    'message' => 'No se pudo geocodificar la dirección.',
                    'error' => $data['status'],
                    'address' => $address
                ], 404);
            }

            $location = $data['results'][0]['geometry']['location'];
            $formattedAddress = $data['results'][0]['formatted_address'];

            return response()->json([
                'data' => [
                    'latitude' => $location['lat'],
                    'longitude' => $location['lng'],
                    'formatted_address' => $formattedAddress,
                    'original_address' => $address
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Geocoding error', [
                'address' => $address,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Error al procesar la geocodificación.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
