# Reseed seguro: geografía versionada y respaldo de leads

Cómo volver a levantar la base sin perder lo que importa (Spec 0059).

El problema que resuelve: `migrate:fresh --seed` borraba la geografía real —48
municipios, 47 con el contorno SVG que dibuja el mapa— porque el seeder solo
sabía crear dos departamentos de juguete; y borraba los `leads`, que no se
siembran en ninguna parte. Después de esta spec, la geografía vuelve sola y los
leads se restauran desde un respaldo local.

## Los dos tipos de dato, y por qué se tratan distinto

| | Geografía | `leads` |
| --- | --- | --- |
| Qué es | nombre, código, coordenadas, contorno SVG | personas: cédula, nombre, contacto, mesa de votación |
| ¿Va al repositorio? | **sí**, es dato público | **nunca**: el remoto está en GitHub |
| Dónde vive | `database/seeders/data/geography.json` | `storage/backups/` (gitignored) |
| Cómo vuelve | `db:seed` | `php artisan leads:restore` |

## Antes de un `migrate:fresh`

```bash
php artisan leads:backup
```

Escribe `storage/backups/leads_<fecha>.sql` con `pg_dump --data-only`. En esta
base son ~3,9 millones de filas y unos **441 MB**, en menos de cuatro segundos.
La carpeta está en `.gitignore`; el archivo **no se comparte ni se sube**.

## El reseed

```bash
php artisan migrate:fresh --seed
```

Deja la geografía real con sus `path` (el mapa de Geografía dibuja), los permisos
y roles sembrados, y el inventario limpio — de paso resuelve el
`reserved_quantity` inflado que dejó la 0056, porque las reservas nacen en cero.

## Después

```bash
php artisan leads:restore leads_20260809_171618.sql
```

Acepta el nombre del archivo (lo busca en `storage/backups/`) o una ruta
completa. Si la tabla ya tiene filas avisa y pide confirmación, porque el
respaldo trae los ids y restaurar encima duplica; `--force` se la salta.

## Cuando cambie la geografía

Si se cargan comunas, barrios o municipios nuevos, hay que volver a generar el
fixture para que el próximo reseed los conserve:

```bash
php artisan geo:export
git add database/seeders/data/geography.json
```

El comando exporta **solo** columnas de geografía (código, nombre, coordenadas,
`path`) y referencia a los padres por código, no por id — un id no sobrevive a un
reseed. Hay una prueba que vigila el fixture: si aparece una columna que no está
en la lista permitida, falla y obliga a revisar que no sea un dato de persona.

## Configuración

`pg_dump` y `psql` casi nunca están en el PATH en Windows, así que su ruta se
configura:

```env
PG_DUMP_PATH='C:/Program Files/PostgreSQL/16/bin/pg_dump.exe'
PSQL_PATH='C:/Program Files/PostgreSQL/16/bin/psql.exe'
GEOGRAPHY_FIXTURE=  # opcional: apunta a otro fixture
```

Dos detalles que costaron un rato y conviene no volver a descubrir:

- **`localhost` se traduce a `127.0.0.1`** al invocar los binarios. El resolvedor
  de `pg_dump` en Windows falla con «no se pudo traducir el nombre localhost»
  aunque PHP conecte por ese mismo nombre sin problema.
- **El entorno se hereda entero**, añadiéndole solo `PGPASSWORD`. Pasar un
  entorno recortado deja al ejecutable sin `PATH` ni `SystemRoot` y falla sin
  imprimir ningún error.

La contraseña viaja por variable de entorno y no como argumento: los argumentos
de un proceso se ven en la lista de procesos del sistema.

## Si no hay fixture

`GeographySeeder` cae a una geografía mínima de demostración (Tolima/Ibagué y
Cundinamarca/Bogotá con una comuna, un barrio, un corregimiento y una vereda).
Es lo que corre en la suite de pruebas, que va sobre SQLite.
