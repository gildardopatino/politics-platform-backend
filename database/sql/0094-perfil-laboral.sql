-- =============================================================================
-- Spec 0094 · Perfil laboral del votante (bolsa de empleo)
--
-- Esquema para aplicar A MANO en producción (PostgreSQL). Es el equivalente de
-- estas cuatro migraciones de Laravel:
--
--   database/migrations/2026_09_16_120000_create_occupations_table.php
--   database/migrations/2026_09_16_120100_create_occupation_aliases_table.php
--   database/migrations/2026_09_16_120200_create_voter_profiles_table.php
--   database/migrations/2026_09_16_120300_create_voter_occupations_table.php
--
-- El bloque final las inserta en la tabla `migrations`. Sin eso, el siguiente
-- `php artisan migrate` del despliegue intentaría crear tablas que ya existen y
-- se caería a mitad de camino.
--
-- Todo es aditivo: ninguna tabla existente se toca, así que el código viejo
-- sigue funcionando entre este script y el redeploy.
--
-- Uso:
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f 0094-perfil-laboral.sql
--
-- Idempotente: se puede correr dos veces sin daño (`IF NOT EXISTS` y
-- `ON CONFLICT DO NOTHING`).
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- 1. Catálogo global de oficios (sin tenant_id, como voting_places)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS occupations (
    id          bigserial PRIMARY KEY,
    nombre      varchar(255) NOT NULL,
    activo      boolean      NOT NULL DEFAULT true,
    created_at  timestamp(0) without time zone NULL,
    updated_at  timestamp(0) without time zone NULL,
    CONSTRAINT occupations_nombre_unique UNIQUE (nombre)
);

-- -----------------------------------------------------------------------------
-- 2. Sinónimos («celador» → «Vigilante»)
--
-- `alias` es único en toda la tabla, no por oficio: un sinónimo que apuntara a
-- dos oficios volvería ambigua la resolución.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS occupation_aliases (
    id              bigserial PRIMARY KEY,
    occupation_id   bigint       NOT NULL,
    alias           varchar(255) NOT NULL,
    created_at      timestamp(0) without time zone NULL,
    updated_at      timestamp(0) without time zone NULL,
    CONSTRAINT occupation_aliases_alias_unique UNIQUE (alias),
    CONSTRAINT occupation_aliases_occupation_id_foreign
        FOREIGN KEY (occupation_id) REFERENCES occupations (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS occupation_aliases_occupation_id_index
    ON occupation_aliases (occupation_id);

-- -----------------------------------------------------------------------------
-- 3. Perfil laboral — uno por votante (dato personal, Ley 1581)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS voter_profiles (
    id                          bigserial PRIMARY KEY,
    tenant_id                   bigint  NOT NULL,
    voter_id                    bigint  NOT NULL,
    busca_empleo                boolean NOT NULL DEFAULT false,
    disponibilidad              varchar(255) NULL,
    nivel_educativo             varchar(255) NULL,
    anios_experiencia           smallint NULL,
    notas                       text     NULL,
    autoriza_tratamiento_datos  boolean  NOT NULL DEFAULT false,
    autorizado_at               timestamp(0) without time zone NULL,
    created_by                  bigint   NULL,
    created_at                  timestamp(0) without time zone NULL,
    updated_at                  timestamp(0) without time zone NULL,
    CONSTRAINT voter_profiles_voter_id_unique UNIQUE (voter_id),
    -- `unsignedSmallInteger` de Laravel; Postgres no tiene enteros sin signo, así
    -- que el rango se sostiene con un CHECK.
    CONSTRAINT voter_profiles_anios_experiencia_check
        CHECK (anios_experiencia IS NULL OR anios_experiencia >= 0),
    CONSTRAINT voter_profiles_tenant_id_foreign
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT voter_profiles_voter_id_foreign
        FOREIGN KEY (voter_id) REFERENCES voters (id) ON DELETE CASCADE,
    CONSTRAINT voter_profiles_created_by_foreign
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS voter_profiles_tenant_id_busca_empleo_index
    ON voter_profiles (tenant_id, busca_empleo);

-- -----------------------------------------------------------------------------
-- 4. Votante ↔ oficio, con la calidad del vínculo (busca | experiencia)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS voter_occupations (
    id              bigserial PRIMARY KEY,
    tenant_id       bigint      NOT NULL,
    voter_id        bigint      NOT NULL,
    occupation_id   bigint      NOT NULL,
    relacion        varchar(20) NOT NULL,
    created_at      timestamp(0) without time zone NULL,
    updated_at      timestamp(0) without time zone NULL,
    CONSTRAINT voter_occupations_unique UNIQUE (voter_id, occupation_id, relacion),
    CONSTRAINT voter_occupations_tenant_id_foreign
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT voter_occupations_voter_id_foreign
        FOREIGN KEY (voter_id) REFERENCES voters (id) ON DELETE CASCADE,
    CONSTRAINT voter_occupations_occupation_id_foreign
        FOREIGN KEY (occupation_id) REFERENCES occupations (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS voter_occupations_tenant_id_occupation_id_index
    ON voter_occupations (tenant_id, occupation_id);

-- =============================================================================
-- 5. Catálogo base de oficios y sinónimos
--
-- Mismo contenido que `Database\Seeders\OccupationsSeeder::CATALOGO` (de ahí se
-- generó). Alternativa equivalente tras el despliegue:
--   php artisan db:seed --class=OccupationsSeeder
-- =============================================================================

INSERT INTO occupations (nombre, activo, created_at, updated_at)
SELECT v.nombre, v.activo, NOW(), NOW()
FROM (VALUES
    ('Vigilante', true),
    ('Conductor', true),
    ('Contable', true),
    ('Aseo y servicios generales', true),
    ('Construcción', true),
    ('Domicilios y mensajería', true),
    ('Mesero', true),
    ('Cocina', true),
    ('Auxiliar de bodega', true),
    ('Jardinería', true),
    ('Secretariado', true),
    ('Ventas', true),
    ('Docencia', true),
    ('Salud', true),
    ('Sistemas', true),
    ('Belleza', true),
    ('Confección', true),
    ('Agricultura', true),
    ('Electricidad', true),
    ('Plomería', true),
    ('Mecánica', true),
    ('Panadería', true),
    ('Cuidado de personas', true),
    ('Call center', true),
    ('Servicios domésticos', true),
    ('Soldadura', true),
    ('Carpintería', true),
    ('Logística', true)
) AS v(nombre, activo)
ON CONFLICT (nombre) DO NOTHING;

INSERT INTO occupation_aliases (occupation_id, alias, created_at, updated_at)
SELECT o.id, v.alias, NOW(), NOW()
FROM (VALUES
    ('celador', 'Vigilante'),
    ('guarda de seguridad', 'Vigilante'),
    ('guardia de seguridad', 'Vigilante'),
    ('vigilancia', 'Vigilante'),
    ('chofer', 'Conductor'),
    ('chófer', 'Conductor'),
    ('conductor de camión', 'Conductor'),
    ('conductor de bus', 'Conductor'),
    ('contador', 'Contable'),
    ('contadora', 'Contable'),
    ('auxiliar contable', 'Contable'),
    ('contabilidad', 'Contable'),
    ('aseo', 'Aseo y servicios generales'),
    ('servicios generales', 'Aseo y servicios generales'),
    ('aseadora', 'Aseo y servicios generales'),
    ('aseador', 'Aseo y servicios generales'),
    ('oficios varios', 'Aseo y servicios generales'),
    ('obra', 'Construcción'),
    ('albañil', 'Construcción'),
    ('ayudante de obra', 'Construcción'),
    ('maestro de obra', 'Construcción'),
    ('construccion', 'Construcción'),
    ('domicilios', 'Domicilios y mensajería'),
    ('mototaxi', 'Domicilios y mensajería'),
    ('mensajero', 'Domicilios y mensajería'),
    ('repartidor', 'Domicilios y mensajería'),
    ('domiciliario', 'Domicilios y mensajería'),
    ('mesera', 'Mesero'),
    ('salonero', 'Mesero'),
    ('meseros', 'Mesero'),
    ('cocinero', 'Cocina'),
    ('cocinera', 'Cocina'),
    ('ayudante de cocina', 'Cocina'),
    ('chef', 'Cocina'),
    ('bodeguero', 'Auxiliar de bodega'),
    ('almacenista', 'Auxiliar de bodega'),
    ('bodega', 'Auxiliar de bodega'),
    ('jardinero', 'Jardinería'),
    ('jardinera', 'Jardinería'),
    ('secretaria', 'Secretariado'),
    ('secretario', 'Secretariado'),
    ('asistente administrativa', 'Secretariado'),
    ('auxiliar administrativo', 'Secretariado'),
    ('vendedor', 'Ventas'),
    ('vendedora', 'Ventas'),
    ('asesor comercial', 'Ventas'),
    ('impulsadora', 'Ventas'),
    ('ventas y mercadeo', 'Ventas'),
    ('docente', 'Docencia'),
    ('profesor', 'Docencia'),
    ('profesora', 'Docencia'),
    ('maestro', 'Docencia'),
    ('educadora', 'Docencia'),
    ('auxiliar de enfermería', 'Salud'),
    ('enfermera', 'Salud'),
    ('enfermero', 'Salud'),
    ('auxiliar de salud', 'Salud'),
    ('técnico en sistemas', 'Sistemas'),
    ('soporte técnico', 'Sistemas'),
    ('informática', 'Sistemas'),
    ('tecnología', 'Sistemas'),
    ('peluquero', 'Belleza'),
    ('peluquera', 'Belleza'),
    ('estilista', 'Belleza'),
    ('manicurista', 'Belleza'),
    ('barbero', 'Belleza'),
    ('modista', 'Confección'),
    ('costurera', 'Confección'),
    ('sastre', 'Confección'),
    ('confeccion', 'Confección'),
    ('agricultor', 'Agricultura'),
    ('campesino', 'Agricultura'),
    ('jornalero', 'Agricultura'),
    ('recolector', 'Agricultura'),
    ('agro', 'Agricultura'),
    ('electricista', 'Electricidad'),
    ('técnico electricista', 'Electricidad'),
    ('plomero', 'Plomería'),
    ('fontanero', 'Plomería'),
    ('mecánico', 'Mecánica'),
    ('mecánico automotriz', 'Mecánica'),
    ('latonero', 'Mecánica'),
    ('pintura automotriz', 'Mecánica'),
    ('panadero', 'Panadería'),
    ('pastelero', 'Panadería'),
    ('repostería', 'Panadería'),
    ('niñera', 'Cuidado de personas'),
    ('cuidadora', 'Cuidado de personas'),
    ('acompañante de adulto mayor', 'Cuidado de personas'),
    ('cuidador', 'Cuidado de personas'),
    ('teleoperador', 'Call center'),
    ('agente de call center', 'Call center'),
    ('asesor telefónico', 'Call center'),
    ('telemercadeo', 'Call center'),
    ('empleada doméstica', 'Servicios domésticos'),
    ('servicio doméstico', 'Servicios domésticos'),
    ('interna', 'Servicios domésticos'),
    ('soldador', 'Soldadura'),
    ('ornamentador', 'Soldadura'),
    ('carpintero', 'Carpintería'),
    ('ebanista', 'Carpintería'),
    ('auxiliar logístico', 'Logística'),
    ('operario de carga', 'Logística'),
    ('estibador', 'Logística')
) AS v(alias, oficio)
JOIN occupations o ON o.nombre = v.oficio
ON CONFLICT (alias) DO NOTHING;

-- =============================================================================
-- 6. Marcar las migraciones como aplicadas
--
-- Esto es lo que evita que el próximo `php artisan migrate` intente volver a
-- crear estas cuatro tablas. Van todas en el mismo `batch`, el siguiente al
-- último que registre la base.
-- =============================================================================

INSERT INTO migrations (migration, batch)
SELECT v.migration, (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations)
FROM (VALUES
    ('2026_09_16_120000_create_occupations_table'),
    ('2026_09_16_120100_create_occupation_aliases_table'),
    ('2026_09_16_120200_create_voter_profiles_table'),
    ('2026_09_16_120300_create_voter_occupations_table')
) AS v(migration)
WHERE NOT EXISTS (
    SELECT 1 FROM migrations m WHERE m.migration = v.migration
);

COMMIT;

-- Comprobación rápida tras aplicarlo:
--   SELECT COUNT(*) FROM occupations;        -- 28 oficios
--   SELECT COUNT(*) FROM occupation_aliases; -- 104 sinónimos
--   SELECT migration, batch FROM migrations ORDER BY id DESC LIMIT 4;
