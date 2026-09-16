# Perfil laboral del votante · Bolsa de empleo

Spec 0094 · Backend (Parte A).

En las reuniones la gente le pide trabajo al candidato. Este módulo guarda ese
dato ligado al votante y —sobre todo— lo hace **consultable**: el valor no está
en almacenar, está en poder responder «¿tenemos a alguien para esta vacante?».

---

## 1. Modelo de datos

Cuatro tablas. Nombres en inglés (como `voters` y `voting_places`), columnas en
español.

### `voter_profiles` — el perfil, uno por votante

| Columna | Tipo | Notas |
| --- | --- | --- |
| `tenant_id` | FK `tenants` | `HasTenant` lo rellena y `TenantScope` lo acota |
| `voter_id` | FK `voters`, **unique** | `cascadeOnDelete` |
| `busca_empleo` | bool, default `false` | |
| `disponibilidad` | string, null | «inmediata», «fines de semana»… |
| `nivel_educativo` | string, null | |
| `anios_experiencia` | unsigned small, null | validado 0–70 |
| `notas` | text, null | lo que dijo en la reunión |
| `autoriza_tratamiento_datos` | bool, default `false` | Ley 1581 (§5) |
| `autorizado_at` | timestamp, null | se sella/limpia solo |
| `created_by` | FK `users`, null | |

Índice `(tenant_id, busca_empleo)`: el filtro más usado de la bolsa.

### `occupations` — catálogo global de oficios

`nombre` (unique) · `activo` (default `true`). **Sin `tenant_id`**, igual que
`voting_places`: un oficio es el mismo en todas las campañas y compartirlo es lo
que hace que la búsqueda case. Solo lectura por API; se siembra.

### `occupation_aliases` — sinónimos

`occupation_id` (FK cascade) · `alias` (**unique en toda la tabla**). El alias es
único globalmente y no por oficio: un sinónimo que apuntara a dos oficios haría
ambigua la resolución.

### `voter_occupations` — votante ↔ oficio

`tenant_id` · `voter_id` · `occupation_id` · `relacion` ∈ {`busca`,
`experiencia`}. Unique `(voter_id, occupation_id, relacion)`, índice
`(tenant_id, occupation_id)`. `HasTenant`.

La distinción de `relacion` es lo que hace útil la bolsa: quien tiene una vacante
quiere a los que **buscan** ese oficio; quien arma un equipo quiere a los que
tienen **experiencia** en él.

---

## 2. Contrato de API

Todo bajo el grupo autenticado + tenant, en `/api/v1`. Respuestas con la forma
estándar `{ data, meta? , message? }` vía Resources.

| Método | Ruta | Permiso |
| --- | --- | --- |
| `GET` | `/oficios` | `view_voter_profiles` |
| `GET` | `/voters/perfiles` | `view_voter_profiles` |
| `GET` | `/voters/{voter}/perfil` | `view_voter_profiles` |
| `PUT` | `/voters/{voter}/perfil` | `manage_voter_profiles` |

> **Orden de rutas (Spec 0006).** `/voters/perfiles` se declara **antes** del
> `apiResource('voters')`. Declarada después, el binding de `/voters/{voter}`
> intentaría resolver un votante con id «perfiles» y el endpoint respondería 404.

### `GET /oficios`

Catálogo **activo**, ordenado por nombre. Alimenta el selector del formulario.

```json
{ "data": [ { "id": 1, "nombre": "Vigilante", "activo": true } ] }
```

### `GET /voters/perfiles` — la bolsa de empleo

Parámetros (todos opcionales):

| Parámetro | Qué hace |
| --- | --- |
| `oficio` | id del catálogo **o** texto; el texto resuelve por sinónimos |
| `relacion` | `busca` \| `experiencia` |
| `busca_empleo` | `1` / `0` |
| `q` | texto contenido en `notas` |
| `per_page` | por defecto 15, tope 100 |

Un `oficio` que no resuelve a ningún oficio ni alias devuelve **lista vacía**, no
un error. Los votantes borrados (soft delete) no aparecen.

```json
{
  "data": [
    {
      "id": 12,
      "voter_id": 340,
      "busca_empleo": true,
      "disponibilidad": "inmediata",
      "anios_experiencia": 5,
      "notas": "Pidió turno de noche.",
      "autoriza_tratamiento_datos": true,
      "autorizado_at": "2026-09-16T14:02:11.000000Z",
      "oficios": [ { "id": 1, "nombre": "Vigilante", "relacion": "busca" } ],
      "votante": {
        "id": 340,
        "cedula": "1110xxxxxx",
        "nombre_completo": "…",
        "telefono": "31xxxxxxxx"
      }
    }
  ],
  "meta": { "total": 1, "current_page": 1, "last_page": 1, "per_page": 15 }
}
```

El bloque `votante` lleva solo lo necesario para llamar a la persona; el resto
del votante se pide por su propio endpoint.

### `GET /voters/{voter}/perfil`

Devuelve el perfil con sus oficios. Un votante **sin** perfil responde `200` con
`data: null` — no es un error, es alguien a quien todavía no se le ha preguntado,
y el formulario abre vacío.

### `PUT /voters/{voter}/perfil`

Upsert del perfil. **Reemplaza** el conjunto de oficios enviado: no acumula.

```json
{
  "busca_empleo": true,
  "disponibilidad": "inmediata",
  "nivel_educativo": "bachillerato",
  "anios_experiencia": 5,
  "notas": "Dijo que necesita algo cerca del barrio.",
  "autoriza_tratamiento_datos": true,
  "oficios": [
    { "occupation_id": 1, "relacion": "busca" },
    { "occupation_id": 2, "relacion": "experiencia" }
  ]
}
```

- Sin la clave `oficios`, los oficios **no se tocan**. Con `oficios: []`, se
  vacían: el conjunto enviado es el conjunto final.
- Un `occupation_id` que no existe → `422`. El formulario **nunca** da de alta un
  oficio en el catálogo global; llenarlo de grafías sueltas rompería justo lo que
  los sinónimos vienen a arreglar.
- `anios_experiencia` fuera de 0–70 → `422`.
- Votante inexistente **o de otro tenant** → `404` (no se distinguen, para no
  filtrar la existencia).

---

## 3. Sinónimos: cómo resuelve, y por qué no hay N+1

`?oficio=celador` devuelve los de «Vigilante». La resolución vive en
`App\Services\VoterProfileService`:

1. El texto se normaliza —minúsculas, sin acentos, un solo espacio— con
   `PuestoResolver::norm()`, la **misma** función que usa el cruce del E-14. Una
   sola regla de normalización en el código; dos se desincronizan.
2. El catálogo se lee **entero y una vez**, nombres y alias en un solo
   `LEFT JOIN`, y se indexa en memoria por su forma normalizada.
3. El término buscado se resuelve contra ese mapa.

La normalización se hace en PHP y no en SQL por lo mismo que en `PuestoResolver`:
cada motor la escribe distinto y el resultado tiene que ser idéntico en SQLite
(la suite) y en Postgres (producción). Eso obliga a traer el catálogo para
comparar — por eso se trae una vez y no por término (Art. VI). El catálogo tiene
decenas de filas, no miles.

El buscador carga `voter` y `occupations` con eager loading, así que su coste es
constante en número de consultas y no crece con los resultados. Hay una prueba
que lo cuenta.

---

## 4. Permisos

Dos permisos propios, aparte de `view_voters`: quien gestiona el padrón no tiene
por qué ver quién de la campaña está buscando trabajo.

| Permiso | Para qué |
| --- | --- |
| `view_voter_profiles` | catálogo, bolsa de empleo y lectura del perfil |
| `manage_voter_profiles` | escribir el perfil |

Sembrados en `RolesAndPermissionsSeeder` desde `App\Support\Permissions`
(Art. VIII). Por rol: `admin` y `coordinator` los dos; `operator` los dos
—quien atiende la reunión es quien captura—; `viewer` solo lectura.

---

## 5. Datos personales y Ley 1581 (habeas data)

La situación laboral de una persona es **dato personal**. Tratarlo exige
autorización del titular (Ley 1581 de 2012), y por eso la casilla no es
cosmética:

- `autoriza_tratamiento_datos` marca la autorización; al marcarla se **sella**
  `autorizado_at` con la fecha, y al desmarcarla se **limpia**. Una autorización
  ya sellada conserva su fecha original: es la que habría que poder probar.
- El perfil **no aparece en ninguna ruta pública** — ni en el check-in por QR, ni
  en la vista pública de la reunión, ni en el `verify-document` público. Hay
  pruebas que lo verifican sobre el cuerpo de las respuestas.
- No se registra en logs.
- La **política de tratamiento de datos** como tal es responsabilidad de la
  campaña, no del software: el sistema deja constancia de la autorización, no la
  sustituye.

El sistema **registra** que alguien busca empleo; no promete nada. Es registro de
gestión social y así debe rotularse en la UI.

---

## 6. Catálogo de oficios

`Database\Seeders\OccupationsSeeder` siembra 28 oficios frecuentes en Colombia
con sus 104 sinónimos (vigilante ≈ celador ≈ guarda de seguridad, contable ≈
contador ≈ auxiliar contable, conductor ≈ chofer, aseo ≈ servicios generales,
construcción ≈ obra ≈ albañil, domicilios ≈ mototaxi…). Es idempotente
(`updateOrCreate`) y está registrado en `DatabaseSeeder`.

La constante `OccupationsSeeder::CATALOGO` es la fuente: de ahí se generó también
el catálogo del SQL manual, y una prueba verifica que los dos siguen diciendo lo
mismo.

---

## 7. Despliegue (esquema por duplicado)

La base de desarrollo está ligada a producción, así que el esquema se entrega dos
veces y ninguna de las dos sobra:

1. **Migraciones de Laravel** (`database/migrations/2026_09_16_1200*`) — la suite
   corre `migrate:fresh` sobre SQLite y sin ellas no hay tablas.
2. **SQL manual** (`database/sql/0094-perfil-laboral.sql`) — DDL equivalente para
   PostgreSQL, el catálogo de oficios, y al final los `INSERT INTO migrations`
   que marcan esas cuatro migraciones como aplicadas.

Ese último bloque es el que evita el accidente: si Laravel no las ve aplicadas,
el siguiente `migrate` del despliegue intentaría recrear tablas que ya existen.

Orden en producción:

```
1. psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/sql/0094-perfil-laboral.sql
2. (opcional, el SQL ya lo hace) php artisan db:seed --class=OccupationsSeeder
3. Redeploy del backend con RUN_MIGRATIONS=false
4. Redeploy del frontend (Parte B)
```

Todo es aditivo: ninguna tabla existente cambia, así que el código viejo sigue
funcionando entre el paso 1 y el 3.
