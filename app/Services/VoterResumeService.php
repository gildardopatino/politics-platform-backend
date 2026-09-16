<?php

namespace App\Services;

use App\Models\Voter;
use App\Models\VoterResume;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dónde viven las hojas de vida y cómo se alcanzan (Spec 0096).
 *
 * Disco `local` —`storage/app/private`, no servible por web— y una carpeta por
 * tenant. El nombre en disco lo genera el servidor: el que traiga el usuario es
 * dato de entrada, y usarlo como ruta es cómo se sale de la carpeta propia. El
 * original se conserva aparte, solo para mostrarlo.
 */
class VoterResumeService
{
    /**
     * Guarda el archivo y deja la fila.
     *
     * El archivo se escribe primero y la fila después, dentro de una
     * transacción: si la escritura de la fila falla, se borra el archivo. Al
     * revés quedaría una fila apuntando a nada, que es peor —la lista la
     * enseñaría y la descarga daría 404 sin explicación.
     *
     * La comprobación de la Ley 1581 no está aquí sino en el FormRequest, que
     * corre **antes** de que esto se llame: así no hay forma de que una subida
     * sin autorización llegue a tocar el disco.
     */
    public function guardar(Voter $voter, UploadedFile $archivo, ?int $usuarioId = null): VoterResume
    {
        $clave = $this->claveDe($voter, $archivo);

        $this->disco()->put($clave, file_get_contents($archivo->getRealPath()));

        try {
            return DB::transaction(fn () => VoterResume::create([
                'voter_id' => $voter->id,
                'archivo_key' => $clave,
                'nombre_original' => $this->nombreVisible($archivo),
                'mime' => $archivo->getMimeType() ?: 'application/octet-stream',
                'tamano_bytes' => $archivo->getSize() ?: 0,
                'subido_por' => $usuarioId,
            ]));
        } catch (\Throwable $e) {
            // Sin fila no debe quedar archivo: un huérfano en disco no lo reclama
            // nadie y nadie sabe de quién era.
            $this->disco()->delete($clave);

            throw $e;
        }
    }

    /**
     * Enlace de descarga temporal.
     *
     * Ruta firmada de Laravel, igual que el archivo del acta E-14: vale sin
     * sesión —el navegador la abre en otra pestaña—, dura minutos, y la firma va
     * atada a la hoja **y a su tenant**, así que cambiarle el id no sirve para
     * pedir otra.
     */
    public function urlFirmada(VoterResume $resume): string
    {
        return URL::temporarySignedRoute(
            'hojas-vida.archivo',
            now()->addMinutes((int) config('voter_resumes.url_ttl_minutes')),
            ['resume' => $resume->id, 'tenant' => $resume->tenant_id],
        );
    }

    public function existe(VoterResume $resume): bool
    {
        return filled($resume->archivo_key) && $this->disco()->exists($resume->archivo_key);
    }

    public function descargar(VoterResume $resume): StreamedResponse
    {
        return $this->disco()->download(
            $resume->archivo_key,
            $resume->nombre_original,
            ['Content-Type' => $resume->mime],
        );
    }

    /**
     * Borra la fila y su archivo. Un archivo que ya no está no impide borrar la
     * fila: lo que se quita del listado es la fila, y dejarla por eso sería
     * dejar visible algo que ya no se puede descargar.
     */
    public function borrar(VoterResume $resume): void
    {
        $clave = $resume->archivo_key;

        $resume->delete();

        if (filled($clave) && $this->disco()->exists($clave)) {
            $this->disco()->delete($clave);
        }
    }

    /**
     * `hojas-vida/{tenant}/{voter}/{ulid}.{ext}` — nombre generado, nunca el del
     * usuario. La extensión se toma de la que adivina el contenido cuando se
     * puede, para que el archivo en disco diga la verdad de lo que es.
     */
    private function claveDe(Voter $voter, UploadedFile $archivo): string
    {
        $extension = Str::lower($archivo->extension() ?: $archivo->getClientOriginalExtension() ?: 'bin');
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';

        return "hojas-vida/{$voter->tenant_id}/{$voter->id}/".Str::ulid().'.'.$extension;
    }

    /**
     * El nombre que verá la gente: el suyo, pero saneado y acotado. Se guarda
     * para mostrarlo, no para construir rutas.
     */
    private function nombreVisible(UploadedFile $archivo): string
    {
        $nombre = basename($archivo->getClientOriginalName());
        $nombre = preg_replace('/[\x00-\x1F\/\\\\]+/u', '', $nombre) ?: 'hoja-de-vida';

        return Str::limit(trim($nombre), 180, '');
    }

    private function disco(): Filesystem
    {
        return Storage::disk(config('voter_resumes.disk'));
    }
}
