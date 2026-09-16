<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hojas de vida del elector (Spec 0096)
    |--------------------------------------------------------------------------
    */

    /**
     * Disco donde viven los archivos. `local` apunta a `storage/app/private`, que
     * no es servible por web: una hoja de vida es PII densa y no puede quedar
     * detrás de una URL adivinable (Art. VII).
     *
     * OJO AL DESPLIEGUE: `storage/` vive dentro del contenedor. Sin un volumen
     * persistente montado en `/var/www/storage/app`, cada redeploy borra todas
     * las hojas de vida. Ver `docs/VOTER_PROFILES.md`.
     */
    'disk' => env('VOTER_RESUMES_DISK', 'local'),

    /**
     * Tamaño máximo en kilobytes. Una hoja de vida ronda 1–5 MB; 8 MB deja
     * margen para un escaneo generoso y no para subir cualquier cosa.
     */
    'max_upload_kb' => (int) env('VOTER_RESUMES_MAX_UPLOAD_KB', 8192),

    /**
     * Vigencia del enlace de descarga firmado. Corta a propósito: se emite para
     * que alguien la baje ahora, y un enlace que dura días acaba reenviado.
     */
    'url_ttl_minutes' => (int) env('VOTER_RESUMES_URL_TTL_MINUTES', 5),

];
