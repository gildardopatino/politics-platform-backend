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
Σ candidatos + blanco + nulos + no marcados  =  suma declarada  =  urna nivelada
urna nivelada  =  votos_urna − votos_incinerados
```

Un acta que cumple las tres igualdades queda `procesada` y entra al
consolidado. Si falla alguna, queda `inconsistente` con el motivo y **no suma**.

El servidor **recalcula siempre** esta cuenta con los números crudos que recibe,
aunque el cliente ya mande su propio veredicto. No es desconfianza en el lector:
el `estado` decide qué votos entran al total, así que no puede depender de que el
cliente lo calcule bien. Que ambos manden su resultado y gane el del servidor es
justamente lo que permite que una discrepancia se note.

Aparte va la **nivelación**: `dif_nivelacion = votantes_e11 − urna nivelada`. Que
alguien se registrara y no depositara es una novedad del acta, no un error de
lectura; se anota y no bloquea nada. Con la mesa bien nivelada da **0**.

### La urna es la nivelada, no la cruda (Spec 0088)

El bloque «NIVELACIÓN DE LA MESA» del E-14 trae **tres** casillas: sufragantes
del E-11, votos en la urna y **votos incinerados**. Cuando en la urna hay más
votos que sufragantes, los jurados extraen al azar el excedente y lo **incineran
sin abrirlo** para nivelar la mesa. La regla de la Registraduría es exacta:

```
votos_urna − votos_incinerados = sufragantes (E-11)
```

Los votos que se cuentan salen de la urna **ya nivelada**, así que es contra ella
—y no contra la cruda— que se compara la suma de las casillas, en uninominal y en
corporación. Compararlas contra la cruda descuadraba en falso **toda** acta con
incineración: la que lo destapó traía E-11 253, urna 254 y un incinerado, cuadraba
en 253 y salía `inconsistente`.

`votos_urna` se guarda y se devuelve **cruda** —es lo que dice el papel— y
`votos_incinerados` viaja al lado, para que quien lea el acta pueda rehacer la
cuenta que la juzgó. Con `votos_incinerados = 0` —el caso normal— nada cambia
respecto de 0061/0067.

Los motivos de descuadre nombran la urna nivelada y llevan la cuenta a la vista:

```
la suma declarada (252) no coincide con la urna nivelada (253 = 254 − 1 incinerado)
```

**Borde:** si el acta declara más incinerados que votos en la urna —imposible bien
diligenciada— la urna nivelada se acota a **0**, nunca a un negativo, y el acta va
a revisión con su propio motivo:

```
el acta declara más votos incinerados (300) que votos en la urna (254): la
nivelación de la mesa está mal diligenciada
```

La misma fórmula la aplica el lector Python antes de publicar (0088-A); el
veredicto que manda sigue siendo el del servidor.

### El acta sin datos no cuadra (Spec 0077)

La igualdad de arriba tiene un cero a la izquierda: **`0 = 0 = 0` la cumple**. Un
acta que el lector no consiguió transcribir —ninguna casilla, la urna vacía—
salía por eso `procesada`, entraba al consolidado aportando nada y desaparecía de
la cola de revisión. Peor que un error de suma, porque no se ve.

Por eso `CuadreService::evaluar()` tiene un guardia **antes** de las
comparaciones:

```
suma_calculada == 0  &&  suma_declarada == 0  &&  urna nivelada == 0
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
| `suma_calculada`, `suma_declarada`, `votos_urna`, `votos_incinerados`, `votantes_e11`, `dif_nivelacion` → `0` | `tipo`, `electoral_event_id` |
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

Los dos primeros son **uninominales**: una página, un candidato por renglón. Los
otros tres son **corporaciones con voto preferente** (spec 0067): N páginas,
agrupaciones políticas y, dentro de cada una, votos por la lista y por cada
candidato. Se leen, se cuadran y se consolidan **distinto** — ver
[Corporación](#corporación-concejo-senado-asamblea-spec-0067).

La diferencia atraviesa toda la API, así que conviene tenerla presente:

| | Uninominal | Corporación |
| --- | --- | --- |
| Qué trae el acta | `resultados[]` (candidato, votos) | `listas[]` (agrupación → preferentes) |
| `suma_declarada` | **obligatoria** | **no existe** en el papel; no se exige ni se guarda |
| Cuadre global | casillas = declarada = urna nivelada | Σ totales de agrupación + controles = **urna nivelada** |
| Cuadre por fila | — | `solo_lista + Σ preferentes = total_agrupacion` |
| Consolidado | por candidato | por lista **y** por (lista, preferente) |

En PostgreSQL ambos campos llevan CHECK; en SQLite —donde corren las pruebas— el
motor lo ignora, así que quien atrapa el valor inválido es la validación de
entrada.

---

## Una campaña, una elección (Spec 0093)

El producto se vende **por uso**: cada tenant sirve a **una** elección. La
campaña de un alcalde escruta alcaldía y nada más; quien necesite dos
elecciones, dos tenants.

Eso ya estaba en el modelo —`tenants.tipo_cargo` es obligatorio al crear la
campaña— pero media API ofrecía además elegir el tipo: la carga lo pedía, el
cruce, el consolidado y las estadísticas lo aceptaban por query. Ese parámetro
era, de hecho, **la forma de mirar la elección de al lado**: bastaba con armar la
petición a mano. La 0093 lo cerró en dos capas.

### Capa 1 — el tipo sale del tenant

**El mapa canónico vive en `Tenant::ELECCION_POR_CARGO`**, pegado a
`Tenant::TIPOS_CARGO` porque son la misma decisión mirada dos veces; una prueba
comprueba que las dos listas cubren exactamente los mismos cargos.
`Tenant::tipoEleccion()` es su lectura desde el modelo.

| `tenants.tipo_cargo` | elección E-14 |
| --- | --- |
| `Gobernacion` | `gobernacion` |
| `Alcaldia` | `alcaldia` |
| `Concejo` | `concejo` |
| `Congresista` | `senado` |
| `Diputado` | `asamblea_departamental` |
| `Otro` | — (no escruta) |

Consecuencias en el contrato:

| Endpoint | Qué cambió |
| --- | --- |
| `POST /actas/upload` | **ya no lleva `tipo`**; lo pone la campaña |
| `GET /actas` | filtra por la elección de la campaña; el `?tipo=` se ignora |
| `GET /consolidado` | el `?tipo=` se ignora; antes, sin él, sumaba **todas** las elecciones cargadas |
| `GET /estadisticas` | `tipo` pasó de obligatorio (0092) a ignorado |
| `GET /cruce`, `GET /proyeccion` | abren sobre la elección de la campaña, no sobre «la más reciente del tenant» |
| `PUT /tenants/{id}` | **ya no acepta `tipo_cargo`**: es inmutable |

El `?tipo=` se **ignora**, no se rechaza: un 422 sería una invitación a seguir
probando, y el punto es que no hay nada que probar. Con `?event=` sí hay error,
porque ahí el cliente nombra algo concreto: una elección de otro tipo responde
**422** en vez de servirse.

**`tipo_cargo` es inmutable** porque de él cuelga todo el escrutinio —las actas
ya cargadas, la elección donde vive «mi candidato», el cruce—. Cambiarlo dejaría
a la campaña rechazando sus propias actas por «ser de otra elección».

**Una campaña de cargo `Otro` no escruta.** No se le inventa un tipo: la carga
responde 422 (`cargo`), lo mismo el cruce, el consolidado, las estadísticas y la
proyección, y `GET /actas` devuelve la lista vacía —que es la respuesta honesta a
«enséñame tus actas» cuando no hay ninguna que enseñar.

### Capa 2 — el acta que no es de esta elección se rechaza

Que el tipo lo ponga la campaña impide *elegir* mal, no impide *subir* un acta de
otra elección. Por eso el lector devuelve `eleccion_detectada` —lo que leyó
impreso en el encabezado del papel— al publicar el resultado, y el backend
compara. Si no coincide, el acta **no se procesa**: se borra en duro, fila y
archivo, y queda constancia. Ver
[`POST /actas/{id}/resultado`](#post-actasidresultado--publicar-la-lectura-worker)
y [`GET /rechazos`](#get-rechazos--las-actas-que-no-eran-de-esta-elección-spec-0093).

**Ante la duda no se borra**: un encabezado ilegible llega como `null` y el acta
sigue el flujo normal. Un OCR flojo no puede tener permiso de borrado.

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
| `view_e14` | `GET /actas`, `GET /actas/{id}`, `GET /consolidado`, `GET /resumen`, `GET /rechazos`, `GET /eventos`, `GET /candidato`, `GET /cruce`, `GET /estadisticas`, `GET /rendimiento-lideres`, `GET /meta`, `GET /proyeccion`, `GET /puestos-por-conciliar` |
| `manage_e14` | `POST /actas`, `POST /actas/upload`, `POST /actas/procesar`, `POST /actas/siguiente`, `POST /actas/{id}/resultado`, `POST /actas/{id}/reprocesar`, `PUT /actas/{id}`, `DELETE /actas/{id}`, `PUT /candidato`, `PUT /meta`, `POST /puestos/fusionar` |

Los tiene `admin` y `coordinator`; `viewer` solo `view_e14`. Sin el permiso, 403.

---

## Endpoints

Todos bajo `/api/v1/e14`, con `throttle:120,1`. La descarga del PDF es la única
excepción: cuelga de `/api/v1/e14/actas/{id}/archivo` y va por firma, no por
sesión.

---

## Configuración de campaña: mi candidato (Specs 0062, 0080 y 0082)

En clave SaaS cada tenant es la campaña de **un** candidato a **un** cargo, y ese
candidato es **una fila del E-14**. El consolidado no lo necesita —suma a todos—,
pero el cruce sí: «base identificada vs votos» no se puede calcular sin saber de quién
son los votos.

La 0062 lo configuraba **por elección**, así que la pantalla ofrecía tantas fichas como
elecciones hubiera cargadas y el nombre se tecleaba en cada una. La 0080 lo corrigió:
hay **una** ficha por campaña, su identidad sale del `Tenant` y lo único que se
configura a mano es el número del tarjetón.

La 0082 la hizo **cargo-aware**. En uninominal el candidato es un número del tarjetón y
con eso basta; en corporación es una persona **dentro de una lista**, y su identidad es
el par `(número de lista, número de preferencia)`. Un preferente suelto no identifica a
nadie — el 5 existe en todas las listas, y el cruce los sumaría todos.

| | Uninominal (alcaldía, gobernación) | Corporación (concejo, senado, asamblea) |
| --- | --- | --- |
| Se configura | `numero` (tarjetón) | `lista_numero` **+** `numero` (preferencia) |
| Catálogo | `candidatos[]` (de `e14_candidates`) | `listas[]` con sus preferentes |
| `data.lista_numero` | siempre `null` | el número de la lista |
| `meta.es_corporacion` | `false` | `true` |

### De dónde sale cada dato

| Dato | Origen | ¿Se edita? |
| --- | --- | --- |
| Nombre del candidato | `tenants.nombre` | No — se administra en la configuración de la campaña |
| Cargo | `tenants.tipo_cargo` | No — ídem |
| **Número** (tarjetón o preferencia) | `electoral_events.candidato_propio_numero` | **Sí** |
| **Número de lista** (solo corporación) | `electoral_events.candidato_propio_lista_numero` | **Sí**; `null` en uninominal |
| Agrupación / partido | `electoral_events.candidato_propio_agrupacion` | Sí, opcional; se completa desde el tarjetón (uninominal) o desde el **nombre de la lista** elegida (corporación) |

El tenant **no tiene** campo de partido, así que la agrupación se queda en la elección
y es editable, con lo que traiga el tarjetón como valor por defecto.

### A qué elección aplica: `tipo_cargo` → `tipo`

El número vive en **una** elección: la del cargo de la campaña. Con varias del mismo
tipo —dos alcaldías de años distintos— gana la **más reciente por fecha**, que es la
que se está escrutando.

Los dos campos **no hablan el mismo idioma**: `tenants.tipo_cargo` es el enum del alta
de campañas (capitalizado, sin tildes) y `electoral_events.tipo` es el vocabulario del
E-14 (`E14Acta::TIPOS`). Traducir por texto acertaría en tres casos y fallaría en los
dos que importan, así que la tabla es explícita y vive en un solo sitio,
`Tenant::ELECCION_POR_CARGO` —pegada al enum de cargos que traduce desde la 0093, que
la convirtió en **la** elección del tenant, la que manda en toda la app
(`Tenant::tipoEleccion()`); `EventoResolver::tipoDelCargo()` es su atajo—:

| `tenants.tipo_cargo` | `electoral_events.tipo` | Nota |
| --- | --- | --- |
| `Alcaldia` | `alcaldia` | |
| `Gobernacion` | `gobernacion` | |
| `Concejo` | `concejo` | |
| `Diputado` | `asamblea_departamental` | Un diputado se elige en la asamblea: el cargo y el tipo de acta se llaman distinto |
| `Congresista` | `senado` | El E-14 solo tiene `senado` para el Congreso |
| `Otro` | — | No es un cargo de elección popular |

Lo que no está en la tabla **no se adivina**. Con un cargo sin elección, `GET` responde
200 con el aviso de qué falta configurar y `PUT` responde 422: escribir el número en la
elección equivocada es peor que no configurarlo.

> **Cámara fuera, y las tres fuentes reconciliadas (Spec 0084).** Hasta la 0084,
> `Representante` (Cámara de Representantes) estaba en el `FormRequest` de alta de
> tenants pero **no** en el enum de la columna: una campaña de Cámara pasaba la
> validación y reventaba contra el CHECK al insertar, lejos de la causa. Se quitó del
> FormRequest y del mapeo porque el sistema **no tiene actas de Cámara** —sin tipo de
> acta E-14 no hay escrutinio ni cruce que ofrecer—, y la lista quedó en un solo sitio,
> `Tenant::TIPOS_CARGO`: `Gobernacion|Alcaldia|Concejo|Congresista|Diputado|Otro`, que
> es exactamente lo que admite la columna. Una prueba compara las tres fuentes —columna,
> constante y reglas— y se cae si alguien mueve una sola.
>
> La Cámara vuelve cuando exista su lector: es corporación con voto preferente, así que
> re-habilitarla es añadir el tipo de acta y el mapeo, una extensión pequeña de la 0067.

### Corporación: lista + preferente (Spec 0082)

Cuando el `tipo_cargo` del tenant mapea a `concejo`, `senado` o
`asamblea_departamental`, la ficha pide **dos** números en vez de uno.

**El catálogo se deriva, no se guarda.** En uninominal es una tabla —
`e14_candidates`, que la ingesta llena sola con la primera acta que menciona a
cada candidato. En corporación no hay equivalente y no debería haberlo: el E-14
de corporación **no trae nombres** de los preferentes, solo números (0067), así
que no queda nada que catalogar que no esté ya en el resultado. Se arma con una
consulta sobre `e14_lista_resultados` (las listas) + `e14_lista_preferentes`
(sus números). Una tabla aparte sería una segunda verdad sobre el mismo papel, y
la primera vez que alguien corrigiera un acta a mano las dos empezarían a
discrepar.

**Solo entran las actas `procesada`**, igual que en el consolidado. Una acta
inconsistente pudo leer mal el número de la lista, y ofrecer un «517» que en
realidad era «5170» dejaría fijar el candidato en una lista que no existe —
exactamente el error que la regla de los dos momentos quiere evitar.

> Es una asimetría consciente con el uninominal, cuyo catálogo se llena en la
> ingesta sin mirar el estado del acta. Aquí se puede ser más estricto porque el
> dato ya está en el resultado, y conviene serlo porque el número de lista es más
> largo —hasta cinco cifras— y por tanto más fácil de transcribir mal.

Una lista **sin voto preferente** aparece en el catálogo con `preferentes: []`:
existe en el tarjetón y no tiene números.

### `GET /candidato` — la ficha

```json
{
  "data": {
    "nombre": "MIGUEL ALCALDE",
    "cargo": "Alcaldia",
    "cargo_label": "Alcaldía",
    "numero": 4,
    "agrupacion": "MOVIMIENTO X",
    "configurado": true,
    "eleccion": {
      "id": 3,
      "nombre": "Alcaldía",
      "tipo": "alcaldia",
      "fecha": "2027-10-31",
      "tiene_actas": true
    },
    "lista_numero": null,
    "candidatos": [
      { "numero": 1, "nombre": "JORGE BOLIVAR TORRES", "agrupacion": null, "cargo": "alcaldia" }
    ],
    "listas": []
  },
  "meta": {
    "cargo_mapeado": true,
    "tipo_eleccion": "alcaldia",
    "es_corporacion": false,
    "aviso": null
  }
}
```

La misma ficha para un concejo, con el par y su catálogo:

```json
{
  "data": {
    "nombre": "ANA RUIZ",
    "cargo": "Concejo",
    "cargo_label": "Concejo",
    "numero": 5,
    "lista_numero": 11,
    "agrupacion": "PARTIDO CENTRO DEMOCRÁTICO",
    "configurado": true,
    "eleccion": { "id": 9, "nombre": "Concejo", "tipo": "concejo", "fecha": null, "tiene_actas": true },
    "candidatos": [],
    "listas": [
      {
        "lista_numero": 1,
        "lista_nombre": "PARTIDO LIBERAL COLOMBIANO",
        "preferentes": [{ "numero": 3 }, { "numero": 7 }]
      },
      {
        "lista_numero": 11,
        "lista_nombre": "PARTIDO CENTRO DEMOCRÁTICO",
        "preferentes": [{ "numero": 1 }, { "numero": 5 }, { "numero": 9 }]
      }
    ]
  },
  "meta": {
    "cargo_mapeado": true,
    "tipo_eleccion": "concejo",
    "es_corporacion": true,
    "aviso": null
  }
}
```

| Campo | Qué es |
| --- | --- |
| `nombre`, `cargo`, `cargo_label` | Identidad del tenant, **de solo lectura** |
| `numero` | El del tarjetón (uninominal) o el de **preferencia** (corporación) |
| `lista_numero` | Solo corporación: el número de la lista. `null` en uninominal |
| `agrupacion` | Lo configurado; `null` si aún no lo está |
| `configurado` | Si el cruce se puede calcular: `numero !== null` en uninominal, y **también** `lista_numero !== null` en corporación (0083) |
| `eleccion` | La del cargo; **`null`** si el cargo no mapea o si aún no existe (se creará al fijar el número) |
| `eleccion.tiene_actas` | Le dice al frontend si el número se escribe a mano o se elige de una lista: la misma regla que valida el servidor |
| `candidatos` | Uninominal: el tarjetón de esa elección. `[]` en corporación |
| `listas` | Corporación: las listas del tarjetón con sus números de preferencia. `[]` en uninominal |
| `meta.cargo_mapeado` | `false` cuando el `tipo_cargo` no tiene elección; la pantalla debe mandar a configurar el cargo, no a fijar un número |
| `meta.es_corporacion` | Qué campos pide la ficha: un número suelto, o el par lista + preferente |
| `meta.aviso` | El texto de ese caso, en español; `null` cuando todo está en orden |

El tarjetón viaja **con** la ficha y no en un endpoint aparte, por lo mismo que en la
0062: la pantalla lo necesita siempre —es de donde se elige el número— y pedirlo en dos
viajes solo abriría la ventana para mostrar un selector vacío mientras llega el segundo.

### `PUT /candidato` — fijar o quitar el número

Uninominal:

```json
{ "numero": 4, "agrupacion": "MOVIMIENTO X" }
```

Corporación — el par, y la lista es **obligatoria** al fijar:

```json
{ "lista_numero": 11, "numero": 5 }
```

| Campo | Regla |
| --- | --- |
| `numero` | **obligatorio en la petición**, admite `null` para deshacer; 1–999. Tarjetón en uninominal, **preferencia** en corporación |
| `lista_numero` | 1–99999. **Obligatorio en corporación** cuando se fija; en uninominal se **ignora** y se guarda `null` |
| `agrupacion` | opcional; si falta se toma del tarjetón (uninominal) o del nombre de la lista elegida (corporación) |

**Por qué la lista es obligatoria.** Un preferente suelto no identifica a nadie: el 5
existe en todas las listas del tarjetón, y el cruce (0083) los sumaría todos. La
identidad de un candidato de corporación es el **par**.

**El `nombre` no viaja.** Se escribe siempre desde `tenants.nombre`, y un `nombre` que
mande el cliente se ignora: volver a pedir la identidad aquí es lo que permitía que la
ficha del escrutinio dijera una cosa y la configuración de la campaña, otra.

**Find-or-create.** Si todavía no hay elección del cargo, se crea al fijar el número: el
número del tarjetón se sabe semanas antes de la primera acta, igual que en la ingesta.

**Los dos momentos** (regla de la 0062). Antes de la primera acta se acepta a ciegas.
En cuanto existe catálogo:

- **Uninominal:** un `numero` que no está en `e14_candidates` responde 422.
- **Corporación:** el **par** tiene que existir. La lista se comprueba primero —si no
  está, el 422 va en `lista_numero`—; y solo si la lista existe se comprueba el
  preferente dentro de ella, con el 422 en `numero`. El orden importa: decir «ese
  preferente no está» cuando la lista es la equivocada manda a buscar en el sitio
  equivocado.

Un acta que **no cuadra** no abre el catálogo de corporación (ver arriba), así que
mientras todas estén inconsistentes se sigue aceptando a ciegas.

`numero: null` borra la ficha completa —nombre, agrupación y **lista** incluidos— y deja
el cruce sin calcular: un nombre suelto de un candidato que ya no se cruza solo confunde
a quien lo lea después. Deshacer no valida nada: es la salida de una configuración mal
puesta y tiene que funcionar aunque lo guardado ya no exista.

Cambiar el número **no recalcula nada guardado**: el cruce se calcula al preguntarlo,
así que la siguiente llamada a `/cruce` ya sale con el candidato nuevo.

| Respuesta | Cuándo |
| --- | --- |
| `200` | Fijado o borrado; devuelve la ficha completa y un `message` |
| `422` en `numero` | Hay catálogo y el número (o el preferente, dentro de su lista) no está en él |
| `422` en `lista_numero` | Corporación: falta la lista al fijar, o no está en el catálogo |
| `422` en `cargo` | El `tipo_cargo` de la campaña no mapea a ninguna elección |
| `403` | Falta `manage_e14`, o la sesión no pertenece a ninguna campaña (super admin) |

### El número sigue donde el cruce lo lee

La 0080 cambió el **flujo**, no el modelo: `candidato_propio_numero` / `_nombre` /
`_agrupacion` siguen en `electoral_events`, que es de donde el cruce (0062), el déficit
(0076) y el rendimiento de líderes (0063) los leen. Ninguno de los tres se tocó. Mover
el número al `Tenant` era la alternativa más pura conceptualmente, y se descartó
justamente por eso: no vale reescribir tres lectores por un cambio de pantalla.

### `GET /eventos` — las elecciones (lectura)

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

Se queda como **lectura**: de aquí salen las elecciones con las que el cruce y el
consolidado eligen de cuál se consulta. Ya no es la vía para configurar el candidato.

> **Retirado en la 0080:** `PUT /eventos/{id}/candidato-propio` (0062). Era la única
> forma de fijar candidato en una elección que no es la del cargo de la campaña, que es
> justo lo que la 0080 elimina. Su sustituto es `PUT /candidato`. Un cliente que siga
> llamándolo recibe `404`.

### Limpieza: `php artisan e14:candidato-unico`

Deja `candidato_propio_*` en `NULL` en toda elección del tenant que **no** sea la de su
cargo — deshace lo que permitía la pantalla vieja. Acepta `--tenant=<id>` para acotar.

Es **idempotente**: escribe solo donde hay algo que borrar, así que la segunda corrida
no toca nada (ni mueve `updated_at`). Va enganchado a `DatabaseSeeder`, de modo que
`migrate:fresh --seed` lo ejecuta como no-op y una base traída de un entorno con la
pantalla vieja queda consistente sin depender de que alguien recuerde el comando.

Una campaña cuyo cargo **no mapea** se **salta** y se informa por consola. Sin saber
cuál es su elección legítima, borrar destruiría el único candidato que tiene y la
dejaría sin forma de volver a fijarlo (el endpoint también la rechaza). La causa es el
cargo, y es lo que hay que arreglar.

---

## Flujo web: carga y cola (Spec 0071)

Cuatro llamadas: se cargan los PDFs, se da la orden, el worker toma de a una y
devuelve lo que leyó.

### `POST /actas/upload` — subir un acta

`multipart/form-data`. Permiso `manage_e14`.

| Campo | |
| --- | --- |
| `archivo` | requerido, PDF (se valida el mimetype real, no la extensión) |
| `electoral_event_id` | opcional |
| `upload_batch_id` | opcional; si no viene, el servidor abre uno y lo devuelve |

**Ya no lleva `tipo` (Spec 0093).** Hasta la 0071 lo elegía quien subía, porque
el dato no está en un sitio fiable del papel; el precio era que nada impedía
cargar un acta de otra elección y etiquetarla mal, y una etiqueta equivocada
manda el acta al parser que no le toca. Ahora lo pone la campaña —una campaña,
una elección— y lo que el papel diga de verdad lo comprueba el lector al leerlo.
Un `tipo` que llegue en el cuerpo se ignora; una campaña de cargo `Otro` recibe
**422** con el aviso de que no tiene escrutinio.

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
  "eleccion_detectada": "alcaldia",
  "fuente": "vision",
  "suma_declarada": 111, "votos_urna": 111, "votos_incinerados": 0, "votantes_e11": 111,
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

### El acta de otra elección se rechaza (Spec 0093)

`eleccion_detectada` es **la elección que el lector leyó impresa en el
encabezado** del acta: uno de los cinco tipos, o `null` si no la pudo leer. Es la
capa 2 de «una campaña, una elección»: la capa 1 impide *elegir* otra elección,
esta impide *colar* un acta de otra.

Al publicar, el backend compara con la elección de la campaña:

| `eleccion_detectada` | Qué pasa |
| --- | --- |
| coincide con la de la campaña | flujo normal |
| `null`, ausente, o un valor que el enum no conoce | flujo normal — **ante la duda no se borra** |
| otra elección | **rechazo**: el acta y su PDF se borran en duro |

El rechazo responde `200` con el acuse de que no se procesó:

```json
{
  "data": null,
  "estado": "rechazada",
  "rechazo": {
    "archivo_nombre": "acta-001.pdf",
    "eleccion_detectada": "gobernacion", "eleccion_detectada_nombre": "Gobernación",
    "eleccion_esperada": "alcaldia", "eleccion_esperada_nombre": "Alcaldía",
    "motivo": "El acta es de Gobernación y esta campaña escruta Alcaldía; se eliminó junto con su archivo."
  },
  "message": "El acta es de Gobernación y esta campaña escruta Alcaldía; se eliminó junto con su archivo."
}
```

Tres cosas que conviene tener claras:

- **El borrado es duro y es el mismo de la 0077**: fila, resultados y archivo. Es
  lo que libera el `archivo_hash` para que el mismo PDF pueda volver a entrar si
  la detección se equivocó.
- **Queda constancia.** Borrar en silencio sería inexplicable: alguien sube
  ochenta PDFs, aparecen setenta y seis actas y nadie sabe qué pasó con las otras
  cuatro. El renglón de `e14_actas_rechazadas` es la respuesta, y se lee con
  [`GET /rechazos`](#get-rechazos--las-actas-que-no-eran-de-esta-elección-spec-0093).
- **Es idempotente.** Reenviar el mismo resultado da `404` —el acta ya no
  existe—, que es el contrato que el worker tolera desde la 0077. Y volver a
  cargar y volver a rechazar el mismo PDF **actualiza** su renglón en vez de
  acumular uno por intento: la constancia es única por `(tenant, archivo_hash)`.

Un valor fuera del enum vale como duda a propósito. Rechazarlo con 422 dejaría al
worker reintentando para siempre un acta que leyó bien salvo por una palabra, y el
precio de equivocarse por exceso es un acta borrada sin vuelta atrás.

### `GET /rechazos` — las actas que no eran de esta elección (Spec 0093)

Permiso `view_e14`. Acotado por tenant. Del más reciente al más viejo,
`per_page` 50 por defecto.

```json
{
  "data": [
    {
      "id": 3,
      "electoral_event_id": 7,
      "archivo_nombre": "acta-001.pdf",
      "archivo_hash": "9f2c…",
      "eleccion_detectada": "gobernacion", "eleccion_detectada_nombre": "Gobernación",
      "eleccion_esperada": "alcaldia", "eleccion_esperada_nombre": "Alcaldía",
      "motivo": "El acta es de Gobernación y esta campaña escruta Alcaldía; se eliminó junto con su archivo.",
      "created_at": "2026-08-24T10:12:00-05:00"
    }
  ],
  "meta": { "total": 1, "current_page": 1, "last_page": 1, "per_page": 50 }
}
```

**Sin PII** (Art. VII): el acta rechazada nunca se leyó, así que aquí no hay
mesa, ni cifras, ni candidatos — solo metadatos del archivo y las dos
elecciones. Las dos viajan en los dos idiomas: el enum, para agrupar o filtrar, y
el nombre en español, para que la tabla se pinte sin llevar su propia traducción.

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
  "votos_incinerados": 0,
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

Permiso `view_e14`. Filtros: `estado`, `zona`, `puesto`, `mesa`,
`electoral_event_id`, `per_page` (50 por defecto). Ordena por zona, puesto y mesa.

**El `tipo` ya no se filtra: se impone** (Spec 0093). El listado trae las actas
de la elección de la campaña y solo esas; un `?tipo=` en la URL se ignora. Una
campaña de cargo `Otro` recibe la lista vacía.

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

Acepta cualquier subconjunto de `suma_declarada`, `votos_urna`,
`votos_incinerados`, `votantes_e11`,
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

Votos por candidato **solo de las actas `procesada`**. Filtro:
`electoral_event_id`.

**El tipo lo pone la campaña** (Spec 0093): el `?tipo=` se sigue aceptando por
compatibilidad pero no se lee. Antes, sin `tipo`, sumaba **todas** las elecciones
que la campaña tuviera cargadas — dos escrutinios distintos en un mismo total.

> Si la campaña es de corporación (`concejo`, `senado`, `asamblea_departamental`)
> la respuesta es **de dos niveles** —por lista y por (lista, preferente)— y no
> la de abajo. Ver [Corporación](#corporación-concejo-senado-asamblea-spec-0067).

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
    "por_puesto": [{ "puesto": "01", "lugar": "INSTITUCION EDUCATIVA SAN JOSE", "departamento": "TOLIMA", "municipio": "IBAGUE", "candidatos": [], "total": 889 }],
    "por_zona":   [{ "zona": "01", "departamento": "TOLIMA", "municipio": "IBAGUE", "candidatos": [], "total": 889 }],
    "por_lugar":  [{ "lugar": "INSTITUCION EDUCATIVA SAN JOSE", "departamento": "TOLIMA", "municipio": "IBAGUE", "candidatos": [], "total": 889 }]
  }
}
```

`por_lugar` (Spec 0074) es el mismo corte que `por_puesto` pero legible: el
nombre impreso del puesto en vez de su código. Las actas sin ese dato no hacen
grupo — un renglón sin nombre con votos dentro se lee como si fuera un puesto de
votación más.

### El desglose agrupa por municipio, no por el código (Spec 0089)

La clave de cada fila es **`(departamento, municipio, eje)`**, y por eso cada una
viaja con `departamento` y `municipio` — y `por_puesto`, además, con el `lugar`.

El motivo es aritmético, no cosmético. El código de puesto (`00`), el de zona
(`00`) y hasta el nombre del lugar (`PUESTO CABECERA MUNICIPAL`) son
identificadores **locales**: se repiten en cada municipio del país. Agrupar por el
eje a secas fundía en una fila las cabeceras de municipios distintos y publicaba
un total que no era de ningún puesto — en datos reales, «por lugar → PUESTO
CABECERA MUNICIPAL = 806» era la suma de varias cabeceras.

| Campo | En qué corte | Qué es |
| --- | --- | --- |
| `departamento`, `municipio` | los tres | De dónde es la fila. `null` cuando el acta no traía ubicación leída |
| `lugar` | `por_puesto` | El nombre impreso del puesto, funcional a su código dentro del municipio: «00» no le dice nada a nadie |

Dos reglas de borde:

- **Sin ubicación no se mezcla.** Un acta con puesto pero sin municipio hace su
  **propio grupo** (`departamento`/`municipio` en `null`, que el panel rotula
  «(sin ubicación)»): colgarla del primer municipio que aparezca sería inventarle
  un origen.
- **Los dos ejes van en la clave** porque hay municipios homónimos en
  departamentos distintos.

El **total global no cambia**: lo que cambia es el reparto. Una prueba fija que
la Σ de las filas de cada corte es `meta.total_candidatos`.

La corporación no tiene desglose geográfico —reparte por lista y por preferente—
así que no había nada que corregir ahí.

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
| `event` | la elección **de la campaña** más reciente | de qué elección se cruza; una de otro tipo responde 422 (Spec 0093) |
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
    "candidato": {
      "numero": 2, "lista_numero": null,
      "nombre": "JOHANA ARANDA", "agrupacion": null,
      "es_corporacion": false
    },
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

`meta.candidato` rotula al candidato en la forma que le toca al cargo: en
uninominal, `numero` (+ nombre y agrupación) con `lista_numero: null`; en
corporación, `lista_numero` **y** `numero` (preferencia). El flag
`es_corporacion` es el que le dice al panel cuál de las dos está mirando —sin él,
un «5» no se sabe si es del tarjetón o de preferencia dentro de una lista—. La
misma forma la publica `/rendimiento-lideres`.

Sin candidato propio configurado responde **422** pidiendo configurarlo, no un
cruce en ceros: un tablero lleno de ceros se lee como «perdí», no como «falta un
dato». En corporación hacen falta **las dos** mitades: un preferente sin lista es
media configuración y también responde 422.

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

**Votos** — depende del cargo, porque «mi candidato» tiene dos formas (Spec 0083).
Solo actas `procesada` y con puesto canónico, agrupadas por el `voting_place_id`
del acta en los dos casos:

| Cargo | De dónde salen los votos |
| --- | --- |
| Uninominal | `e14_resultados` con `numero = candidato_propio_numero` |
| Corporación | `e14_lista_preferentes` unido a `e14_lista_resultados` con `lista_numero = candidato_propio_lista_numero` **y** `numero = candidato_propio_numero` |

**Las dos condiciones, no una.** El número de preferencia se repite en todas las
listas del tarjetón: el 5 del partido A y el 5 del partido B son dos personas, y
filtrar solo por `numero` le atribuiría a tu candidato los votos de sus rivales.
Los `votos_solo_lista` **tampoco** entran: son de la agrupación, no de ningún
candidato.

Lo que sale de la ramificación es la misma tabla con la misma llave, así que el
déficit, la cobertura y el rendimiento de líderes no se enteran de la diferencia:
cuentan igual en los dos casos.

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

## Estadísticas del escrutinio (Spec 0092)

El cruce contesta «¿dónde se me fugó la base?» en una tabla. Esta es la misma
materia prima servida para **explorarla**: una fila por mesa con todo lo que hace
falta para que el panel arme sus gráficas y su drill-down —departamento →
municipio → zona → puesto → mesa— **en memoria**, sin una petición por nivel.

No recalcula nada: pide el cruce a `nivel=mesa` y le cose encima lo que el acta
sabe de esa misma mesa. Una segunda implementación del conteo sería una segunda
cifra que un día deja de cuadrar con «Potencial vs real» sin que nadie sepa cuál
miente.

### `GET /estadisticas`

| Parámetro | Por defecto | Qué hace |
| --- | --- | --- |
| `tipo` | — | **se ignora** desde la 0093; lo pone la campaña |
| `event` | la más reciente **de la elección de la campaña** | fija la elección por id; una de otro tipo responde 422 |
| `municipio` | — | coincidencia parcial sobre el municipio del puesto |
| `voting_place` | — | el **id** si se eligió de la lista, o texto parcial del nombre |
| `incluir` | `voters` | `voters` \| `leads` \| `ambos` — qué cuenta como base |

`nivel` **no** se acepta: la fila de este endpoint es siempre la mesa.

`tipo` era **obligatorio** en la 0092, porque la página se abría eligiendo
elección y «la última» habría sido una respuesta correcta a una pregunta que
nadie hizo. La 0093 respondió esa pregunta de otra forma: la campaña tiene una
sola elección, así que preguntarlo era ofrecer mirar la de al lado. Se sigue
aceptando para no romper a un cliente viejo, y no cambia la respuesta.

```json
{
  "data": [
    {
      "voting_place_id": 12,
      "departamento": "TOLIMA",
      "municipio": "IBAGUE",
      "zona": "01",
      "puesto": "COLEGIO SAN SIMON",
      "mesa": 5,
      "base": 50,
      "votos_candidato": 30,
      "deficit": 20,
      "excedente": 0,
      "tiene_acta": true,
      "votos_urna": 111,
      "votantes_e11": 108
    }
  ],
  "meta": {
    "total": 1,
    "con_acta": 1,
    "electoral_event_id": 3,
    "tipo": "alcaldia",
    "nivel": "mesa",
    "candidato": {
      "numero": 2, "lista_numero": null,
      "nombre": "JOHANA ARANDA", "agrupacion": null,
      "es_corporacion": false
    }
  }
}
```

`meta` no trae los cortes por zona, puesto ni municipio: son el mismo `data`
filtrado, y el cliente los pliega con lo que ya tiene. `total` y `con_acta` son
la cobertura **global**; la del ámbito que el usuario tenga abierto sale de contar
las filas de ese ámbito.

### Las dos mitades de la fila no miden lo mismo

Es lo único que hay que saber para leer este endpoint sin equivocarse:

| Campos | De quién son |
| --- | --- |
| `base`, `votos_candidato`, `deficit`, `excedente` | **del candidato del tenant**: su base identificada y la fila del E-14 con su número (o su par lista + preferente en corporación) |
| `votos_urna`, `votantes_e11` | **de la mesa entera**: todos los candidatos, más blancos, nulos y no marcados |

Ponerlos en la misma fila es lo que permite leer «aquí votaron 400 personas y yo
saqué 12». Dividir uno por el otro sin decirlo inventaría una cuota de mercado que
el acta no afirma.

### No hay «% de participación», y no es un olvido

La participación real es sufragantes ÷ **censo**, y el censo por mesa no existe en
el modelo —ni el histórico de la RNEC ni los habilitados del puesto se guardan—.
Así que aquí solo viajan **volúmenes**: `votantes_e11` (sufragantes del E-11) y
`votos_urna` (papeletas en la urna). Cualquier porcentaje de participación que
alguien muestre a partir de esto se lo habría inventado. El día que haya censo por
mesa se carga como catálogo, igual que `voting_places`, y el turnout real es otra
spec.

### Qué cuenta como lectura, y qué como acta

Los dos umbrales son distintos **a propósito**:

- `votos_urna`, `votantes_e11` y `zona` salen de las actas **leídas**
  (`procesada`, `inconsistente`, `revision_manual`), igual que el desglose del
  consolidado: son transcripciones de casillas, y un acta que no cuadra igual
  reporta cuánta gente votó ahí.
- `tiene_acta` sigue siendo el del cruce —solo `procesada`—, porque esa bandera
  dice si los **votos** de esa mesa se pueden usar para juzgar a alguien.

Una mesa puede entonces traer `tiene_acta: false` con `votos_urna: 120`: se leyó,
no cuadra, y por eso no aporta votos pero sí volumen.

Una mesa **sin** acta va con `votos_urna` y `votantes_e11` en `0`, `zona` en
`null` —una zona en cero sería una zona inventada— y `tiene_acta: false`, y
**cuenta en `total`**: es el denominador de la cobertura, la mesa que todavía
falta por escrutar.

### La llave es puesto + mesa, nunca la mesa sola

El número de mesa es local a su puesto: el «002» del Colegio San Simón y el «002»
de la Escuela La Paz son dos mesas distintas. Cada fila viaja con su
`voting_place_id`, que es lo que las desambigua, y la unión con el acta se hace
por ese par —con la mesa normalizada a entero, así que el `005` del acta casa con
el `5` del votante—.

Las actas sin puesto canónico quedan fuera, igual que en el cruce: sin
`voting_place_id` no hay fila con la que casarlas, y aproximarlas por nombre es
justo lo que la conciliación existe para no hacer. Se ven en
`GET /cruce` → `meta.cobertura.actas_sin_conciliar`.

---

## Meta y proyección: «¿voy ganando?» (Spec 0064)

El cruce dice dónde se fuga y el rendimiento quién moviliza. Falta la pregunta que
abre el tablero: *faltan X votos para ganar, tengo Y identificados, voy al Z %*.

La proyección pone tres números **distintos** uno al lado del otro sin fundirlos:
la **meta** (fijada a mano), la **base identificada** (0076) y los **votos
reales** (0062/0083). Y **orquesta**: la base sale de `RegistradosService` y los
votos de `VotosService`, los mismos que alimentan el cruce y el rendimiento. Nada
se recalcula por su cuenta; si lo hiciera, el día que cambie qué estados de acta
cuentan tendríamos dos tableros contradiciéndose y ninguna forma de saber cuál
miente.

### La meta se fija a mano

No sale del histórico de la Registraduría ni del censo del puesto —los dos quedan
como gancho para más adelante—: la pone el jefe de campaña. Hay **una global por
elección** y **overrides opcionales por puesto**.

**Municipio y zona no se fijan: se agregan de sus puestos.** Una tabla de metas por
municipio conviviendo con las de sus puestos obligaría a decidir cuál gana cuando
no cuadran, y esa pregunta no tiene respuesta buena.

`null` no es `0`. «Todavía no fijé la meta» y «mi meta aquí es cero votos» son
estados distintos: el primero no tiene avance que calcular ni semáforo que
encender, y el segundo pintaría de verde un puesto donde nadie se propuso nada.
Por eso quitar la meta de un puesto **borra la fila** en vez de guardar un cero, y
una meta en `0` se trata como meta sin fijar.

### `GET /meta`

| Parámetro | Por defecto | Qué hace |
| --- | --- | --- |
| `event` | la elección más reciente del tenant | de qué elección es la meta |

```json
{
  "data": {
    "electoral_event_id": 3,
    "meta_votos": 12000,
    "puestos": [
      {
        "voting_place_id": 12,
        "departamento": "TOLIMA",
        "municipio": "IBAGUE",
        "puesto": "COLEGIO SAN SIMON",
        "meta_votos": 300
      }
    ]
  },
  "meta": { "meta_asignada": 300, "sin_asignar": 11700, "puestos_con_meta": 1 }
}
```

`sin_asignar` es `max(0, global − asignada)`, y es `null` cuando no hay meta
global —sin ella no hay nada que repartir, y un `0` ahí se leería como «ya está
todo asignado»—. Que la suma de los puestos sea menor que la global es el estado
normal, no un error; que sea mayor tampoco lo es.

### `PUT /meta` — fijarla (`manage_e14`, auditado)

```json
{
  "event": 3,
  "meta_votos": 12000,
  "puestos": [
    { "voting_place_id": 12, "meta_votos": 300 },
    { "voting_place_id": 15, "meta_votos": null }
  ]
}
```

Es un **PUT parcial**: la clave que no viene no se toca. Mandar solo `puestos` deja
la global como estaba y al revés — la pantalla edita una casilla a la vez, y
exigirle reenviar el estado entero convertiría cada ajuste en una ocasión de pisar
lo que otro acababa de guardar. `meta_votos: null` **sí** viaja y significa
«quítala»: la ausencia de la clave y el nulo explícito son cosas distintas.

Devuelve la misma forma que el `GET`, más `message`. Cambiar la meta mueve el
semáforo de todo el tablero, así que queda auditada (`owen-it`).

### `GET /proyeccion`

| Parámetro | Por defecto | Qué hace |
| --- | --- | --- |
| `event` | la elección **de la campaña** más reciente | de qué elección se proyecta; una de otro tipo responde 422 (Spec 0093) |
| `nivel` | `puesto` | `global` \| `municipio` \| `puesto` |
| `municipio` | — | coincidencia parcial sobre el municipio del puesto |
| `incluir` | `voters` | `voters` \| `leads` \| `ambos` — la misma base de la 0076 |

```json
{
  "data": [
    {
      "voting_place_id": 12,
      "departamento": "TOLIMA",
      "municipio": "IBAGUE",
      "puesto": "COLEGIO SAN SIMON",
      "meta": 300,
      "identificados": 50,
      "votos_reales": 30,
      "tiene_actas": true,
      "actas": 1,
      "avance_base": 16.67,
      "avance_real": 10,
      "faltante": 270,
      "semaforo": "rojo",
      "base_del_semaforo": "real"
    }
  ],
  "meta": {
    "electoral_event_id": 3,
    "nivel": "puesto",
    "incluir": "voters",
    "candidato": {
      "numero": 2, "lista_numero": null,
      "nombre": "JOHANA ARANDA", "agrupacion": null,
      "es_corporacion": false
    },
    "totales": {
      "meta": 12000, "meta_origen": "global", "meta_asignada": 300,
      "puestos": 2, "puestos_sin_meta": 1,
      "identificados": 80, "votos_reales": 60, "tiene_actas": true, "actas": 2,
      "avance_base": 0.67, "avance_real": 0.5,
      "faltante": 11940, "semaforo": "rojo", "base_del_semaforo": "real"
    },
    "cobertura": {
      "votos_reales_total": 60, "votos_reales_ubicados": 30,
      "votos_reales_sin_ubicar": 30, "actas_sin_ubicar": 1
    },
    "umbrales": { "verde": 90, "ambar": 70 },
    "aviso_base": "La base identificada … no es voto asegurado …"
  }
}
```

Con `nivel=municipio` la fila trae `departamento`, `municipio` y `puestos`
(cuántos agregó), sin `voting_place_id` ni `puesto`. Con `nivel=global` es **una**
fila, con `puestos` y `meta_origen`. Como en el cruce, un porcentaje redondo llega
como entero (`10`, no `10.0`).

El orden es **lo accionable arriba**: primero lo que más lejos está de su meta
(`faltante` descendente), lo que no tiene meta al final —no hay distancia que
medir— y dentro de cada grupo, el orden geográfico, que es estable entre llamadas.

### El total es el conteo real; el desglose, solo lo conciliado (0086)

`meta.totales.votos_reales` —y con él `avance_real`, `faltante`, `tiene_actas` y
`actas`— es el **conteo real** del candidato: **todas** las actas `procesada` del
evento, con la misma cuenta del consolidado
(`ConsolidadoService::totalDeMiCandidato()`, que reutiliza `votosPorCandidato` en
uninominal y `votosPorPreferente` por el par `(lista, preferente)` en
corporación). No es la suma de las filas.

Las **filas** (`data[]` de nivel puesto y municipio) siguen geo-filtradas por
`VotosService`: solo cuentan actas cuyo puesto ya casó con el catálogo, porque
una fila sin puesto no es una fila y el cruce necesita que votos y base se
encuentren en la misma llave. **Que la suma de las filas sea menor que el total
es el estado normal**, no un error: la diferencia son actas sin conciliar.

La fila de `nivel=global` **sí** lleva el conteo real: no es una fila por puesto,
es la cabecera en forma de fila, y si dijera la suma geo mientras
`meta.totales` dice el total, la misma pantalla se contradiría.

Antes de esta spec el total salía de la suma geo-filtrada y el tablero decía 50
donde el consolidado decía 411 — el número estrella de «¿voy ganando?» mentía por
defecto, y con meta 10.000 reclamaba 9.950 votos que no faltaban.

| Campo de `meta.cobertura` | Qué es |
| --- | --- |
| `votos_reales_total` | el conteo real: todas las `procesada` |
| `votos_reales_ubicados` | Σ de los votos de las filas por puesto |
| `votos_reales_sin_ubicar` | `max(0, total − ubicados)` — el gap, con nombre propio |
| `actas_sin_ubicar` | actas que cuadran y todavía no cuelgan de ningún puesto |

La cobertura se calcula sobre **toda** la campaña y no sobre lo filtrado, igual
que `actas_sin_conciliar` del cruce: un acta sin puesto no está en ningún
municipio, y esconderla al filtrar dejaría el gap invisible justo donde se mira.
Conciliar puestos es lo que mueve esos votos al desglose; hasta entonces el total
ya los cuenta.

**Con `municipio=` el total vuelve a ser la suma de lo ubicado ahí.** El ámbito
ya no es la campaña, y atribuirle a IBAGUÉ votos que no tienen puesto sería
inventar de dónde salieron. La cobertura sigue reportando el gap global.

Sin ninguna acta `procesada`, `votos_reales` es `null` y el avance se mide sobre
la base: la regla de la 0064 no cambia, solo **qué** se cuenta cuando hay actas.

### El semáforo es honesto o no sirve

| Campo | Regla |
| --- | --- |
| `avance_base` | `identificados / meta · 100`. `null` sin meta |
| `avance_real` | `votos_reales / meta · 100`. `null` sin meta **o** sin actas |
| `faltante` | `max(0, meta − (votos_reales ?? identificados))`. `null` sin meta |
| `semaforo` | `verde` si el avance ≥ `umbral_verde`; `ambar` si ≥ `umbral_ambar`; si no, `rojo`. Sin meta, `sin_meta` |
| `base_del_semaforo` | `real` donde hay actas, `base` donde no. `null` sin meta |

El semáforo se enciende **sobre los votos reales donde los hay** y sobre la base
donde no, y siempre dice cuál de las dos está mirando. Un verde sobre base
identificada y un verde sobre votos escrutados no significan lo mismo: la base es
el piso de trabajo de la campaña, **no voto asegurado** (0076) — puede rendir de
más o de menos. Un tablero que no los distinga le vende a la campaña una certeza
que no tiene, y por eso el aviso viaja en `meta.aviso_base`, junto al dato.

Los cortes son **inclusive por abajo**: llegar justo al umbral es cumplirlo.

`votos_reales` es `null` sin acta y `0` con acta: «todavía no se ha escrutado» no
es «mi candidato sacó cero». Es la misma distinción que `tiene_acta` en el cruce.

Casos borde: con meta `null` o `0` no hay avance ni faltante ni color
(`sin_meta`); identificar **más** que la meta no es un error —avance > 100 %,
faltante `0`, verde— pero sigue sin ser voto; y un puesto con meta y sin base ni
actas sale con avance `0` y en rojo, que es exactamente lo que hay que ver.

### De dónde sale cada número

| Número | Fuente | Spec |
| --- | --- | --- |
| `meta` | `electoral_events.meta_votos` + `e14_meta_puesto` | 0064 |
| `identificados` | `RegistradosService` (la base del cruce, `voters` ± `leads`) | 0076 |
| `votos_reales` de cada fila | `VotosService::deMiCandidato()`, actas `procesada` **con puesto** | 0062 / 0083 |
| `votos_reales` del total | `ConsolidadoService::totalDeMiCandidato()`, **todas** las `procesada` | 0086 |

**Corporación incluida, sin código propio.** Los votos reales salen del mismo
`VotosService` que ya bifurca por cargo: en concejo/asamblea/senado cuenta la fila
`(lista, preferente)` del E-14, nunca el preferente suelto de todas las listas. La
proyección no se entera de la diferencia.

Sin candidato propio configurado responde **422** pidiendo configurarlo, con el
mismo mensaje del cruce; en corporación hacen falta **las dos** mitades del par.

La lectura es siempre por puesto y se pliega después, así que las agregaciones son
**por evento y no por fila**: doce puestos cuestan lo mismo que uno.

Lo que la 0064 **no** hace: derivar la meta de histórico o censo, calcular cifra
repartidora o adjudicar curules (→ futuro), ni proyectar tendencias — «proyección»
aquí es meta vs base vs votos, no un modelo predictivo. Tampoco hay meta por
líder: nadie la fijó, y el corte por líder es el de la 0063.

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
    "candidato": {
      "numero": 2, "lista_numero": null,
      "nombre": "JOHANA ARANDA", "agrupacion": null,
      "es_corporacion": false
    },
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

---

## Corporación: concejo, senado, asamblea (Spec 0067)

Un acta de corporación no tiene candidatos: tiene **agrupaciones políticas**, y
dentro de cada una los votos por la lista y por cada candidato con voto
preferente. Son **dos niveles** y los dos hacen falta — el total de la agrupación
es la cifra con la que se reparten curules por cifra repartidora, y el del
preferente dice quién se las lleva dentro de la lista.

El lector (0067-A) transcribe las N páginas del PDF y las arma en un acta antes
de publicar; lo que llega aquí ya viene ensamblado.

### Lo que el papel enseñó

Tres cosas que la spec daba por supuestas y el acta real desmintió (transcrita
entera en 0067-A, `actas/concejo/zona_1_mesa001.pdf`, 13 páginas / 17 listas):

1. **No existe la casilla «SUMA TOTAL DE VOTOS DEL ACTA E-14».** El uninominal la
   tiene; la corporación, no. Por eso `suma_declarada` **no se exige ni se
   guarda** para estos tipos: se reporta en cero. Exigirla habría obligado al
   lector a inventarse una cifra, y compararla contra cero habría mandado a
   revisión todas las actas de concejo.
2. **Una hoja puede traer más de una lista** (cinco de las trece traen dos).
3. **Hay listas «SIN VOTO PREFERENTE»**: un único renglón, «VOTOS POR LA
   AGRUPACIÓN POLÍTICA», sin candidatos. Se guardan con ese voto en
   `votos_solo_lista` y `preferentes: []`, y `con_voto_preferente: false`.

Además, los números de preferencia **no son correlativos**: el acta salta los
renglones que la lista no llenó, así que una lista puede ir 1, 3, 5, 12, 19.

### El resultado anidado

Es la forma que aceptan **las dos** puertas de entrada: `POST /actas` (ingesta
directa) y `POST /actas/{id}/resultado` (worker).

```json
{
  "tipo": "concejo",
  "estado": "procesada",
  "zona": "01", "puesto": "01", "mesa": "001",
  "lugar": "UNIVERSIDAD COOPERATIVA NUEVA SEDE",
  "listas": [
    {
      "lista_numero": 29,
      "lista_nombre": "NUEVA FUERZA DEMOCRÁTICA",
      "con_voto_preferente": true,
      "votos_solo_lista": 0,
      "total_agrupacion": 3,
      "preferentes": [
        { "numero": 6, "votos": 1 },
        { "numero": 10, "votos": 1 },
        { "numero": 18, "votos": 1 }
      ]
    },
    {
      "lista_numero": 37,
      "lista_nombre": "MOVIMIENTO POLITICO FUERZA CIUDADANA",
      "con_voto_preferente": false,
      "votos_solo_lista": 1,
      "total_agrupacion": 1,
      "preferentes": []
    }
  ],
  "votos_blanco": 8,
  "votos_nulos": 3,
  "votos_no_marcados": 12,
  "votos_urna": 111,
  "votantes_e11": 111
}
```

| Campo | Regla |
| --- | --- |
| `listas` | **obligatorio** en un acta de corporación legible; `min:1` |
| `listas.*.lista_numero` | 1..99999, **distinto** dentro del acta. Cinco cifras porque las hay: 5170, 6497, 2642 |
| `listas.*.lista_nombre` | opcional |
| `listas.*.votos_solo_lista` | el renglón «0»; en una lista sin voto preferente, **su único voto** |
| `listas.*.total_agrupacion` | lo que declara «TOTAL AGRUPACIÓN POLÍTICA». Se guarda tal cual, sin recalcularlo |
| `listas.*.con_voto_preferente` | `false` para la maqueta de un solo renglón; por defecto `true` |
| `listas.*.preferentes.*.numero` | 1..999, distinto **dentro de su lista** — no entre listas |
| `listas.*.preferentes.*.votos` | entero ≥ 0 |
| `suma_declarada` | **no se exige**; si llega se acepta, pero el cuadre no la usa |
| `resultados` | **no se exige** en corporación (es la forma uninominal) |

> **El mismo número de preferencia en dos listas son dos personas.** Es el caso
> normal, no un error: la identidad es `(lista, número)`. Por eso la unicidad se
> valida *dentro* de cada lista, y no con un `distinct` global que rechazaría
> actas legítimas.

### El cuadre, en dos niveles

```
por lista:  votos_solo_lista + Σ preferentes = total_agrupacion
global:     Σ total_agrupacion + blanco + nulos + no_marcados = urna nivelada
```

El **self-check por lista** es lo que hace útil el error. Un acta de diecisiete
agrupaciones que no cuadra por un voto no le dice nada a quien la revise, así que
el motivo **nombra la lista**:

```
la lista 1 · PARTIDO LIBERAL COLOMBIANO declara 8 pero sus casillas suman 9
(solo lista 2 + preferentes 7)
```

Ya se ganó el sueldo: en la primera acta real que leyó el lector, la visión puso
un voto en un renglón vacío del liberal y el acta paró en `inconsistente`
nombrándolo, en vez de publicar una cifra inventada.

El **global** cuenta lo que cada agrupación *declara*, no lo recalculado desde
sus casillas: es la cifra que alguien tallaría del papel, y recalcularla taparía
justo el error que el self-check acaba de señalar. Y se contrasta **contra la
urna**, no contra una suma declarada que no existe.

La **nivelación** (`votantes_e11 − urna nivelada`) es una novedad, no un error, igual
que en uninominal. El guardia de **acta sin datos** (spec 0077) aplica al nivel
que toca: sin listas y con la urna en cero, `revision_manual` — `0 = 0` lo
cumpliría en silencio. Pero listas en cero **con** urna es `inconsistente`, no
vacía: son dos cosas distintas.

El servidor rehace esta cuenta con los números crudos aunque el lector ya la haya
hecho, igual que en uninominal: el `estado` decide qué votos entran al
consolidado y no puede depender de que el cliente lo calcule bien.

### El detalle del acta

`GET /actas/{id}` devuelve `listas[]` anidadas, con `suma_calculada` por lista
para que el panel pueda resaltar la que no cuadra sin rehacer la cuenta:

```json
{
  "data": {
    "tipo": "concejo",
    "estado": "inconsistente",
    "observacion": "la lista 1 · PARTIDO LIBERAL COLOMBIANO declara 8 pero sus casillas suman 9 (solo lista 2 + preferentes 7)",
    "resultados": [],
    "listas": [
      {
        "lista_numero": 1,
        "lista_nombre": "PARTIDO LIBERAL COLOMBIANO",
        "votos_solo_lista": 2,
        "total_agrupacion": 8,
        "con_voto_preferente": true,
        "suma_calculada": 9,
        "preferentes": [{ "numero": 3, "votos": 1 }]
      }
    ]
  }
}
```

En un acta uninominal `listas` viene vacía, y `resultados` en una de corporación:
el `tipo` dice cuál mirar.

### `GET /consolidado?tipo=concejo`

Dos cortes del mismo escrutinio, juntos en la misma respuesta porque los dos
hacen falta:

```json
{
  "data": [
    { "lista_numero": 1,  "lista_nombre": "PARTIDO LIBERAL COLOMBIANO", "votos": 10 },
    { "lista_numero": 11, "lista_nombre": "PARTIDO CENTRO DEMOCRÁTICO", "votos": 20 },
    { "lista_numero": 24, "lista_nombre": "PARTIDO DEMÓCRATA COLOMBIANO", "votos": 0 }
  ],
  "preferentes": [
    { "lista_numero": 1,  "lista_nombre": "PARTIDO LIBERAL COLOMBIANO", "numero": 1, "votos": 8 },
    { "lista_numero": 11, "lista_nombre": "PARTIDO CENTRO DEMOCRÁTICO", "numero": 1, "votos": 10 },
    { "lista_numero": 11, "lista_nombre": "PARTIDO CENTRO DEMOCRÁTICO", "numero": 5, "votos": 6 }
  ],
  "meta": {
    "actas": { "procesada": 2, "inconsistente": 0, "revision_manual": 0 },
    "votos_blanco": 6, "votos_nulos": 2, "votos_no_marcados": 2,
    "total_listas": 30,
    "total_votos": 40
  }
}
```

- `data` va **por lista**; `preferentes`, por **(lista, número)**.
- Los preferentes **no traen nombre**: el acta no lo imprime. Una columna vacía
  invitaría a rellenarla a mano con lo que alguien recuerde.
- Una lista sin votos **sigue apareciendo**: omitirla convertiría «cero votos» en
  «no se presentó», que no es lo mismo para quien lo lee.
- Solo entran actas `procesada`, igual que en uninominal.
- `meta.total_listas` sustituye a `total_candidatos`; el resto de `meta` es igual.

El consolidado uninominal **no cambia**: sigue devolviendo `data` por candidato,
`meta.total_candidatos` y su `desglose`, y sin bloque `preferentes`.

### Esquema

```
e14_lista_resultados
  id, tenant_id, e14_acta_id → e14_actas (cascade),
  lista_numero (unsignedInteger), lista_nombre (nullable),
  votos_solo_lista, total_agrupacion, con_voto_preferente (bool), timestamps
  Único: (e14_acta_id, lista_numero)

e14_lista_preferentes
  id, tenant_id, e14_acta_id → e14_actas (cascade),
  e14_lista_resultado_id → e14_lista_resultados (cascade),
  numero (unsignedSmallInteger), votos, timestamps
  Único: (e14_lista_resultado_id, numero)
```

**`e14_resultados` no se tocó.** La spec ofrecía meter los preferentes ahí con un
`lista_numero` nullable, y se descartó por dos razones de corrección:

1. Esa tabla tiene un único `(e14_acta_id, numero)` que protege al uninominal de
   contar dos veces al mismo candidato. En corporación ese par **no** es único
   —el preferente 1 existe en todas las listas—, así que habría habido que
   ampliar el índice con una columna **nula**; y un índice único con NULL deja de
   proteger las filas uninominales en PostgreSQL, donde dos NULL se consideran
   distintos. Habríamos cambiado un problema de corporación por un agujero en el
   uninominal.
2. `e14_resultados.e14_candidate_id` apunta al tarjetón (`e14_candidates`), único
   por `(evento, cargo, numero)`. En corporación el 5 de una lista y el 5 de otra
   son dos personas: ese catálogo las fundiría en una. Y como el acta no trae
   nombres, no hay nada que catalogar — el catálogo de corporación, si se quiere,
   es cosa de la 0082.

Los preferentes cuelgan de la lista y no del acta porque un preferente no existe
sin su lista: así el único `(lista, numero)` es natural y el borrado en cascada
sale gratis. `tenant_id` y `e14_acta_id` van denormalizados para consolidar sin
encadenar joins y para que `TenantScope` filtre directo.

> **Resuelto en 0083:** `GET /cruce` y `GET /rendimiento-lideres` cuentan la fila
> `(candidato_propio_lista_numero, candidato_propio_numero)` sobre
> `e14_lista_preferentes`. Hasta entonces leían `e14_resultados.numero`, que es la forma
> uninominal, y un tenant de concejo con su candidato bien configurado veía el panel en
> ceros. Ver «Cómo se cuenta cada lado».


---

## Esquema

### `electoral_events`
`id`, `tenant_id`, `nombre`, `fecha`, `tipo`, `candidato_propio_numero`,
`candidato_propio_lista_numero`, `candidato_propio_nombre`,
`candidato_propio_agrupacion`, `meta_votos`, timestamps.
Único: `(tenant_id, tipo, nombre)`.

Las columnas de candidato propio son de la 0062 y son nullable: una elección recién
creada por la ingesta todavía no sabe de quién es la campaña.

Desde la 0080 **solo la elección del cargo del tenant las usa**: el candidato es uno por
campaña y su nombre se deriva de `tenants.nombre`. Las columnas no se movieron —el
cruce, el déficit y el rendimiento las leen aquí— y `e14:candidato-unico` deja en NULL
las de cualquier otra elección.

`candidato_propio_lista_numero` la añadió la 0082 para corporación. Es **nullable y sin
defecto** a propósito: `null` significa literalmente «este candidato no está dentro de
ninguna lista», que es lo que pasa en uninominal, y de eso depende que el cruce siga
leyendo `candidato_propio_numero` como siempre. El preferente se guarda en esa misma
columna en vez de añadir una segunda de número: es el mismo concepto —cuál de las filas
del acta es la mía— contado al nivel que corresponda, y duplicarlo obligaría a preguntar
cuál de las dos vale en cada lectura.

`meta_votos` la añadió la 0064: la meta global de votos, **nullable y sin
defecto**. `null` es «todavía no la fijaron», que no es una meta de cero — un `0`
por defecto habría pintado de rojo a toda campaña recién creada.

### `e14_meta_puesto` (Spec 0064)
`id`, `tenant_id`, `electoral_event_id`, `voting_place_id`, `meta_votos`,
timestamps.
Único: `(electoral_event_id, voting_place_id)`.

El override de la meta por puesto. **Que exista la fila es la meta**: no hay
estado «cero por defecto», y quitarla borra el renglón. No hay tabla análoga para
municipio ni zona — esas metas se agregan de sus puestos.

### `e14_actas_rechazadas` (Spec 0093)
`id`, `tenant_id`, `electoral_event_id?`, `archivo_nombre`, `archivo_hash`,
`eleccion_detectada`, `eleccion_esperada`, `motivo`, `created_at`.
Índice: `(tenant_id, created_at)`. Único: `(tenant_id, archivo_hash)`.

La constancia de un acta que se borró por no ser de esta elección. No apunta a
`e14_actas` —el acta ya no existe— y por eso copia el nombre y el hash del
archivo en vez de referenciarlos. **Sin PII**: el acta rechazada nunca se leyó,
así que aquí no hay mesa ni cifras. Solo `created_at`: un rechazo es un hecho con
fecha, no una fila que se edite.

El único por `(tenant_id, archivo_hash)` es lo que hace idempotente el rechazo:
el mismo PDF vuelto a cargar y vuelto a rechazar actualiza su renglón. El hash
puede ser nulo —un acta sin archivo— y varios nulos no colisionan ni en
PostgreSQL ni en SQLite, así que esos casos conviven.

### `e14_candidates`
`id`, `tenant_id`, `electoral_event_id`, `numero`, `nombre`, `agrupacion`,
`cargo`, timestamps.
Único: `(electoral_event_id, cargo, numero)`.

### `e14_actas`
`id`, `tenant_id`, `electoral_event_id`, `tipo`, `departamento_code`,
`departamento`, `municipio_code`, `municipio`, `zona`, `puesto`, `mesa`,
`lugar`, `voting_place_id`, `archivo_nombre`,
`archivo_hash`, `upload_batch_id`, `archivo_path`, `estado`, `suma_calculada`,
`suma_declarada`, `votos_urna`, `votos_incinerados` (entera, default `0`,
Spec 0088), `votantes_e11`, `dif_nivelacion`,
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

Es la forma **uninominal**: un candidato por renglón. La 0067 no la tocó — los
votos de corporación viven en sus dos tablas propias, y el porqué está en
[Corporación · Esquema](#esquema-1).

### `e14_lista_resultados` (Spec 0067)
`id`, `tenant_id`, `e14_acta_id`, `lista_numero`, `lista_nombre`,
`votos_solo_lista`, `total_agrupacion`, `con_voto_preferente`, timestamps.
Único: `(e14_acta_id, lista_numero)`.

Una agrupación política en una mesa: el primer nivel del resultado de
corporación. `lista_numero` es `unsignedInteger` y no un smallint porque las hay
de cinco cifras (5170, 6497, 2642 en la muestra real).

### `e14_lista_preferentes` (Spec 0067)
`id`, `tenant_id`, `e14_acta_id`, `e14_lista_resultado_id`, `numero`, `votos`,
timestamps.
Único: `(e14_lista_resultado_id, numero)`.

El segundo nivel: los votos por candidato dentro de su lista. **Sin columna de
nombre** — el E-14 de corporación solo imprime el número de preferencia, y la
identidad que necesitan el consolidado y el cruce es `(lista, número)`.

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
| `E14_UMBRAL_MOVILIZADOS` | `20` | desde cuánta gente identificada la movilización de un líder es «mucha» (0063) |
| `E14_UMBRAL_DEFICIT_PONDERADO` | `30` | puntos de déficit ponderado que marcan «posible inflado» (0063) |
| `E14_UMBRAL_VERDE` | `90` | % de avance desde el que la proyección pinta verde (0064) |
| `E14_UMBRAL_AMBAR` | `70` | % de avance desde el que pinta ámbar; por debajo, rojo (0064) |

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

Y desde la 0093 el **tipo de elección** también sale del tenant, nunca del
cliente: un `?tipo=` ajeno no cambia la respuesta y un `?event=` de otra elección
—aunque sea del mismo tenant— responde 422. `GET /rechazos` va acotado por
`TenantScope` como todo lo demás.
