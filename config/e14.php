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

    /**
     * Umbrales de la señal «posible inflado» del scorecard de líderes (0063).
     *
     * Son un **parámetro revisable, no un veredicto**: marcan a quién mirar
     * primero —mucha movilización en mesas que rindieron por debajo de la base
     * identificada—, nunca a quién acusar. Las dos condiciones van juntas a
     * propósito: sin volumen no hay base que inflar, y sin déficit no hay nada
     * que explicar.
     */
    'rendimiento_lideres' => [
        /** Personas identificadas a partir de las cuales la movilización es «mucha». */
        'umbral_movilizados' => (int) env('E14_UMBRAL_MOVILIZADOS', 20),

        /**
         * Puntos porcentuales de su base que no se reflejaron en votos, ponderados
         * por su presencia en cada mesa. 30 = sus mesas rindieron al 70 % o menos.
         */
        'umbral_deficit_ponderado' => (float) env('E14_UMBRAL_DEFICIT_PONDERADO', 30),
    ],

];
