# API de escrutinio E-14

Specs `0061` (tablas, ingesta directa, credencial de servicio) y `0071`
(carga desde el panel, cola y worker). Registra los resultados reales por
candidato y mesa, leídos de las actas E-14, y los consolida.

Hay **dos caminos** para que un acta entre, y conviene no confundirlos:

| Camino | Quién | Cómo |
| --- | --- | --- |
| **Ingesta directa** (0061) | el lector con la carpeta de PDFs en su máquina | lee primero y publica el resultado ya cuadrado con `POST /actas` |
| **Carga + cola** (0071) | el panel sube los PDFs, un worker los lee | `upload` → `procesar` → `siguiente` → `resultado` |

El segundo es el que se usa en producción; el primero sigue en pie para lotes
locales y desarrollo. Los dos terminan en la misma tabla y con la misma regla
de cuadre.

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

---

## Estados de un acta

```
cargada → pendiente → procesando → procesada | inconsistente | revision_manual
```

| Estado | Qué significa |
| --- | --- |
| `cargada` | vestigio: hasta la 0072 el PDF subido esperaba una orden manual |
| `pendiente` | en la cola, esperando a que un worker la reclame |
| `procesando` | reclamada por un worker; nadie más puede tomarla |
| `procesada` | leída y cuadra: entra al consolidado |
| `inconsistente` | leída y **no** cuadra: fuera del consolidado, con el motivo |
| `revision_manual` | ilegible, abandonada demasiadas veces, o mesa duplicada |

Los tres primeros los mueve **solo el servidor**. Un cliente que intente
declararse `procesando` recibe 422: si pudiera, sacaría un acta de la cola sin
haberla leído.

**Desde la 0072 subir un acta la deja en `pendiente`**, así que el camino real
empieza en el segundo estado. `cargada` sigue siendo válido —no hubo migración
que lo retirara— pero ya nadie lo escribe; solo lo llevan las actas que se
subieron antes del cambio, y `POST /actas/procesar` existe para rescatarlas.

La corrección manual (`PUT`) reevalúa el cuadre y nunca marca `procesada` un
acta que no cuadra.

## Tipos de elección

`alcaldia`, `gobernacion`, `concejo`, `senado`, `asamblea_departamental`.

Los dos primeros son **uninominales**: una página, es lo que el lector sabe leer
hoy. Los otros tres son **corporaciones con voto preferente**, multipágina por
lista, y necesitan el parser de la spec 0067. Hasta entonces se pueden cargar y
encolar sin problema; el worker pide solo los tipos que sabe leer (`tipos` en
`/siguiente`) y los demás se quedan en la cola sin estorbar.

En PostgreSQL ambos campos llevan CHECK; en SQLite —donde corren las pruebas— el
motor lo ignora, así que quien atrapa el valor inválido es la validación de
entrada.

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
| `view_e14` | `GET /actas`, `GET /actas/{id}`, `GET /consolidado`, `GET /resumen`, `GET /eventos`, `GET /cruce`, `GET /puestos-por-conciliar` |
| `manage_e14` | `POST /actas`, `POST /actas/upload`, `POST /actas/procesar`, `POST /actas/siguiente`, `POST /actas/{id}/resultado`, `PUT /actas/{id}`, `PUT /eventos/{id}/candidato-propio`, `POST /puestos/fusionar` |

Los tiene `admin` y `coordinator`; `viewer` solo `view_e14`. Sin el permiso, 403.

---

## Endpoints

Todos bajo `/api/v1/e14`, con `throttle:120,1`. La descarga del PDF es la única
excepción: cuelga de `/api/v1/e14/actas/{id}/archivo` y va por firma, no por
sesión.

---

## Configuración de campaña: el candidato propio (Spec 0062)

En clave SaaS cada tenant es la campaña de **un** candidato a **un** cargo, y ese
candidato es **una fila del E-14**. El consolidado no lo necesita —suma a todos—,
pero el cruce sí: «registrados vs votos» no se puede calcular sin saber de quién
son los votos.

Se configura **por elección**, no por tenant: una campaña puede tener cargada la
de alcaldía y la de concejo, o la de 2027 y la de 2031, y el número del tarjetón
es distinto en cada una.

### `GET /eventos` — las elecciones y su configuración

```json
{
  "data": [
    {
      "id": 3,
      "nombre": "Alcaldía",
      "tipo": "alcaldia",
      "fecha": "2027-10-31",
      "candidato_propio": { "numero": 4, "nombre": "MIGUEL ALCALDE", "agrupacion": "MOVIMIENTO X" },
      "tiene_actas": true,
      "actas": 812,
      "candidatos": [
        { "numero": 1, "nombre": "JORGE BOLIVAR TORRES", "agrupacion": null, "cargo": "alcaldia" }
      ]
    }
  ]
}
```

El tarjetón (`candidatos`) viaja **con** la elección y no en un endpoint aparte:
la pantalla de configuración lo necesita siempre, y pedirlo en dos viajes solo
abriría la ventana para mostrar un selector vacío mientras llega el segundo.

`tiene_actas` es lo que le dice al frontend si el número se escribe a mano o se
elige de una lista — la misma regla que valida el servidor.

### `PUT /eventos/{id}/candidato-propio`

```json
{ "numero": 4, "nombre": "MIGUEL ALCALDE", "agrupacion": "MOVIMIENTO X" }
```

| Campo | Regla |
| --- | --- |
| `numero` | **obligatorio en la petición**, admite `null` para deshacer; 1–999 |
| `nombre` | opcional; si falta se toma del catálogo |
| `agrupacion` | opcional; si falta se toma del catálogo |

**Los dos momentos.** Antes de la primera acta el número se acepta a ciegas: se
sabe semanas antes de que haya un acta que leer, y obligar a esperar dejaría la
campaña sin configurar justo cuando tiene tiempo de hacerlo. En cuanto existe el
catálogo `e14_candidates`, un número que no está en él responde 422 — apuntaría el
cruce a una fila que ninguna acta va a traer.

`numero: null` borra la ficha completa (nombre y agrupación incluidos) y deja el
cruce sin calcular: un nombre suelto de un candidato que ya no se cruza solo
confunde a quien lo lea después.

Cambiar el número **no recalcula nada guardado**: el cruce se calcula al
preguntarlo, así que la siguiente llamada a `/cruce` ya sale con el candidato
nuevo.

---

## Flujo web: carga y cola (Spec 0071)

Cuatro llamadas: se cargan los PDFs, se da la orden, el worker toma de a una y
devuelve lo que leyó.

### `POST /actas/upload` — subir un acta

`multipart/form-data`. Permiso `manage_e14`.

| Campo | |
| --- | --- |
| `tipo` | requerido, uno de los cinco |
| `archivo` | requerido, PDF (se valida el mimetype real, no la extensión) |
| `electoral_event_id` | opcional |
| `upload_batch_id` | opcional; si no viene, el servidor abre uno y lo devuelve |

**Subir es encolar (Spec 0072).** El acta nace en `pendiente` y el worker la
toma sola. Antes nacía `cargada` y hacía falta un segundo paso; quien subía un
lote y se iba de la pantalla lo dejaba ahí sin que nada avisara.

**Deduplica por contenido.** El PDF se guarda como `e14/{tenant}/{sha256}.pdf`,
así que subir el mismo escaneo con otro nombre —cosa que pasa todo el tiempo
cuando varias personas cargan el mismo lote— devuelve el acta que ya existe, con
`duplicada: true` y `200` en vez de `201`, y **sin tocarle el estado**: volver a
subir una que ya se leyó no la devuelve a la cola. La deduplicación es por
tenant.

```json
{
  "data": { "id": 12, "estado": "pendiente", "tiene_archivo": true, "…": "…" },
  "upload_batch_id": "0f8c…",
  "duplicada": false,
  "message": "Acta cargada y encolada."
}
```

El acta queda sin zona/puesto/mesa: eso está dentro del papel y lo dirá el
worker.

### `POST /actas/procesar` — reencolar (casos de borde)

`{ "tipo"?, "batch_id"?, "ids"? }` → pasa de `cargada` a `pendiente` lo que
encaje, y devuelve `{ "data": { "encoladas": N } }`. Permiso `manage_e14`.

**Ya no hace falta en el camino normal**: desde la 0072 subir encola. Queda para
las actas que se quedaron en `cargada` antes de ese cambio y para reencolar un
lote a mano. Llamarlo dos veces no reencola lo que ya está en marcha.

### `POST /actas/siguiente` — reclamar (worker)

`{ "tipos"?: ["alcaldia", "gobernacion"] }`. Permiso `manage_e14`.

Devuelve **una** acta `pendiente`, ya marcada `procesando` a nombre de quien
preguntó, junto con la URL firmada de su PDF:

```json
{
  "data": { "id": 12, "estado": "procesando", "intentos": 1, "…": "…" },
  "archivo_url": "https://…/api/v1/e14/actas/12/archivo?tenant=3&expires=…&signature=…"
}
```

**`204` cuando no hay nada.** Es la señal para dormir un rato en vez de girar en
vacío.

#### Cómo es atómico

La garantía no está en el bloqueo de fila sino en el `UPDATE`:

```sql
UPDATE e14_actas SET estado = 'procesando', claimed_at = now(), intentos = intentos + 1
 WHERE id = ? AND estado = 'pendiente'
```

Una sola sentencia, así que es el motor quien decide el ganador: dos workers con
la misma acta en la mano obtienen 1 y 0 filas afectadas, y el que pierde
reintenta con la siguiente. Esto vale igual en PostgreSQL que en SQLite. El
`SELECT … FOR UPDATE` que lo precede es la optimización que evita que dos
workers lleguen siquiera a competir por la misma fila.

#### Actas colgadas

Un worker que se cae deja el acta en `procesando` y sin dueño. Pasados
`E14_CLAIM_TIMEOUT_MINUTES` (15 por defecto) vuelve a la cola: releerla es
barato —el resultado es idempotente— y perderla en silencio no lo es. Con tope:
a los `E14_MAX_INTENTOS` reclamos (3) el acta va a `revision_manual`, para que un
PDF que tumba al worker no lo tumbe indefinidamente a costa del resto. El
reencolado se dispara solo, en cada llamada a `/siguiente`.

### `GET /actas/{id}/archivo` — el PDF

Ruta **pública por firma**, no por descuido. La URL llega ya firmada en
`/siguiente` y se usa segundos después.

- Dura `E14_URL_TTL_MINUTES` (15 por defecto), no los seis días del máximo de S3.
- La firma cubre el **id del acta y el del tenant**: cambiar cualquiera de los
  dos la invalida (403), así que no se puede editar para pedir otra ni
  transferir a otra campaña.
- Es una ruta firmada de Laravel y no un enlace prefirmado de S3, así que
  funciona igual con el disco local. (Para volúmenes grandes, redirigir a un
  prefirmado de S3 es un cambio aditivo aquí dentro.)

### `POST /actas/{id}/resultado` — publicar la lectura (worker)

Permiso `manage_e14`. Mismo cuerpo que `POST /actas` salvo que no lleva `tipo`
ni datos de archivo —el acta ya existe—, y que la ubicación de la mesa es lo que
el worker acaba de leer:

```json
{
  "estado": "procesada",
  "zona": "01", "puesto": "01", "mesa": "001",
  "departamento_code": "73", "departamento": "TOLIMA",
  "municipio_code": "73001", "municipio": "IBAGUE",
  "lugar": "INSTITUCION EDUCATIVA SAN JOSE",
  "fuente": "vision",
  "suma_declarada": 111, "votos_urna": 111, "votantes_e11": 111,
  "votos_blanco": 4, "votos_nulos": 4, "votos_no_marcados": 4,
  "resultados": [ { "numero": 1, "nombre": "JORGE BOLIVAR TORRES", "votos": 50 } ],

  "hubo_recuento": true,
  "constancias": "Se recontaron los votos por diferencia de un tarjetón.",
  "recuento_solicitado_por": "CARLOS PEREZ",
  "recuento_representacion": "PARTIDO VERDE"
}
```

Los cuatro últimos campos son las **constancias de los jurados** — ver abajo.

El servidor **rehace la cuenta** y fija el estado. Lo que el worker diga en
`estado` se ignora, con una excepción: `revision_manual` es su forma de decir
«no pude leerla», y entonces se acepta sin cifras, con el motivo en
`observacion`.

**Idempotente**: reenviar el mismo resultado deja lo mismo, así que un worker
que publica y se cae antes de leer la respuesta puede repetir sin miedo.

**Mesa duplicada.** Dos fotos distintas del mismo papel tienen hashes distintos,
así que la deduplicación de la carga no las ve; se descubre al leerlas. La
segunda responde `200` con `estado: revision_manual` y el motivo apuntando al
acta que ya tenía esa mesa — y **no** se le guarda la mesa leída, porque
escribirla chocaría con el índice único que es justo lo que está avisando. La
cola sigue y el consolidado no cuenta doble.

### Las constancias de los jurados (Spec 0073)

La segunda página del E-14 trae un bloque manuscrito —«CONSTANCIAS DE LOS
JURADOS DE VOTACIÓN»— donde se anota lo que pasó en la mesa, más «HUBO RECUENTO
DE VOTOS (SÍ/NO)» y quién lo pidió. Muy a menudo **ahí está la explicación del
acta que no cuadra**, así que se guarda tal cual y se muestra junto al acta.

| Campo | |
| --- | --- |
| `hubo_recuento` | `true` / `false` / `null` |
| `constancias` | el texto libre, transcrito sin interpretar (máx. 5000) |
| `recuento_solicitado_por` | quién pidió el recuento |
| `recuento_representacion` | en representación de quién |

Tres reglas:

- **No entran en el cuadre.** Un acta no cuadra menos porque alguien explique por
  qué no cuadra. El `estado` lo sigue decidiendo la aritmética, y nada más.
- **`hubo_recuento` es `null` cuando no se pudo leer**, y eso no es un «no».
  Tratar lo ilegible como negativo sería inventarse el dato más interesante del
  bloque.
- **Se guardan aunque el resto del acta no se haya podido leer.** Un acta que
  entra como `revision_manual` conserva sus constancias: es justo lo que quien
  revise va a querer tener delante.

Se aceptan en `POST /actas/{id}/resultado`, en `PUT /actas/{id}` —quien mira el
papel suele descifrar la letra mejor que la visión— y también en la ingesta
directa `POST /actas`, para que el lector de carpeta local no las pierda por
entrar por otra puerta.

`E14ActaResource` los expone tal cual, más un `tiene_constancias` (booleano) que
es lo que el panel usa para marcar dónde hay algo que leer.

### `GET /resumen` — avance para el panel

Permiso `view_e14`. Filtros `batch_id`, `tipo`.

```json
{
  "data": {
    "por_estado": { "cargada": 0, "pendiente": 4, "procesando": 1, "procesada": 115, "inconsistente": 2, "revision_manual": 1 },
    "por_tipo":  { "alcaldia": 123 },
    "por_lote":  { "0f8c…": 123 },
    "total": 123,
    "en_cola": 5
  }
}
```

`en_cola` (pendientes + procesando) es el número que mira quien está esperando a
que termine.

---

## Ingesta directa (Spec 0061)

### `POST /actas` — registrar un acta ya leída

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

---

## Consulta y corrección (comunes a los dos caminos)

### `GET /actas` — listado y cola de revisión

Permiso `view_e14`. Filtros: `estado`, `tipo`, `zona`, `puesto`, `mesa`,
`electoral_event_id`, `per_page` (50 por defecto). Ordena por zona, puesto y mesa.

Filtros de ubicación (Spec 0074). Hay dos formas de preguntar por lo mismo
porque son dos usos distintos:

| Filtro | Cómo compara |
|---|---|
| `departamento_code`, `municipio_code` | exacto, sobre el código; sirve para agrupar |
| `departamento`, `municipio` | coincidencia parcial sobre el **nombre** o exacta sobre el código |
| `lugar` | coincidencia parcial |

Las parciales son insensibles a mayúsculas en los dos drivers (`ILIKE` en
PostgreSQL, `like` en SQLite). `departamento`/`municipio` son la casilla de
búsqueda del panel: quien escribe pone lo que recuerda —el nombre o el código—
y espera que `tolima` encuentre `TOLIMA`.

```
GET /api/v1/e14/actas?estado=revision_manual
GET /api/v1/e14/actas?estado=inconsistente&zona=01
GET /api/v1/e14/actas?departamento_code=73&municipio_code=73001
GET /api/v1/e14/actas?departamento=tolima&lugar=cooperativa
```

### `GET /actas/{id}`

El acta con sus votos por candidato, y la **URL firmada de su PDF** si lo tiene:

```json
{ "data": { "id": 12, "…": "…" }, "archivo_url": "https://…/archivo?…" }
```

Quien revisa un acta a mano necesita mirar el papel. La URL se emite en la
respuesta y no se guarda —una URL firmada guardada es una URL que caduca en la
base de datos— y es la misma ruta corta y atada al tenant que usa el worker.
`archivo_url` es `null` cuando el acta entró por la ingesta directa y no tiene
archivo.

Un acta de otro tenant responde **404**.

### `PUT /actas/{id}` — corrección manual

Acepta cualquier subconjunto de `suma_declarada`, `votos_urna`, `votantes_e11`,
`votos_blanco`, `votos_nulos`, `votos_no_marcados`, `departamento_code`,
`departamento`, `municipio_code`, `municipio`, `lugar`, `observacion` y
`resultados`.

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
    "por_zona":   [{ "zona": "01",   "candidatos": [], "total": 889 }],
    "por_lugar":  [{ "lugar": "INSTITUCION EDUCATIVA SAN JOSE", "candidatos": [], "total": 889 }]
  }
}
```

`por_lugar` (Spec 0074) es el mismo corte que `por_puesto` pero legible: el
nombre impreso del puesto en vez de su código. Las actas sin ese dato no hacen
grupo — un renglón sin nombre con votos dentro se lee como si fuera un puesto de
votación más.

`meta.actas` está ahí a propósito: un consolidado sin decir cuántas actas quedaron
fuera invita a leerlo como definitivo cuando no lo es.

---

## Cruce potencial vs real (Spec 0062)

La pregunta que paga la analítica electoral: *tengo 340 registrados en este puesto
y mi candidato sacó 120 votos — ¿dónde se fue el resto?*

Tres decisiones gobiernan el cálculo:

1. **Potencial = registrados**, no comprometidos ni encuestados: es el único
   número que existe para todas las mesas.
2. **Real = la fila del E-14 de mi candidato**, y solo de actas `procesada`. Una
   mesa que no cuadra consigo misma no sirve para juzgar el rendimiento de nadie.
3. **El match es exacto** por `voting_place_id` + mesa normalizada. Lo que no
   resuelve no se aproxima: se cuenta en la cobertura y se manda a conciliar.

Nada se guarda: cambiar el candidato propio o fusionar dos puestos se ve en la
siguiente llamada.

### `GET /cruce`

| Parámetro | Por defecto | Qué hace |
| --- | --- | --- |
| `event` | la elección más reciente del tenant | de qué elección se cruza |
| `nivel` | `puesto` | `puesto` \| `mesa` |
| `municipio` | — | coincidencia parcial sobre el municipio del puesto |
| `voting_place` | — | el **id** si se eligió de la lista, o texto parcial del nombre |
| `incluir` | `voters` | `voters` \| `leads` \| `ambos` — qué cuenta como registrado |

```json
{
  "data": [
    {
      "voting_place_id": 12,
      "departamento": "TOLIMA",
      "municipio": "IBAGUE",
      "puesto": "COLEGIO SAN SIMON",
      "registrados": 50,
      "votos_candidato": 30,
      "penetracion": 60,
      "diferencia": -20,
      "anomalia": false,
      "tiene_acta": true,
      "actas": 1
    }
  ],
  "meta": {
    "electoral_event_id": 3,
    "nivel": "puesto",
    "incluir": "voters",
    "candidato": { "numero": 2, "nombre": "JOHANA ARANDA", "agrupacion": null },
    "totales": {
      "puestos": 1, "registrados": 50, "votos_candidato": 30,
      "penetracion": 60, "diferencia": -20, "anomalias": 0
    },
    "cobertura": {
      "puestos_sin_acta": 0,
      "puestos_sin_registrados": 0,
      "actas_sin_conciliar": 0,
      "registrados_sin_conciliar": 0,
      "nombres_sin_conciliar": 0
    }
  }
}
```

`mesa` aparece en cada fila **solo** con `nivel=mesa`: una columna que siempre
viene en null invita a mostrarla vacía.

`penetracion` es un **porcentaje** con dos decimales, y es `null` —no `0`— cuando
no hay registrados: decir «no penetro nada» donde lo que pasa es que no se sabe
sería otra cosa. Ojo con el JSON: un porcentaje redondo llega como entero (`60`,
no `60.0`).

`diferencia` = votos − registrados, con signo. `anomalia` marca `votos >
registrados`, que es **imposible legítimamente** —nadie vota donde no está
registrado— y por tanto un dato a revisar, no un rendimiento a celebrar.

`tiene_acta` se cuenta aparte de los votos porque son dos preguntas distintas: un
puesto donde mi candidato sacó cero votos **tiene** acta, y confundirlo con uno sin
escrutar sería leer un cero real como un dato que falta.

Sin candidato propio configurado responde **422** pidiendo configurarlo, no un
cruce en ceros: un tablero lleno de ceros se lee como «perdí», no como «falta un
dato».

### Cómo se cuenta cada lado

**Registrados** — `voters` del tenant agrupados por `voting_place_id`. Los que lo
tienen nulo (captura vieja, o el webhook de Registraduría) se mapean **por
nombre**; los que ni así resuelven van a `registrados_sin_conciliar`. `leads` no
tiene esa columna, así que va siempre por nombre.

**Votos** — `e14_resultados` con `numero = candidato_propio_numero`, solo de actas
`procesada`, agrupados por el `voting_place_id` del acta.

Ambos lados normalizan la mesa a entero, así que el `005` del acta casa con el `5`
del votante.

### La cobertura es la mitad del informe

Un 80 % de penetración sobre la cuarta parte de los puestos no dice lo mismo que
sobre todos, y sin este bloque las dos cosas se leen igual.

| Campo | Qué cuenta |
| --- | --- |
| `puestos_sin_acta` | filas con registrados pero sin acta que cuadre |
| `puestos_sin_registrados` | filas con acta pero sin nadie registrado (ahí votó gente que la campaña no tiene) |
| `actas_sin_conciliar` | actas `procesada` sin puesto canónico: sin `lugar` legible, o sin departamento con el que darlo de alta. **Sus votos no entran en ninguna fila** |
| `registrados_sin_conciliar` | votantes/leads cuyo nombre de puesto no resuelve |
| `nombres_sin_conciliar` | cuántas variantes distintas de nombre están pendientes |

Los tres primeros siguen a los filtros; los dos de «sin conciliar» son globales de
la elección, porque un registro sin puesto no se puede atribuir a ningún
municipio.

---

## Esquema

### `electoral_events`
`id`, `tenant_id`, `nombre`, `fecha`, `tipo`, `candidato_propio_numero`,
`candidato_propio_nombre`, `candidato_propio_agrupacion`, timestamps.
Único: `(tenant_id, tipo, nombre)`.

Las tres columnas de candidato propio son de la 0062 y son nullable: una elección
recién creada por la ingesta todavía no sabe de quién es la campaña.

### `e14_candidates`
`id`, `tenant_id`, `electoral_event_id`, `numero`, `nombre`, `agrupacion`,
`cargo`, timestamps.
Único: `(electoral_event_id, cargo, numero)`.

### `e14_actas`
`id`, `tenant_id`, `electoral_event_id`, `tipo`, `departamento_code`,
`departamento`, `municipio_code`, `municipio`, `zona`, `puesto`, `mesa`,
`lugar`, `voting_place_id`, `archivo_nombre`,
`archivo_hash`, `upload_batch_id`, `archivo_path`, `estado`, `suma_calculada`,
`suma_declarada`, `votos_urna`, `votantes_e11`, `dif_nivelacion`,
`votos_blanco`, `votos_nulos`, `votos_no_marcados`, `fuente`, `confianza`,
`observacion`, `hubo_recuento`, `constancias`, `recuento_solicitado_por`,
`recuento_representacion`, `processed_at`, `claimed_at`, `intentos`, timestamps.

**`zona`, `puesto` y `mesa` son opcionales** desde la 0071: un acta recién
subida todavía no sabe de qué mesa es. El índice único por mesa sigue en pie
—varios nulos no colisionan entre sí ni en PostgreSQL ni en SQLite—, así que las
actas sin leer conviven y la unicidad empieza a aplicar en cuanto el worker dice
de qué mesa era cada una.

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

### Configuración (`config/e14.php`)

| Variable | Por defecto | Para qué |
| --- | --- | --- |
| `E14_DISK` | el disco por defecto de la app | dónde viven los PDFs (Wasabi/S3 en producción) |
| `E14_MAX_UPLOAD_KB` | `20480` | tamaño máximo de un acta |
| `E14_URL_TTL_MINUTES` | `15` | vigencia de la URL firmada del PDF |
| `E14_CLAIM_TIMEOUT_MINUTES` | `15` | cuánto puede estar un acta en `procesando` antes de volver a la cola |
| `E14_MAX_INTENTOS` | `3` | reclamos por acta antes de mandarla a revisión |

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

Los **nombres** (`departamento`, `municipio`, `lugar`) se guardan como texto por
la misma razón, y además porque son lo que dice **el papel**: el papel manda
aunque venga con una tilde de más o con un nombre viejo. Sin ellos el panel
mostraba «73 / 73001» y había que saberse el DIVIPOLA de memoria (Spec 0074).

### …y por qué la 0062 sí añadió `voting_place_id`

Lo anterior sigue siendo cierto, pero el cruce con los registrados cambió el
cálculo: `voting_places` pasó de ser «un catálogo que nadie garantiza» a ser **lo
único común entre los dos lados**.

- el votante se registra con municipio (nombre) + puesto (nombre) + mesa sin
  ceros, y ya tenía `voting_place_id`;
- el acta trae puesto por código + `lugar` (nombre) + mesa con ceros.

No hay código de puesto compartido, así que cruzar por códigos es imposible, y
cruzar por nombres sueltos daría un falso negativo con cada abreviatura. La
solución es que **ambos lados resuelvan al mismo renglón del catálogo**:
`voting_place_id` + mesa normalizada, exacto.

`voting_place_id` es **nullable** y se queda nulo cuando no hay con qué
resolverlo. Esas actas salen en la cobertura del cruce; nunca se les inventa un
puesto.

---

## Aislamiento

Todos los modelos usan `HasTenant`, así que `TenantScope` filtra cada consulta.
Un acta de otro tenant da 404 en `show` y `update`, no aparece en `index` y no
suma en el consolidado. Dos campañas pueden tener la misma zona/puesto/mesa sin
pisarse: el índice único incluye `tenant_id`.

Un `electoral_event_id` de otro tenant en el `POST` responde 422, no crea nada.
