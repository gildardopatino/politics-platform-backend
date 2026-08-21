<?php

return [

    /*
    |--------------------------------------------------------------------------
    | La landing page del candidato (Spec 0085)
    |--------------------------------------------------------------------------
    |
    | La landing —banners, biografía, propuestas, eventos, galería, testimonios,
    | feed de redes, voluntarios y contacto— es vitrina pública: no toca ninguna
    | decisión de campaña. Se retiró del producto, y este interruptor es cómo se
    | retiró: **apagada por defecto**.
    |
    | Con el flag en `false` sus rutas ni siquiera se registran, así que los
    | endpoints públicos `landingpage/*` responden 404 y el admin de landing no
    | existe. Eso es deliberado: apagar un módulo que nadie usa también quita
    | superficie de ataque, y una ruta que no existe no se puede explotar.
    |
    | El código y las tablas siguen en su sitio: esta fase **apaga**, no borra.
    | Ponerlo en `true` devuelve la landing tal cual estaba. El borrado duro es
    | la Fase 2 de la 0085 y va aparte, cuando se confirme que nadie la usa.
    |
    | El sync de redes sociales se apaga con ella: alimenta el feed de la
    | landing y no tiene otro consumidor.
    |
    */

    'habilitada' => filter_var(env('LANDING_HABILITADA', false), FILTER_VALIDATE_BOOL),

];
