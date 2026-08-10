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

### El acta sin datos no cuadra (Spec 0077)

La igualdad de arriba tiene un cero a la izquierda: **`0 = 0 = 0` la cumple**. Un
acta que el lector no consiguió transcribir —ninguna casilla, la urna vacía—
salía por eso `procesada`, entraba al consolidado aportando nada y desaparecía de
la cola de revisión. Peor que un error de suma, porque no se ve.

Por eso `CuadreService::evaluar()` tiene un guardia **antes** de las
comparaciones:

```
suma_calculada == 0  &&  suma_declarada == 0  &&  votos_urna == 0
        →  estado = revision_manual
        →  observacion = «el acta no tiene datos: ninguna casilla ni la urna registran votos»
```

No es una regla de cuadre más, es la pregunta previa: **si no hay nada que
contar, no hay nada que cuadrar**. Tres consecuencias:

- `votantes_e11` **no rescata** el cuadre. Que el E-11 diga que se registraron
  250 personas no convierte en escrutinio un acta sin un solo voto. La
  nivelación se anota igual, con la fórmula de siempre.
- Aplica a los **tres** caminos que escriben `estado`: el resultado del worker
  (`POST /actas/{id}/resultado`), la ingesta directa (`POST /actas`) y la
  corrección manual (`PUT /actas/{id}`). Vaciar un acta a mano no la aprueba.
- Con **un solo voto** en cualquier casilla el guardia deja de aplicar y vuelven
  las reglas de coincidencia normales: una mesa con un voto es una mesa, no un
  acta en blanco.

El acta cae así en `revision_manual`, que es donde alguien puede releerla,
corregirla o quitarla — ver [Volver a leer o quitar un acta](#volver-a-leer-o-quitar-un-acta-spec-0077).

Excepción de redacción: si el propio cliente declara el acta `revision_manual`
—«no pude transcribirla»— manda **su** explicación sobre la genérica de arriba.
Los dos la mandan a revisión, así que no hay discrepancia que resolver, y «la
casilla del candidato 3 está tachada» dice dónde mirar.

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

### Volver a leer o quitar un acta (Spec 0077)

Los tres estados terminales son un callejón sin salida si el lector no transcribió
nada: no hay una casilla que corregir, está todo vacío. Dos acciones lo abren, y
son la respuesta a dos problemas distintos — *«que la lea otra vez»* y *«este
escaneo no sirve, voy a subir uno mejor»*.

#### `POST /actas/{id}/reprocesar` · `manage_e14`

Devuelve el acta a la cola y **descarta la lectura anterior**. 200 con el acta ya
`pendiente`.

| Se limpia | Se conserva |
| --- | --- |
| `e14_resultados` del acta (borrado explícito) | `archivo_path`, `archivo_hash`, `archivo_nombre` |
| `suma_calculada`, `suma_declarada`, `votos_urna`, `votantes_e11`, `dif_nivelacion` → `0` | `tipo`, `electoral_event_id` |
| `votos_blanco`, `votos_nulos`, `votos_no_marcados` → `0` | ubicación (`departamento*`, `municipio*`, `lugar`, `voting_place_id`, `zona`, `puesto`, `mesa`) |
| `confianza`, `observacion`, `claimed_at`, `processed_at` → `null` | constancias de los jurados (0073) |
| `intentos` → `0`; `fuente` → `vision` | |

Los `intentos` vuelven a cero a propósito: un acta que ya falló dos veces
agotaría el tope (`e14.max_intentos`) en la primera relectura y volvería a
revisión sola, sin haber tenido su oportunidad. El archivo se conserva porque es
lo que se va a releer.

**Rechaza con 409** cuando el acta está en vuelo:

| Estado | Respuesta |
| --- | --- |
| `cargada`, `procesada`, `inconsistente`, `revision_manual` | 200, queda `pendiente` |
| `procesando` | 409 — «Un worker está leyendo esta acta ahora mismo» |
| `pendiente` | 409 — «Esta acta ya está en la cola» |

Con `procesando` es evidente: reencolarla mientras un worker trabaja son dos
lecturas escribiendo sobre la misma fila. Con `pendiente` no hay peligro, pero
tampoco hay nada que hacer, y decir que sí sería fingir un efecto. De paso, ese
rechazo es lo que hace que **pulsar dos veces no encole dos veces**.

Reprocesar un acta `procesada` está permitido y **la saca del consolidado** hasta
que se relea: el panel tiene que advertirlo en la confirmación.

#### `DELETE /actas/{id}` · `manage_e14`

Borra el acta, sus resultados y su archivo. 200 `{ message }`; después,
`GET /actas/{id}` responde 404.

Es **hard delete** —`E14Acta` no usa `SoftDeletes`— y aquí eso es el objetivo, no
un detalle: la fila tiene que desaparecer para que se libere el índice único
`tenant_id + archivo_hash`. Mientras existe, el dedup por contenido de la 0072
devuelve el acta que ya está en vez de crear una nueva, así que **volver a cargar
el mismo PDF** solo funciona si antes se eliminó. Ese es todo el propósito de la
acción.

Detalles que importan:

- **Permitido en cualquier estado**, `procesando` incluido: quitar un acta no
  puede depender de que un worker termine. Si su resultado llega después, el acta
  ya no existe y recibe 404, que es el contrato que el worker ya tolera.
- El borrado del **archivo es best-effort** y va después del commit: borrar del
  disco no se deshace con un rollback, y un objeto que ya no está no puede dejar
  la fila colgada para siempre.
- Los resultados se borran **explícitamente**, no por cascada de la FK: que se
  vayan con el acta no puede depender de si el motor tiene las claves foráneas
  activadas.
- **Auditado** (`event = deleted`, consultable con `view_audits`): borrar el
  escrutinio de una mesa es justo lo que después alguien va a pedir cuentas.
  Reprocesar queda como `updated`.

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
| `view_e14` | `GET /actas`, `GET /actas/{id}`, `GET /consolidado`, `GET /resumen`, `GET /eventos`, `GET /cruce`, `GET /rendimiento-lideres`, `GET /puestos-por-conciliar` |
| `manage_e14` | `POST /actas`, `POST /actas/upload`, `POST /actas/procesar`, `POST /actas/siguiente`, `POST /actas/{id}/resultado`, `POST /actas/{id}/reprocesar`, `PUT /actas/{id}`, `DELETE /actas/{id}`, `PUT /eventos/{id}/candidato-propio`, `POST /puestos/fusionar` |

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
pero el cruce sí: «base identificada vs votos» no se puede calcular sin saber de quién
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
no hay forma de marcar un acta como procesada sin que las cifras cuadren — ni
vaciándola, porque un acta sin datos tampoco cuadra.

Cuando no hay nada que corregir —el lector no transcribió el acta— las salidas
son `POST /actas/{id}/reprocesar` y `DELETE /actas/{id}`, descritas en
[Volver a leer o quitar un acta](#volver-a-leer-o-quitar-un-acta-spec-0077).

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

## Cruce: déficit sobre la base identificada (Specs 0062 y 0076)

La pregunta que paga la analítica electoral: *tengo 340 personas identificadas en
este puesto y mi candidato sacó 120 votos — ¿qué pasó con las otras?*

### Base ≠ censo: qué corrigió la 0076

La 0062 llamó «registrados» a los `voters` del tenant y les aplicó una lógica que
solo tiene sentido contra el **censo electoral** del puesto: marcaba anomalía
cuando `votos > registrados`, con un «nadie puede votar donde no está registrado»
que salta casi siempre y que es **falso**.

Los `voters` no son el censo: son la **base identificada** de la campaña —quien
llegó por reuniones, call center, líderes— y por diseño un **subconjunto** del
electorado. El candidato recibe votos de mucha gente que no está en el sistema, así
que `votos > base` es lo esperable y no informa de nada: pudo ser toda la base más
gente de fuera, o parte de la base que se fue y otros que la compensaron.

La señal que **sí** tiene información cierta es la contraria, el **déficit**: donde
`votos < base`, al menos `base − votos` personas de la base identificada no se
reflejaron en votos ahí. Ese es el piso de fuga y el sitio a donde ir a preguntar
—no fueron a votar, votaron por otro, faltó movilización—. El caso inverso se
reporta como **excedente**: dato neutro, sin alarma.

Por eso la 0076 quitó `anomalia`/`anomalias` del contrato y renombró
`registrados` → `base` y `penetracion` → `rendimiento`.

### Las tres decisiones del cálculo

1. **Base = los `voters`** (opcionalmente `leads`) del tenant, no comprometidos ni
   encuestados: es el único número que existe para todas las mesas.
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
| `incluir` | `voters` | `voters` \| `leads` \| `ambos` — qué cuenta como base |

```json
{
  "data": [
    {
      "voting_place_id": 12,
      "departamento": "TOLIMA",
      "municipio": "IBAGUE",
      "puesto": "COLEGIO SAN SIMON",
      "base": 50,
      "votos_candidato": 30,
      "rendimiento": 60,
      "diferencia": -20,
      "deficit": 20,
      "excedente": 0,
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
      "puestos": 1, "base": 50, "votos_candidato": 30,
      "rendimiento": 60, "diferencia": -20,
      "deficit_total": 20, "excedente_total": 0, "puestos_con_deficit": 1
    },
    "cobertura": {
      "puestos_sin_acta": 0,
      "puestos_sin_base": 0,
      "actas_sin_conciliar": 0,
      "base_sin_conciliar": 0,
      "nombres_sin_conciliar": 0
    }
  }
}
```

`mesa` aparece en cada fila **solo** con `nivel=mesa`: una columna que siempre
viene en null invita a mostrarla vacía.

`rendimiento` es un **porcentaje** con dos decimales, y es `null` —no `0`— cuando
no hay base: decir «no rindo nada» donde lo que pasa es que no se sabe sería otra
cosa. Ojo con el JSON: un porcentaje redondo llega como entero (`60`, no `60.0`).
El **100 % no es un techo**: significa «igualaste tu base identificada»; por debajo
hay déficit, por encima excedente.

### Déficit y excedente: las dos mitades de la diferencia

`diferencia` = votos − base, con signo, y se separa en sus dos mitades porque **no
valen lo mismo**:

| Campo | Cálculo | Qué significa |
| --- | --- | --- |
| `deficit` | `max(0, base − votos)` | gente de tu base que **no se reflejó en votos** ahí. Dato cierto y accionable: no fue a votar, votó por otro, o faltó movilizarla |
| `excedente` | `max(0, votos − base)` | votos de fuera de tu base. Neutro: activaste por encima de lo que tenías identificado |

Siempre `deficit − excedente = −diferencia`, y uno de los dos es cero.

En `meta.totales`, `deficit_total` y `excedente_total` suman **fila por fila**, así
que no se cancelan entre sí; `puestos_con_deficit` cuenta las filas con
`deficit > 0`. Ese es el número de cabecera, **no** `diferencia`: la diferencia
global se compensa sola —un puesto que rindió de sobra tapa a otro que se quedó
corto— y puede salir positiva con muchos déficits locales dentro.

Con `nivel=mesa` el cálculo es por mesa, así que el excedente de una mesa **no**
cancela el déficit de otra del mismo puesto.

Casos borde: con `base = 0` el `deficit` es `0` y el `excedente` son todos los
votos (no se le puede reclamar nada a una base que no existe); con `base = 0` y
`votos = 0` los tres van a cero y `rendimiento` a `null`.

> **El campo `anomalia` de la 0062 ya no existe**, ni su total `anomalias`. Sacar
> más votos que la base no es un error (ver «Base ≠ censo» arriba). Un chequeo real
> de integridad —votos por encima del **censo** del puesto— necesitaría cargar el
> potencial electoral por puesto, que hoy no existe; queda para una spec futura.

`tiene_acta` se cuenta aparte de los votos porque son dos preguntas distintas: un
puesto donde mi candidato sacó cero votos **tiene** acta, y confundirlo con uno sin
escrutar sería leer un cero real como un dato que falta.

Sin candidato propio configurado responde **422** pidiendo configurarlo, no un
cruce en ceros: un tablero lleno de ceros se lee como «perdí», no como «falta un
dato».

### Cómo se cuenta cada lado

**Base** — `voters` del tenant agrupados por `voting_place_id`. Los que lo tienen
nulo se mapean **por nombre**; los que ni así resuelven van a
`base_sin_conciliar`. `leads` no tiene esa columna, así que va siempre por
nombre.

> **Desde la Spec 0075** las tres escrituras del votante —alta manual, edición y
> webhook de Registraduría— resuelven `voting_place_id` con **este mismo**
> `PuestoResolver`, así que el camino normal es el match exacto por id y el mapeo
> por nombre queda como red de seguridad para datos viejos y para `leads`. Antes el
> webhook casaba por igualdad exacta de cadenas con su propio `firstOrCreate`: dos
> grafías del mismo colegio dejaban dos renglones del catálogo, el acta apuntaba a
> uno y el votante al otro, y la fila del cruce **no casaba en silencio** —peor que
> un nulo, porque el respaldo por nombre solo rescata los nulos. Ver
> `docs/VOTER_SYNC_SYSTEM.md` y el comando `voters:reapuntar-voting-place`.

**Votos** — `e14_resultados` con `numero = candidato_propio_numero`, solo de actas
`procesada`, agrupados por el `voting_place_id` del acta.

Ambos lados normalizan la mesa a entero, así que el `005` del acta casa con el `5`
del votante.

### La cobertura es la mitad del informe

Un 80 % de rendimiento sobre la cuarta parte de los puestos no dice lo mismo que
sobre todos, y sin este bloque las dos cosas se leen igual.

| Campo | Qué cuenta |
| --- | --- |
| `puestos_sin_acta` | filas con base pero sin acta que cuadre |
| `puestos_sin_base` | filas con acta pero sin nadie de la base identificada (ahí votó gente que la campaña no tiene) |
| `actas_sin_conciliar` | actas `procesada` sin puesto canónico: sin `lugar` legible, o sin departamento con el que darlo de alta. **Sus votos no entran en ninguna fila** |
| `base_sin_conciliar` | votantes/leads cuyo nombre de puesto no resuelve |
| `nombres_sin_conciliar` | cuántas variantes distintas de nombre están pendientes |

Los tres primeros siguen a los filtros; los dos de «sin conciliar» son globales de
la elección, porque un registro sin puesto no se puede atribuir a ningún
municipio.

---

## Rendimiento operativo del líder (Spec 0063)

### Por qué aquí no hay «votos del líder»

El roadmap pedía «rendimiento por líder (líder → leads → mesa → votos)». Ese
número **no se puede calcular**, y publicarlo de todas formas sería inventar el
dato más importante del informe:

- **Un líder no posee votantes ni leads.** Los `voters` son de la campaña —del
  admin inicial del tenant—; los `leads` son un diccionario de cédulas para
  autocompletar en reuniones. Ninguno cuelga de un líder.
- **Lo que un líder sí produce son reuniones** (`meetings.planner_user_id`) y, a
  través de ellas, asistentes que hacen check-in.
- **El voto es secreto y las mesas se comparten.** En una misma mesa mueven gente
  varios líderes y el acta no dice de quién es cada voto. Repartirlos por
  presencia sería una regla de tres disfrazada de dato.

Por eso el endpoint entrega **dos mitades que no se mezclan**: la actividad, que
es cierta, y el rendimiento de sus mesas, que es un **proxy declarado**. El
contrato no expone —ni va a exponer— ningún `votos_lider`.

### `GET /rendimiento-lideres`

| Parámetro | Por defecto | Qué hace |
| --- | --- | --- |
| `event` | la elección más reciente del tenant | contra qué escrutinio se contrasta la actividad |
| `order` | `deficit` | `deficit` \| `actividad` — qué va arriba en el ranking |

No hay `nivel`: el proxy solo tiene sentido **por mesa** (`meta.nivel_fijo`). A
nivel de puesto la gente de un líder se diluye entre todas las mesas del colegio y
el número deja de decir nada de él.

El conjunto de líderes son los `users` del tenant con `is_team_leader = true`. Uno
sin reuniones **no se oculta**: sale con la actividad en cero.

Sin candidato propio configurado responde **422** con el mismo mensaje que el
cruce: es la misma configuración la que falta.

```json
{
  "data": [
    {
      "lider": { "id": 7, "nombre": "ANA LIDER" },
      "reuniones": 3,
      "asistentes": 45,
      "checkins": 40,
      "movilizados_identificados": 30,
      "identificacion": 75,
      "puestos_cubiertos": 2,
      "mesas_cubiertas": 4,
      "mesas_sin_acta": 1,
      "mesas_en_deficit": 2,
      "mesas_en_superavit": 1,
      "rendimiento_ponderado": 62.5,
      "deficit_ponderado": 37.5,
      "posible_inflado": true
    }
  ],
  "meta": {
    "electoral_event_id": 3,
    "nivel_fijo": "mesa",
    "orden": "deficit",
    "candidato": { "numero": 2, "nombre": "JOHANA ARANDA", "agrupacion": null },
    "totales": {
      "lideres": 1, "reuniones": 3, "asistentes": 45, "checkins": 40,
      "movilizados_identificados": 30, "movilizados_por_lider": 30
    },
    "umbrales": { "movilizados_identificados": 20, "deficit_ponderado": 30 },
    "cobertura": {
      "checkins_sin_identificar": 10,
      "lideres_sin_proxy": 0,
      "mesas_con_gente": 4,
      "mesas_sin_base": 0
    },
    "aviso_proxy": "El voto es secreto y las mesas se comparten entre líderes: …",
    "aviso_inflado": "«Posible inflado» es una señal a revisar, no una acusación: …"
  }
}
```

### La mitad cierta: qué produjo el líder

| Campo | Definición |
| --- | --- |
| `reuniones` | `meetings` con `planner_user_id` = el líder. Mide al **planificador**, no al subárbol de `reports_to` |
| `asistentes` | filas de `meeting_attendees` de esas reuniones |
| `checkins` | las que tienen `checked_in = true`. Son **eventos**, no personas: quien asiste a dos reuniones suyas cuenta dos veces aquí |
| `movilizados_identificados` | **personas únicas** entre esos check-ins que resuelven a un `voter` del tenant con puesto canónico **y** mesa |
| `identificacion` | `movilizados_identificados / checkins · 100`. `null` si no hubo check-ins |
| `puestos_cubiertos` / `mesas_cubiertas` | puestos y mesas distintos donde vota su gente identificada |

**Cómo se identifica a una persona.** Primero por `meeting_attendees.voter_id`
(Spec 0022); si no lo tiene —asistencia anterior a esa spec—, por **cédula**
contra `voters`. Del votante hacen falta las dos cosas:

- el **puesto**: `voters.voting_place_id`, y si viene nulo —captura vieja— el
  resolver de respaldo por nombre de la 0062, que solo busca y nunca da de alta;
- la **mesa**: `mesa_votacion` normalizada (`005` = `5`).

Si falta cualquiera de las dos, la persona **no** es identificada. Es honesto: el
proxy se lee por mesa, y una mesa que no se conoce no se inventa.

**Lo que no resuelve se reporta, no se descarta.** Ese check-in sigue contando en
`checkins` —el líder movilizó a alguien— y engorda
`cobertura.checkins_sin_identificar`. Lo único que no sabemos es dónde vota. Bajar
el denominador para que el porcentaje se vea mejor sería mentir sobre la calidad
del dato, que es justo lo que `identificacion` mide.

`totales.movilizados_identificados` son **personas distintas de toda la campaña**;
`totales.movilizados_por_lider` es la suma de la columna. El segundo puede ser
mayor: quien asiste a reuniones de dos líderes cuenta para los dos, y ninguno de
los dos miente.

### La mitad que es un proxy: cómo rindieron sus mesas

Para cada mesa donde vota su gente identificada se toma el rendimiento de la 0076
—`votos_candidato / base · 100`, con la base de **toda** la campaña en esa mesa— y
se promedia **ponderando por su presencia**: cuántos de sus movilizados votan ahí.

```
rendimiento_ponderado = Σ (rendimiento_mesa × gente_del_líder_en_la_mesa)
                        ─────────────────────────────────────────────────
                              Σ gente_del_líder_en_la_mesa
```

Ponderar y no promediar a secas es lo que hace comparable el número: una mesa donde
puso 40 personas dice mucho más de su trabajo que una donde puso una.

| Campo | Qué significa |
| --- | --- |
| `mesas_en_deficit` / `mesas_en_superavit` | de sus mesas **con acta**, cuántas rindieron por debajo / por encima de la base |
| `rendimiento_ponderado` | el promedio de arriba. `null` si ninguna de sus mesas tiene acta y base |
| `deficit_ponderado` | `max(0, 100 − rendimiento_ponderado)`: los puntos de su base que no se reflejaron en votos. La misma cifra vuelta del derecho, para ordenar por lo accionable |
| `mesas_sin_acta` | mesas suyas todavía sin escrutinio: no entran en el proxy |

**Una mesa sin acta procesada no entra.** Sin escrutinio no hay con qué comparar, y
un 0 % diría que rindió mal cuando lo que pasa es que aún no se sabe. Por eso una
elección sin actas muestra la actividad completa y el proxy vacío
(`rendimiento_ponderado = null`, `cobertura.lideres_sin_proxy` con el conteo).

**El número es compartido.** En esas mesas también mueven gente otros líderes, y el
déficit de una mesa no es «lo que este líder perdió»: es lo que perdió la campaña
donde este líder trabaja. `meta.aviso_proxy` lo dice con esas palabras y viaja
siempre con el dato.

### `posible_inflado`: una señal, no un veredicto

Marca a quien **movilizó mucho** y a la vez **sus mesas rindieron poco**:

```
posible_inflado = movilizados_identificados >= umbral_movilizados
               && deficit_ponderado        >= umbral_deficit_ponderado
```

Las dos condiciones van juntas a propósito. Un líder con dos personas en una mesa
mala no tiene una base inflada, tiene dos personas; y uno con cien personas en
mesas que rindieron no tiene nada que explicar.

Los umbrales viven en `config/e14.php` (`e14.rendimiento_lideres`), por defecto
**20 movilizados** y **30 puntos** de déficit ponderado —sus mesas al 70 % o menos—,
y se pueden mover con `E14_UMBRAL_MOVILIZADOS` / `E14_UMBRAL_DEFICIT_PONDERADO`.
Vienen en `meta.umbrales` para que la UI pueda explicar la marca en vez de
mostrarla a secas. Cambiarlos cambia **a quién se mira primero**, no quién hizo
trampa: la señal significa «anda a preguntar», y `meta.aviso_inflado` obliga a
decirlo junto a la marca.

### Orden del ranking

`order=deficit` (por defecto) pone arriba el mayor `deficit_ponderado`, para que lo
accionable no haya que buscarlo; quien no tiene proxy va al final y no se cuela
entre medias. `order=actividad` ordena por movilización identificada, check-ins y
reuniones —solo la mitad cierta—. Empates, por nombre.

### Lo que no hace

- **No persiste nada**: se calcula al preguntarlo, igual que el cruce.
- **No suma el equipo**: mide las reuniones que el líder planeó, no las de quienes
  le reportan (`reports_to`). El rollup por rama del organigrama es otra pregunta.
- **No captura metas de votos por líder**: su trabajo es operativo y no existe tal
  meta.

---

## Conciliación de puestos (Spec 0062)

`norm()` une lo que se puede unir sin adivinar —mayúsculas, acentos, espacios—,
pero «COL. SAN SIMON» y «COLEGIO SAN SIMON» son el mismo colegio y dos renglones
distintos. El servidor **no los une por parecido**: los lista, sugiere candidatos, y
ejecuta la decisión de una persona.

### `GET /puestos-por-conciliar`

Parámetros: `event` (por defecto la elección más reciente), `incluir` (igual que en
el cruce).

```json
{
  "data": [
    {
      "origen": "acta",
      "voting_place_id": 44,
      "departamento": "TOLIMA", "municipio": "IBAGUE", "puesto": "COLEGIO SAN SIMON",
      "registrados": 0, "actas": 1,
      "sugerencias": [
        { "voting_place_id": 12, "municipio": "IBAGUE", "puesto": "COL. SAN SIMON",
          "registrados": 50, "actas": 0, "palabras_en_comun": 2 }
      ]
    },
    {
      "origen": "votante",
      "voting_place_id": null,
      "municipio": "IBAGUE", "puesto": "COL. SAN SIMON",
      "registrados": 50, "actas": 0,
      "sugerencias": [ /* … */ ]
    }
  ],
  "meta": {
    "electoral_event_id": 3, "incluir": "voters", "total": 2,
    "registrados_sin_conciliar": 50, "actas_sin_puesto": 0, "fusiones": 0
  }
}
```

Se listan las **dos formas de quedarse sin pareja**:

- `origen: "acta"` — un puesto con acta y **cero registrados**. Casi siempre es un
  renglón que creó la grafía del acta, y los votantes están en otro.
- `origen: "votante"` — un nombre de puesto de `voters`/`leads` que no resuelve a
  ningún renglón del catálogo.

Lo que **no** se lista son los puestos con registrados y sin acta: eso no es un
problema de nombres, son actas que todavía no han llegado, y ya salen en la
cobertura del cruce. Meterlos aquí llenaría la pantalla de ruido la noche del
escrutinio, justo cuando tiene que servir para algo.

`meta.actas_sin_puesto` son las actas sin `lugar` legible: no tienen nombre que
fusionar y se arreglan corrigiendo el acta (`PUT /actas/{id}`).

Las `sugerencias` son eso, sugerencias: los puestos del mismo municipio que tienen
lo que a este le falta, ordenados por cuántas palabras de 4+ letras comparten
(compartir «SAN» no dice nada; compartir «COLEGIO SIMON» sí).

### `POST /puestos/fusionar`

```json
{ "origen_id": 44, "destino_id": 12 }
```
```json
{ "municipio": "IBAGUE", "puesto": "COL. SAN SIMON", "destino_id": 12 }
```

El origen viene de una de las dos formas de la lista: un renglón del catálogo
(`origen_id`) o un nombre suelto que nunca llegó a tener renglón (`municipio` +
`puesto`, obligatorios sin `origen_id`). Permiso `manage_e14`.

Responde con el destino y cuántas filas se movieron:

```json
{
  "data": {
    "voting_place_id": 12, "departamento": "TOLIMA", "municipio": "IBAGUE",
    "puesto": "COL. SAN SIMON", "nombre_fusionado": "COLEGIO SAN SIMON",
    "votantes_movidos": 0, "actas_movidas": 1
  },
  "message": "«COLEGIO SAN SIMON» ahora cuenta en «COL. SAN SIMON»."
}
```

Es **idempotente**: repetirla no mueve nada la segunda vez. Y las cadenas se
aplanan — fusionar A→B cuando ya existía C→A deja las tres en B, para que nunca
haya que seguir una cadena para saber dónde cuenta un registro.

### Qué hace exactamente una fusión

Dos cosas, y ninguna de ellas es tocar el catálogo global:

1. **Guarda la decisión** en `e14_puesto_alias` (por tenant): «este nombre
   normalizado va a este puesto». El resolver la consulta **antes** que el
   catálogo, así que las actas que lleguen después también caen ahí. Sin esto, la
   siguiente acta con la grafía absorbida desharía la fusión en silencio.
2. **Reapunta las filas propias** del tenant que ya existían: sus `voters` y sus
   `e14_actas`.

`voting_places` **no se toca**: es un catálogo global compartido entre campañas, y
borrar o mover un renglón cambiaría los datos de otros tenants sin que nadie lo
haya pedido (Constitución, Art. III). Lo que se guarda es «en esta campaña, este
nombre va a este puesto».

El repunte se hace con un `UPDATE` masivo —pueden ser miles de votantes—, así que
no deja auditoría fila por fila; el rastro de quién decidió qué y cuándo es el
registro de `e14_puesto_alias`, que sí está auditado.

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

### `e14_puesto_alias`
`id`, `tenant_id`, `clave`, `voting_place_id`, `municipio`, `puesto`, timestamps.
Único: `(tenant_id, clave)`.

Las fusiones de puestos de la 0062. `clave` es `norm(municipio)|norm(puesto)`: la
llave es el **nombre**, no un id, porque hay grafías que nunca llegaron a tener
renglón en el catálogo y tienen que poder fusionarse igual. `municipio` y `puesto`
se guardan crudos para que la auditoría pueda decir qué se fusionó y no solo su
hash.

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
