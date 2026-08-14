<?php

namespace Tests\Unit\Services\E14;

use App\Services\E14\ProyeccionService;
use Tests\TestCase;

/**
 * La regla del semáforo, aislada (Spec 0064 · RF-4 y RF-5).
 *
 * Es una función de una sola variable —el avance— y de dos umbrales que viven en
 * `config/e14.php`. Se prueba aparte del endpoint porque los bordes exactos
 * (¿el 90 es verde o ámbar?) son lo que se discute cuando alguien mueve un
 * umbral, y ahí es donde tiene que estar escrito.
 */
class ProyeccionSemaforoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['e14.proyeccion.umbral_verde' => 90, 'e14.proyeccion.umbral_ambar' => 70]);
    }

    public function test_el_umbral_es_inclusive_por_abajo(): void
    {
        // Justo en el corte se enciende el color bueno: llegar al 90 % es
        // cumplirlo, no quedarse a las puertas.
        $this->assertSame(ProyeccionService::SEMAFORO_VERDE, ProyeccionService::semaforo(90.0));
        $this->assertSame(ProyeccionService::SEMAFORO_AMBAR, ProyeccionService::semaforo(89.99));
        $this->assertSame(ProyeccionService::SEMAFORO_AMBAR, ProyeccionService::semaforo(70.0));
        $this->assertSame(ProyeccionService::SEMAFORO_ROJO, ProyeccionService::semaforo(69.99));
    }

    public function test_pasarse_de_la_meta_sigue_siendo_verde(): void
    {
        $this->assertSame(ProyeccionService::SEMAFORO_VERDE, ProyeccionService::semaforo(150.0));
    }

    public function test_cero_es_rojo_pero_sin_avance_no_hay_color(): void
    {
        $this->assertSame(ProyeccionService::SEMAFORO_ROJO, ProyeccionService::semaforo(0.0));

        // Sin meta no hay porcentaje, y sin porcentaje no hay semáforo que
        // encender: el rojo diría «vas mal» donde lo que pasa es que nadie fijó
        // contra qué medirse.
        $this->assertSame(ProyeccionService::SEMAFORO_SIN_META, ProyeccionService::semaforo(null));
    }

    public function test_los_umbrales_salen_de_la_configuracion(): void
    {
        config(['e14.proyeccion.umbral_verde' => 50, 'e14.proyeccion.umbral_ambar' => 25]);

        $this->assertSame(ProyeccionService::SEMAFORO_VERDE, ProyeccionService::semaforo(60.0));
        $this->assertSame(ProyeccionService::SEMAFORO_AMBAR, ProyeccionService::semaforo(30.0));
        $this->assertSame(ProyeccionService::SEMAFORO_ROJO, ProyeccionService::semaforo(10.0));
    }
}
