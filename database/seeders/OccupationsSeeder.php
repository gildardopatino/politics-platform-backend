<?php

namespace Database\Seeders;

use App\Models\Occupation;
use App\Models\OccupationAlias;
use Illuminate\Database\Seeder;

/**
 * Catálogo base de oficios y sus sinónimos (Spec 0094).
 *
 * Los oficios frecuentes en una campaña de Tolima: lo que la gente pide en las
 * reuniones. El canónico es el nombre con el que se muestra; los alias son las
 * otras formas en que la misma persona lo diría —«celador» por «vigilante»—, y
 * son lo que permite que quien busque encuentre.
 *
 * Idempotente (Art. VIII): `updateOrCreate` por la clave natural, así que correr
 * el seeder dos veces no duplica ni un oficio ni un alias.
 */
class OccupationsSeeder extends Seeder
{
    /**
     * Oficio canónico => sus sinónimos.
     *
     * Público porque es la fuente del catálogo también para el SQL manual de
     * producción (`database/sql/0094-perfil-laboral.sql`) y para las pruebas:
     * dos listas separadas se desincronizan el primer día.
     *
     * @var array<string, array<int, string>>
     */
    public const CATALOGO = [
        'Vigilante' => ['celador', 'guarda de seguridad', 'guardia de seguridad', 'vigilancia'],
        'Conductor' => ['chofer', 'chófer', 'conductor de camión', 'conductor de bus'],
        'Contable' => ['contador', 'contadora', 'auxiliar contable', 'contabilidad'],
        'Aseo y servicios generales' => ['aseo', 'servicios generales', 'aseadora', 'aseador', 'oficios varios'],
        'Construcción' => ['obra', 'albañil', 'ayudante de obra', 'maestro de obra', 'construccion'],
        'Domicilios y mensajería' => ['domicilios', 'mototaxi', 'mensajero', 'repartidor', 'domiciliario'],
        'Mesero' => ['mesera', 'salonero', 'meseros'],
        'Cocina' => ['cocinero', 'cocinera', 'ayudante de cocina', 'chef'],
        'Auxiliar de bodega' => ['bodeguero', 'almacenista', 'bodega'],
        'Jardinería' => ['jardinero', 'jardinera'],
        'Secretariado' => ['secretaria', 'secretario', 'asistente administrativa', 'auxiliar administrativo'],
        'Ventas' => ['vendedor', 'vendedora', 'asesor comercial', 'impulsadora', 'ventas y mercadeo'],
        'Docencia' => ['docente', 'profesor', 'profesora', 'maestro', 'educadora'],
        'Salud' => ['auxiliar de enfermería', 'enfermera', 'enfermero', 'auxiliar de salud'],
        'Sistemas' => ['técnico en sistemas', 'soporte técnico', 'informática', 'tecnología'],
        'Belleza' => ['peluquero', 'peluquera', 'estilista', 'manicurista', 'barbero'],
        'Confección' => ['modista', 'costurera', 'sastre', 'confeccion'],
        'Agricultura' => ['agricultor', 'campesino', 'jornalero', 'recolector', 'agro'],
        'Electricidad' => ['electricista', 'técnico electricista'],
        'Plomería' => ['plomero', 'fontanero'],
        'Mecánica' => ['mecánico', 'mecánico automotriz', 'latonero', 'pintura automotriz'],
        'Panadería' => ['panadero', 'pastelero', 'repostería'],
        'Cuidado de personas' => ['niñera', 'cuidadora', 'acompañante de adulto mayor', 'cuidador'],
        'Call center' => ['teleoperador', 'agente de call center', 'asesor telefónico', 'telemercadeo'],
        'Servicios domésticos' => ['empleada doméstica', 'servicio doméstico', 'interna'],
        'Soldadura' => ['soldador', 'ornamentador'],
        'Carpintería' => ['carpintero', 'ebanista'],
        'Logística' => ['auxiliar logístico', 'operario de carga', 'estibador'],
    ];

    public function run(): void
    {
        foreach (self::CATALOGO as $nombre => $alias) {
            $oficio = Occupation::updateOrCreate(['nombre' => $nombre], ['activo' => true]);

            foreach ($alias as $sinonimo) {
                OccupationAlias::updateOrCreate(
                    ['alias' => $sinonimo],
                    ['occupation_id' => $oficio->id]
                );
            }
        }
    }
}
