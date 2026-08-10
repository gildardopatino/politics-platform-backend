<?php

namespace App\Services\E14;

use App\Models\E14Acta;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los PDFs de las actas: dónde se guardan y cómo los alcanza el worker
 * (Spec 0071).
 *
 * El nombre en el disco es el **hash del contenido**, no el que traía el
 * archivo. Dos jurados que suben el mismo escaneo con nombres distintos ocupan
 * un solo objeto, y renombrar un PDF no lo convierte en un acta nueva — que es
 * la misma regla con la que el lector decide qué ya procesó.
 */
class E14ArchivoService
{
    /**
     * SHA-256 del contenido. Es la identidad del acta a efectos de deduplicación.
     */
    public function hashDe(UploadedFile $archivo): string
    {
        return hash_file('sha256', $archivo->getRealPath());
    }

    /**
     * Guarda el PDF y devuelve su ruta. Si ya estaba, no lo vuelve a escribir.
     */
    public function guardar(UploadedFile $archivo, int $tenantId, string $hash): string
    {
        $ruta = "e14/{$tenantId}/{$hash}.pdf";

        if (! $this->disco()->exists($ruta)) {
            $this->disco()->put($ruta, file_get_contents($archivo->getRealPath()));
        }

        return $ruta;
    }

    /**
     * URL con la que el worker descarga el PDF sin tener que autenticarse.
     *
     * Es una ruta firmada de Laravel y no un enlace prefirmado de S3, por tres
     * razones: dura lo que decidimos aquí —minutos, no los seis días del máximo
     * de S3—, funciona igual con el disco local, y la firma va atada **al acta y
     * a su tenant**, así que no se puede reutilizar para pedir otra.
     */
    public function urlFirmada(E14Acta $acta): ?string
    {
        if (blank($acta->archivo_path)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'e14.actas.archivo',
            now()->addMinutes((int) config('e14.url_ttl_minutes')),
            ['acta' => $acta->id, 'tenant' => $acta->tenant_id],
        );
    }

    public function existe(E14Acta $acta): bool
    {
        return filled($acta->archivo_path) && $this->disco()->exists($acta->archivo_path);
    }

    public function descargar(E14Acta $acta): StreamedResponse
    {
        return $this->disco()->response(
            $acta->archivo_path,
            $acta->archivo_nombre ?: basename($acta->archivo_path),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function borrar(E14Acta $acta): void
    {
        if ($this->existe($acta)) {
            $this->disco()->delete($acta->archivo_path);
        }
    }

    private function disco(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('e14.disk'));
    }
}
