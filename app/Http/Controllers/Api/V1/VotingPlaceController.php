<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Voter;
use App\Scopes\TenantScope;
use App\Services\VotingPlaceImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class VotingPlaceController extends Controller
{
    protected VotingPlaceImageService $imageService;

    public function __construct(VotingPlaceImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    /**
     * Generate voting place image
     * 
     * POST /api/v1/voting-place/generate-image
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cedula' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cedula = $request->input('cedula');
            
            // Buscar votante por cédula sin filtro de tenant
            $voter = Voter::withoutGlobalScope(TenantScope::class)
                ->where('cedula', $cedula)
                ->first();

            if (!$voter) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voter not found',
                    'error' => 'No se encontró un votante con la cédula proporcionada'
                ], 404);
            }

            // Verificar que tenga datos de votación
            if (empty($voter->departamento_votacion) || empty($voter->municipio_votacion) || 
                empty($voter->puesto_votacion) || empty($voter->mesa_votacion)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incomplete voting data',
                    'error' => 'El votante no tiene información completa del puesto de votación'
                ], 422);
            }

            $votingData = [
                'departamento' => strtoupper($voter->departamento_votacion),
                'ciudad' => strtoupper($voter->municipio_votacion),
                'puesto' => strtoupper($voter->puesto_votacion),
                'mesa' => $voter->mesa_votacion,
                'direccion' => $voter->direccion_votacion,
            ];

            $imageUrl = $this->imageService->generateVotingPlaceImage($cedula, $votingData);
            
            // Leer la imagen y convertirla a base64
            $imagePath = public_path(str_replace(url('/'), '', $imageUrl));
            $imageBase64 = null;
            
            if (file_exists($imagePath)) {
                $imageData = file_get_contents($imagePath);
                $imageBase64 = base64_encode($imageData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Voting place image generated successfully',
                'data' => [
                    'cedula' => $cedula,
                    'nombres' => $voter->nombres,
                    'apellidos' => $voter->apellidos,
                    'image_url' => $imageUrl,
                    'image_base64' => $imageBase64,
                    'voting_data' => $votingData,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error generating voting place image', [
                'cedula' => $request->input('cedula'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate voting place image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate and send voting place image via WhatsApp
     * 
     * POST /api/v1/voting-place/send-whatsapp
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendWhatsApp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cedula' => 'required|string|max:20',
            'phone' => 'nullable|string|max:20', // Opcional, se puede tomar del votante
            'tenant_id' => 'required|integer|exists:tenants,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cedula = $request->input('cedula');
            $tenantId = $request->input('tenant_id');
            
            // Buscar votante por cédula sin filtro de tenant
            $voter = Voter::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('cedula', $cedula)
                ->first();

            if (!$voter) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voter not found',
                    'error' => 'No se encontró un votante con la cédula proporcionada'
                ], 404);
            }

            // Verificar que tenga datos de votación
            if (empty($voter->departamento_votacion) || empty($voter->municipio_votacion) || 
                empty($voter->puesto_votacion) || empty($voter->mesa_votacion)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incomplete voting data',
                    'error' => 'El votante no tiene información completa del puesto de votación'
                ], 422);
            }

            // Usar el teléfono proporcionado o el del votante
            $phone = $request->input('phone') ?? $voter->telefono;

            if (empty($phone)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number required',
                    'error' => 'No se proporcionó un número de teléfono y el votante no tiene teléfono registrado'
                ], 422);
            }
            
            $votingData = [
                'departamento' => strtoupper($voter->departamento_votacion),
                'ciudad' => strtoupper($voter->municipio_votacion),
                'puesto' => strtoupper($voter->puesto_votacion),
                'mesa' => $voter->mesa_votacion,
                'direccion' => $voter->direccion_votacion ?? '',
            ];

            $success = $this->imageService->sendVotingPlaceImageWhatsApp(
                $phone,
                $cedula,
                $votingData,
                $tenantId
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Voting place image sent successfully via WhatsApp',
                    'data' => [
                        'cedula' => $cedula,
                        'nombres' => $voter->nombres,
                        'apellidos' => $voter->apellidos,
                        'phone' => $phone,
                        'voting_data' => $votingData,
                    ]
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to send WhatsApp message'
            ], 500);

        } catch (\Exception $e) {
            Log::error('Error sending voting place image via WhatsApp', [
                'cedula' => $request->input('cedula'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send voting place image via WhatsApp',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
