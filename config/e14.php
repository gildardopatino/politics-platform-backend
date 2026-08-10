<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Escrutinio E-14 (Specs 0061 y 0071)
    |--------------------------------------------------------------------------
    */

    /**
     * Disco donde viven los PDFs de las actas. Por defecto el mismo que el resto
     * de la aplicación (Wasabi/S3 en producción, `local` en pruebas).
     */
    'disk' => env('E14_DISK', env('FILESYSTEM_DISK', 'local')),

    /**
     * Tamaño máximo de un acta, en kilobytes. Un E-14 escaneado ronda el megabyte;
     * el margen está para escaneos a mucha resolución, no para subir cualquier cosa.
     */
    'max_upload_kb' => (int) env('E14_MAX_UPLOAD_KB', 20480),

    /**
     * Vigencia de la URL firmada con la que el worker descarga el PDF.
     *
     * Corta a propósito: se emite en el mismo momento en que el worker reclama el
     * acta y se usa segundos después. Un enlace que dura días es un enlace que
     * acaba en el historial de alguien.
     */
    'url_ttl_minutes' => (int) env('E14_URL_TTL_MINUTES', 15),

    /**
     * Cuánto puede estar un acta en `procesando` antes de darla por abandonada.
     *
     * Un worker que se cae a mitad de una lectura deja el acta reclamada y sin
     * dueño. Pasado este tiempo vuelve a la cola: es preferible leer dos veces la
     * misma acta —el resultado es idempotente— que perderla en silencio.
     */
    'claim_timeout_minutes' => (int) env('E14_CLAIM_TIMEOUT_MINUTES', 15),

    /**
     * Reclamos por acta antes de dejar de reintentarla.
     *
     * Sin tope, un PDF que hace caer al worker lo tumbaría una y otra vez a costa
     * del resto de la cola. Al llegar aquí el acta va a revisión manual.
     */
    'max_intentos' => (int) env('E14_MAX_INTENTOS', 3),

];
