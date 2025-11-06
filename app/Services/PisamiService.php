<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PisamiService
{
    protected $baseUrl = 'https://pisami.ibague.gov.co/app/PISAMI/modulos/administrativa/gestiondocumental/maestros/radicacion_pqr_publica/verifica_documento.php';

    /**
     * Verificar documento en la API de PISAMI
     * 
     * @param string $cedula
     * @return array|null
     */
    public function verifyDocument(string $cedula): ?array
    {
        try {
            // Hacer request GET a la API externa
            $response = Http::timeout(30)->get($this->baseUrl, [
                'doc' => $cedula
            ]);

            if (!$response->successful()) {
                Log::warning('PISAMI API request failed', [
                    'cedula' => $cedula,
                    'status' => $response->status()
                ]);
                return null;
            }

            $content = $response->body();

            // Parsear el contenido JavaScript
            return $this->parseJavaScriptResponse($content);

        } catch (\Exception $e) {
            Log::error('Error calling PISAMI API', [
                'cedula' => $cedula,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Parsear la respuesta JavaScript y extraer los valores
     * 
     * @param string $content
     * @return array|null
     */
    protected function parseJavaScriptResponse(string $content): ?array
    {
        // Verificar que el contenido tenga el formato esperado
        if (!str_contains($content, 'parent.document.f_pqr')) {
            return null;
        }

        // Extraer valores usando expresiones regulares
        $primerNombre = $this->extractValue($content, 'PRIMER_NOMBRE');
        $segundoNombre = $this->extractValue($content, 'SEGUNDO_NOMBRE');
        $primerApellido = $this->extractValue($content, 'PRIMER_APELLIDO');
        $segundoApellido = $this->extractValue($content, 'SEGUNDO_APELLIDO');
        $direccion = $this->extractValue($content, 'DIRECCION_NOTIFICACION');
        $telefono = $this->extractValue($content, 'TEL_MOVIL_NOTIFICACION');
        $email = $this->extractValue($content, 'EMAIL');

        // Combinar nombres y apellidos
        $nombres = trim($primerNombre . ' ' . $segundoNombre);
        $apellidos = trim($primerApellido . ' ' . $segundoApellido);

        // Verificar que al menos tengamos nombre y apellido
        if (empty($nombres) && empty($apellidos)) {
            return null;
        }

        return [
            'nombres' => $nombres ?: null,
            'apellidos' => $apellidos ?: null,
            'direccion' => $direccion ?: null,
            'telefono' => $telefono ?: null,
            'email' => $email ?: null,
        ];
    }

    /**
     * Extraer valor de un campo específico del JavaScript
     * 
     * @param string $content
     * @param string $fieldName
     * @return string
     */
    protected function extractValue(string $content, string $fieldName): string
    {
        // Patrón para encontrar: parent.document.f_pqr.CAMPO.value="VALOR";
        $pattern = '/parent\.document\.f_pqr\.' . preg_quote($fieldName, '/') . '\.value="([^"]*)"/';
        
        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}
