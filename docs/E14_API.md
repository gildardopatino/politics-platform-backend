# API de escrutinio E-14

Spec `0061` · Parte A. Registra los resultados reales por candidato y mesa,
leídos de las actas E-14, y los consolida.

El cliente principal es el **lector de actas** (`plafform-politics-e14`, Parte B),
que corre sin navegador y publica cada acta que consigue leer. El panel usa la
misma API para consultarlas y corregirlas.

---

## La regla que gobierna todo

```
Σ candidatos + blanco + nulos + no marcados  =  suma declarada  =  votos en la urna
```

Un acta que cumple las tres igualdades queda `procesada` y entra al
consolidado. Si falla alguna, queda `inconsistente` con el motivo y **no suma**.

El servidor **recalcula siempre** esta cuenta con los números crudos que recibe,
aunque el cliente ya mande su propio veredicto. No es desconfianza en el lector:
el `estado` decide qué votos entran al total, así que no puede depender de que el
cliente lo calcule bien. Que ambos manden su resultado y gane el del servidor es
justamente lo que permite que una discrepancia se note.

Aparte va la **nivelación**: `dif_nivelacion = votantes_e11 − votos_urna`. Que
alguien se registrara y no depositara es una novedad del acta, no un error de
lectura; se anota y no bloquea nada.

---

## Autenticación

Dos clientes, dos credenciales, un solo middleware (`e14.auth`):

| Cliente | Credencial | Cómo se obtiene |
| --- | --- | --- |
| Panel | JWT normal | `POST /api/v1/login` |
| Lector de actas | token de servicio `e14_…` | `php artisan e14:token <tenant>` |

Ambas viajan en `Authorization: Bearer <valor>`. **El tenant sale siempre de la
credencial**: no hay cabecera ni campo del cuerpo con el que un cliente pueda
elegir a nombre de qué campaña escribe.

### Obtener y rotar el token del lector

```bash
php artisan e14:token miguel-alcalde
# Token de E-14 para «Miguel Alcalde» (slug: miguel-alcalde, id: 3):
#
#   e14_4f2c…              <- se muestra UNA sola vez
```

Va al `.env` del lector (que está en `.gitignore`):

```env
BACKEND_API_URL=https://api.tudominio.com
BACKEND_API_TOKEN=e14_4f2c…
```

En la base solo queda el **SHA-256**. Si se pierde el valor, no se recupera: se
rota.

```bash
php artisan e14:token miguel-alcalde --rotate
```

Rotar revoca el anterior **de inmediato** —el lector deja de publicar hasta que
se actualice su `.env`— y lo conserva marcado con `revoked_at` en vez de
borrarlo: si aparece algo publicado y nadie sabe quién lo publicó, el `last_used_at`
de cada token es lo primero que se mira.

El comando crea, la primera vez, un usuario de servicio del tenant
(`e14-reader+<slug>@servicio.local`) con `view_e14` y `manage_e14` y nada más.
Si el token se filtra, lo que se pierde es la capacidad de publicar actas de una
campaña; no una sesión de administrador. El token tampoco sirve para el resto de
la API: `e14.auth` solo cubre `/api/v1/e14/*`.

Sin credencial válida la API responde **401 y no escribe nada**.

---

## Permisos

| Permiso | Qué habilita |
| --- | --- |
| `view_e14` | `GET /e14/actas`, `GET /e14/actas/{id}`, `GET /e14/consolidado` |
| `manage_e14` | `POST /e14/actas`, `PUT /e14/actas/{id}` |

Los tiene `admin` y `coordinator`; `viewer` solo `view_e14`. Sin el permiso, 403.

---

## Endpoints

Todos bajo `/api/v1/e14`, con `throttle:120,1`.

### `POST /actas` — registrar un acta

**Idempotente por mesa.** La clave natural es
`(tenant, evento, tipo, zona, puesto, mesa)`: reenviar la misma mesa **actualiza**
la fila y devuelve `200`; la primera vez devuelve `201`. Reprocesar el lote
entero no duplica un solo voto.

```json
{
  "tipo": "alcaldia",
  "archivo_nombre": "zona_1_mesa001.pdf",
  "archivo_hash": "a1b2…(64 hex)",
  "fuente": "vision",
  "departamento_code": "73",
  "municipio_code": "73001",
  "zona": "01",
  "puesto": "01",
  "mesa": "001",
  "lugar": "INSTITUCION EDUCATIVA SAN JOSE",
  "estado": "procesada",
  "suma_calculada": 111,
  "suma_declarada": 111,
  "votos_urna": 111,
  "votantes_e11": 111,
  "dif_nivelacion": 0,
  "votos_blanco": 4,
  "votos_nulos": 4,
  "votos_no_marcados": 4,
  "observacion": "",
  "resultados": [
    { "numero": 1, "nombre": "JORGE BOLIVAR TORRES", "votos": 50 },
    { "numero": 2, "nombre": "JOHANA XIMENA ARANDA RIVERA", "votos": 30 },
    { "numero": 3, "nombre": "RENSO ALEXANDER GARCIA PARRA", "votos": 19 }
  ]
}
```

`estado`, `suma_calculada` y `dif_nivelacion` se **aceptan pero se recalculan**.
Se reciben para poder compararlos, que no es lo mismo que creerlos.

**Acta ilegible.** Si el lector no pudo transcribirla, la publica igual con
`estado: "revision_manual"`, sin cifras y con el motivo en `observacion`. Así la
mesa aparece en la cola de revisión del panel en vez de quedar como si nadie la
hubiera intentado. Es el único `estado` que el cliente puede fijar.

**El evento electoral se resuelve solo.** Si no se manda `electoral_event_id`, se
busca (o se crea) uno por `(tenant, tipo, nombre)`, con `nombre` = `evento_nombre`
o el tipo capitalizado. Exigir que alguien dé de alta la elección antes de poder
leer un acta sería un paso de configuración que la noche del escrutinio nadie va
a dar. Para separar dos elecciones del mismo tipo, se manda `evento_nombre`
(p. ej. `"Alcaldía 2027"`) o el `electoral_event_id` explícito.

**El catálogo de candidatos también.** Cada `numero` que aparece por primera vez
crea su fila en `e14_candidates`; las actas siguientes se enlazan a ella. En
`e14_resultados.nombre` queda lo que el lector transcribió, aunque el catálogo
diga otra cosa: si la visión leyó mal un nombre, hay rastro.

| Respuesta | Cuándo |
| --- | --- |
| `201` | mesa nueva |
| `200` | la mesa ya estaba: se actualizó |
| `401` | sin credencial válida (no escribe nada) |
| `403` | sin `manage_e14` |
| `422` | validación, o el mismo `archivo_hash` ya radicado **en otra mesa** |

Ese último 422 es deliberado: el mismo PDF archivado bajo dos mesas es un error
de radicación, y dejarlo pasar metería los mismos votos dos veces en el total.

### `GET /actas` — listado y cola de revisión

Filtros: `estado`, `tipo`, `zona`, `puesto`, `mesa`, `electoral_event_id`,
`per_page` (50 por defecto). Ordena por zona, puesto y mesa.

```
GET /api/v1/e14/actas?estado=revision_manual
GET /api/v1/e14/actas?estado=inconsistente&zona=01
```

### `GET /actas/{id}`

El acta con sus votos por candidato. Un acta de otro tenant responde **404**.

### `PUT /actas/{id}` — corrección manual

Acepta cualquier subconjunto de `suma_declarada`, `votos_urna`, `votantes_e11`,
`votos_blanco`, `votos_nulos`, `votos_no_marcados`, `departamento_code`,
`municipio_code`, `lugar`, `observacion` y `resultados`.

Al aplicarse deja `fuente: "manual"` y **vuelve a evaluar el cuadre**. Si ahora
cuadra, el acta entra al consolidado; si no, sigue fuera. Corregir no es aprobar:
no hay forma de marcar un acta como procesada sin que las cifras cuadren.

### `GET /consolidado`

Votos por candidato **solo de las actas `procesada`**. Filtros:
`electoral_event_id`, `tipo`.

```json
{
  "data": [
    { "numero": 1, "nombre": "JORGE BOLIVAR TORRES", "agrupacion": null, "votos": 304 }
  ],
  "meta": {
    "actas": { "procesada": 5, "inconsistente": 1, "revision_manual": 0 },
    "votos_blanco": 42,
    "votos_nulos": 22,
    "votos_no_marcados": 36,
    "total_candidatos": 889,
    "total_votos": 989
  },
  "desglose": {
    "por_puesto": [{ "puesto": "01", "candidatos": [], "total": 889 }],
    "por_zona":   [{ "zona": "01",   "candidatos": [], "total": 889 }]
  }
}
```

`meta.actas` está ahí a propósito: un consolidado sin decir cuántas actas quedaron
fuera invita a leerlo como definitivo cuando no lo es.

---

## Esquema

### `electoral_events`
`id`, `tenant_id`, `nombre`, `fecha`, `tipo`, timestamps.
Único: `(tenant_id, tipo, nombre)`.

### `e14_candidates`
`id`, `tenant_id`, `electoral_event_id`, `numero`, `nombre`, `agrupacion`,
`cargo`, timestamps.
Único: `(electoral_event_id, cargo, numero)`.

### `e14_actas`
`id`, `tenant_id`, `electoral_event_id`, `tipo`, `departamento_code`,
`municipio_code`, `zona`, `puesto`, `mesa`, `lugar`, `archivo_nombre`,
`archivo_hash`, `estado`, `suma_calculada`, `suma_declarada`, `votos_urna`,
`votantes_e11`, `dif_nivelacion`, `votos_blanco`, `votos_nulos`,
`votos_no_marcados`, `fuente`, `confianza`, `observacion`, `processed_at`,
timestamps.

Dos índices únicos:

- `e14_actas_mesa_unique` — `(tenant_id, electoral_event_id, tipo, zona, puesto, mesa)`:
  una mesa se escruta una vez.
- `e14_actas_hash_unique` — `(tenant_id, archivo_hash)`: el mismo PDF no puede
  quedar archivado bajo dos mesas.

`estado` ∈ `procesada | inconsistente | revision_manual`; `fuente` ∈ `vision | manual`.
`dif_nivelacion` es `integer` con signo a propósito: una urna con **más** votos
que votantes registrados es la anomalía que más importa poder ver, y truncarla a
cero la escondería.

### `e14_resultados`
`id`, `tenant_id`, `e14_acta_id`, `e14_candidate_id`, `numero`, `nombre`,
`votos`, timestamps.
Único: `(e14_acta_id, numero)`.

### `e14_service_tokens`
`id`, `tenant_id`, `user_id`, `nombre`, `token_hash` (único), `last_used_at`,
`revoked_at`, timestamps.

Todas las tablas llevan `HasTenant` y auditoría (`owen-it`); `e14_actas` además
registra en `activity_log` los cambios de `estado`, `suma_declarada`,
`votos_urna`, `fuente` y `observacion`, que son los que alguien va a querer
revisar después de una corrección manual.

### Por qué mesa y puesto van por código y no por FK

`voting_places` existe, pero es un catálogo **global sin `tenant_id`** que solo se
llena cuando el webhook de Registraduría reporta un puesto, con tres cadenas de
texto libre como clave natural y sin relación con `departments`/`municipalities`.
Y **mesa no existe como entidad**: es una columna de texto en `voters` y `leads`.
Colgar el escrutinio de ahí sería apoyarlo en datos que ningún proceso garantiza.

Se enlaza por códigos, que es la alternativa que la propia spec contempla. Cuando
exista un catálogo de mesas de verdad, añadir la FK es aditivo.

---

## Aislamiento

Todos los modelos usan `HasTenant`, así que `TenantScope` filtra cada consulta.
Un acta de otro tenant da 404 en `show` y `update`, no aparece en `index` y no
suma en el consolidado. Dos campañas pueden tener la misma zona/puesto/mesa sin
pisarse: el índice único incluye `tenant_id`.

Un `electoral_event_id` de otro tenant en el `POST` responde 422, no crea nada.
