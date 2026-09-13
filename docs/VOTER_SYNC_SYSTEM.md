# Sincronización de votantes: asistentes y Registraduría

## 📋 Resumen

`voters` es la tabla oficial de electores. Se alimenta por **tres** vías:

| Vía | Qué escribe | Dónde |
| --- | --- | --- |
| Check-in de reunión | crea o liga la persona por cédula | `App\Services\AttendanceService` (Spec 0022) |
| Comando programado | recorre `meeting_attendees` y rellena huecos | `voters:sync`, dos veces al día |
| Consulta de Registraduría | escribe el puesto de votación | cola de salida al servicio Python, ver abajo (Spec 0091) |

> **Actualizado por la Spec 0022.** La sincronización asistente → votante ya no
> vive en `MeetingAttendeeObserver` con su propia copia de la lógica: el observer
> delega en `AttendanceService`, que normaliza la cédula (sin puntos ni espacios)
> antes de comparar. Antes no lo hacía, y «71.000.001» y «71000001» acababan como
> dos votantes distintos. Lo que sigue describiendo este documento —qué campos se
> rellenan y cuándo se marca `has_multiple_records`— se conserva igual.

> **Actualizado por la Spec 0091.** La tercera vía dejó de ser un par de
> webhooks públicos que n8n llamaba: ahora es Laravel quien llama de salida al
> servicio de scraping, en cola. Las rutas de n8n ya no existen.

> **Contrato observado.** Las pruebas de caracterización de los webhooks
> (Spec 0011) se fueron con ellos en la 0091. El flujo que los reemplaza está
> cubierto por `tests/Unit/Services/Registraduria/` y
> `tests/Feature/Voters/ConsultarPuestoVotacionJobTest.php`.

---

## 🔄 Funcionamiento Automático

### Cuando se crea un asistente:

1. **Si el elector NO existe** (por cédula):
   - ✅ Se crea un nuevo registro en `voters`
   - ✅ Se copia toda la información: nombres, apellidos, email, teléfono, dirección, barrio
   - ✅ Se guarda la primera reunión donde se registró (`meeting_id`)

2. **Si el elector YA existe** (mismo `cedula` y `tenant_id`):
   - ✅ Se actualizan solo los campos vacíos en `voters`
   - ✅ Si hay conflictos (datos diferentes), se marca con `has_multiple_records = true`
   - ⚠️ No se sobrescribe información existente

### Cuando se actualiza un asistente:

- Se ejecuta la misma lógica de sincronización
- Se actualizan campos vacíos en `voters`
- Se detectan y marcan conflictos

### Cuando se elimina un asistente:

- ❌ NO se elimina el elector de `voters`
- El elector es el registro oficial y puede tener múltiples asistencias

---

## 🎯 Lógica de Actualización

### Campos que se actualizan (solo si están vacíos en voters):

```php
- email
- telefono
- direccion
- barrio_id
```

### Detección de Conflictos

Si un campo en `voters` tiene un valor y el nuevo `attendee` tiene un valor diferente, se marca:

```php
has_multiple_records = true
```

**Ejemplo de conflicto:**
```
Voter existente:
- cedula: 123456
- email: juan@email.com
- telefono: 3001234567

Nuevo attendee:
- cedula: 123456
- email: juan.perez@otro.com  ❌ Diferente
- telefono: 3109876543         ❌ Diferente

Resultado:
- No se sobrescribe nada
- has_multiple_records = true
```

---

## 🗂️ Estructura de Tablas

### Tabla `voters` (Tabla Oficial)

```sql
- id
- tenant_id
- cedula (único por tenant)
- nombres
- apellidos
- email
- telefono
- direccion
- barrio_id
- corregimiento_id
- vereda_id
- meeting_id (primera reunión donde se registró)
- departamento_votacion
- municipio_votacion
- puesto_votacion
- direccion_votacion
- mesa_votacion
- has_multiple_records (flag de conflictos)
- created_by
- created_at
- updated_at
- deleted_at
```

### Tabla `meeting_attendees`

```sql
- id
- tenant_id
- meeting_id
- cedula
- nombres
- apellidos
- direccion
- telefono
- email
- barrio_id
- extra_fields (JSON)
- checked_in
- checked_in_at
- created_by
- created_at
- updated_at
```

---

## 🔧 Comando de Sincronización Manual

Para sincronizar asistentes existentes a `voters`:

### Sincronizar todos los asistentes:
```bash
php artisan voters:sync-attendees
```

### Sincronizar solo un tenant específico:
```bash
php artisan voters:sync-attendees --tenant-id=1
```

### Output del comando:
```
Starting sync of meeting attendees to voters...
Found 1,234 attendees to process
████████████████████████████████ 100%

Sync completed!
+----------+-------+
| Action   | Count |
+----------+-------+
| Created  | 850   |
| Updated  | 250   |
| Skipped  | 134   |
| Errors   | 0     |
| Total    | 1,234 |
+----------+-------+
```

---

## 📊 Logs del Sistema

El sistema registra todas las sincronizaciones:

### Al crear nuevo voter:
```
[INFO] New voter created from meeting attendee
{
  "cedula": "123456789",
  "nombres": "Juan",
  "apellidos": "Pérez",
  "meeting_id": 45
}
```

### Al actualizar voter existente:
```
[INFO] Voter updated from meeting attendee
{
  "voter_id": 123,
  "cedula": "123456789",
  "changes": ["email", "telefono"],
  "has_conflicts": false
}
```

### Al detectar conflictos:
```
[INFO] Voter updated from meeting attendee
{
  "voter_id": 123,
  "cedula": "123456789",
  "changes": ["has_multiple_records"],
  "has_conflicts": true
}
```

### Si hay error:
```
[ERROR] Error syncing attendee to voters
{
  "attendee_id": 567,
  "cedula": "987654321",
  "error": "Foreign key constraint failed"
}
```

---

## 🔍 Consultas Útiles

### Ver voters con múltiples registros (conflictos):
```sql
SELECT * FROM voters WHERE has_multiple_records = true;
```

### Ver voters creados desde una reunión específica:
```sql
SELECT * FROM voters WHERE meeting_id = 45;
```

### Contar voters por barrio:
```sql
SELECT b.nombre, COUNT(*) as total
FROM voters v
JOIN barrios b ON v.barrio_id = b.id
GROUP BY b.id, b.nombre
ORDER BY total DESC;
```

### Ver asistentes sin voter (no debería haber):
```sql
SELECT ma.*
FROM meeting_attendees ma
LEFT JOIN voters v ON ma.cedula = v.cedula AND ma.tenant_id = v.tenant_id
WHERE v.id IS NULL;
```

---

## 🚨 Consideraciones Importantes

1. **Cédula como clave única**: La cédula debe ser única por tenant en `voters`
2. **No se eliminan voters**: Aunque se elimine un asistente, el voter permanece
3. **Actualización conservadora**: Solo se actualizan campos vacíos, no se sobrescribe
4. **Detección de conflictos**: `has_multiple_records` indica que hay datos inconsistentes
5. **Primera reunión**: `meeting_id` siempre guarda la primera reunión donde se registró

---

## 🧪 Testing

### Caso 1: Crear primer asistente
```php
$attendee = MeetingAttendee::create([
    'tenant_id' => 1,
    'meeting_id' => 10,
    'cedula' => '123456789',
    'nombres' => 'Juan',
    'apellidos' => 'Pérez',
    'email' => 'juan@email.com',
    'telefono' => '3001234567',
]);

// Verificar que se creó el voter
$voter = Voter::where('cedula', '123456789')->first();
assert($voter !== null);
assert($voter->email === 'juan@email.com');
assert($voter->meeting_id === 10);
```

### Caso 2: Crear segundo asistente (misma cédula)
```php
$attendee2 = MeetingAttendee::create([
    'tenant_id' => 1,
    'meeting_id' => 20,
    'cedula' => '123456789',
    'nombres' => 'Juan',
    'apellidos' => 'Pérez',
    'email' => 'juan.nuevo@email.com', // Email diferente
    'telefono' => '3109876543',        // Teléfono diferente
]);

// Verificar que NO se sobrescribió
$voter = Voter::where('cedula', '123456789')->first();
assert($voter->email === 'juan@email.com'); // Email original
assert($voter->has_multiple_records === true); // Marcado con conflicto
assert($voter->meeting_id === 10); // Primera reunión
```

### Caso 3: Actualizar asistente con info adicional
```php
$attendee = MeetingAttendee::where('cedula', '123456789')->first();
$attendee->update([
    'direccion' => 'Calle 10 # 20-30', // Campo que estaba vacío
]);

// Verificar que se actualizó en voter
$voter = Voter::where('cedula', '123456789')->first();
assert($voter->direccion === 'Calle 10 # 20-30');
```

---

## 🔄 Flujo Completo

```
┌──────────────────────┐
│ Crear/Actualizar     │
│ MeetingAttendee      │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│ Observer detecta     │
│ evento created/      │
│ updated              │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│ Buscar Voter por     │
│ cedula + tenant_id   │
└──────────┬───────────┘
           │
      ┌────┴────┐
      │         │
  Existe?    No existe
      │         │
      ▼         ▼
┌─────────┐ ┌──────────┐
│Actualizar│ │ Crear    │
│(campos  │ │ nuevo    │
│vacíos)  │ │ Voter    │
└─────────┘ └──────────┘
      │         │
      └────┬────┘
           │
           ▼
┌──────────────────────┐
│ Detectar conflictos  │
│ y marcar si hay      │
└──────────────────────┘
```

---

## 📝 Checklist de Implementación

- [x] Observer `MeetingAttendeeObserver` creado
- [x] Lógica de sincronización en eventos `created` y `updated`
- [x] Detección de conflictos (`has_multiple_records`)
- [x] Actualización conservadora (solo campos vacíos)
- [x] Comando artisan `voters:sync-attendees`
- [x] Logging de sincronizaciones
- [x] Documentación completa
- [x] Observer registrado en `AppServiceProvider`

---

## 🆘 Troubleshooting

### Problema: Voter no se creó
**Verificar:**
1. ¿El observer está registrado en `AppServiceProvider`?
2. ¿Hay errores en logs? `storage/logs/laravel.log`
3. ¿La cédula es única por tenant?

### Problema: Datos no se actualizan
**Causa probable:** El campo en `voters` ya tiene un valor
**Solución:** La actualización es conservadora, solo rellena campos vacíos

### Problema: has_multiple_records = true
**Causa:** Se detectaron datos conflictivos (email, teléfono o barrio diferentes)
**Acción:** Revisar manualmente el registro y unificar información

---

## 🔗 Archivos Relacionados

- `app/Observers/MeetingAttendeeObserver.php` - Observer principal
- `app/Console/Commands/SyncAttendeesToVoters.php` - Comando de sincronización
- `app/Providers/AppServiceProvider.php` - Registro del observer
- `app/Models/MeetingAttendee.php` - Modelo de asistentes
- `app/Models/Voter.php` - Modelo de electores


---

## El puesto de votación del votante (`voting_place_id`)

Spec 0075. `voters.voting_place_id` es la **llave del cruce** con el escrutinio
E-14 (Spec 0062): el acta y el votante tienen que resolver al **mismo** renglón de
`voting_places`, porque es lo único que los dos lados comparten (el votante no trae
código de puesto y el acta no trae el nombre tal como lo teclea la campaña).

Los tres caminos de escritura del votante pasan por el mismo
`App\Services\E14\PuestoResolver`, con una asimetría deliberada:

| Camino | Qué hace | Por qué |
| --- | --- | --- |
| Consulta de Registraduría (`RegistraduriaSyncService`, Spec 0091) | `resolverRegistraduria()` — **find-or-create** normalizado | es el censo oficial de dónde vota esa persona: si el puesto no está en el catálogo, lo que falta es el renglón (igual que el acta E-14) |
| `POST /voters` (alta manual) | `buscarPorNombre()` — **solo busca** | un nombre tecleado en un formulario no da de alta un puesto en el catálogo **global**; si no resuelve queda nulo y lo recoge la conciliación de la 0062 |
| `PUT/PATCH /voters/{id}` | `buscarPorNombre()` — **solo busca**, y recalcula | el id es función de la ubicación con la que **queda** el votante |

Reglas que valen para los tres:

- **Normalizado**: insensible a mayúsculas, acentos y espacios de más, y con los
  **alias** (fusiones) del tenant por delante del catálogo. Nada de puntuación ni
  abreviaturas: «COL.» no es «COLEGIO» hasta que alguien lo fusione a mano.
- `voting_place_id` **nunca** se acepta del cliente: no está en las reglas de
  `Store/UpdateVoterRequest` y se deriva siempre en el servidor de
  `municipio_votacion` + `puesto_votacion`.
- **La edición resuelve sobre la ubicación efectiva.** Un `PATCH` que solo cambia
  el puesto resuelve con el municipio que el votante ya tenía; vaciar el municipio
  o el puesto sí deja el id en **nulo** —un puesto que ya no corresponde a la
  ubicación no se conserva.
- `mesa_votacion` **no** influye: la llave es a nivel de **puesto**.
- Sin N+1: el resolver va inyectado y carga catálogo y alias **una vez por
  petición** (`refrescar()` al inicio de la escritura).

---

## Consulta de Registraduría (Spec 0091)

Cuando un votante nace **sin puesto de votación**, Laravel llama de salida a
`platform-politics-registraduria` —un servicio FastAPI que scrapea la
Registraduría— y escribe el resultado. Es *push* y bajo demanda: el dato llega
poco después del check-in, no en el siguiente lote nocturno.

**Hasta la Spec 0091 esto lo hacía n8n al revés**: preguntaba por los pendientes
(`GET .../registraduria/pendientes`) y escribía de vuelta
(`POST .../registraduria/actualizar`), autenticándose con un secreto por tenant.
Esas dos rutas, su middleware, el comando `registraduria:secret` y la columna
`tenants.registraduria_secret_hash` **ya no existen**. Ver «Historia» al final.

### El camino completo

```
Nace un votante sin puesto
   │  (check-in de reunión / alta manual)
   ▼
ConsultarPuestoVotacionJob::despacharSiFalta()      ← punto único de encolado
   │  ¿hay servicio configurado? ¿le falta el puesto?
   ▼  cola
ConsultarPuestoVotacionJob
   │  enlaza current_tenant_id · guarda de idempotencia
   ▼
RegistraduriaClient::consultar(cedula)              → POST /api/consultar
   │
   ├─ encontrado   → RegistraduriaSyncService::aplicar()  → PuestoResolver → voters
   ├─ no_encontrado→ termina bien, sin reintentar
   └─ fallo        → excepción → la cola reintenta (60 s, 300 s)
```

### Configuración

```env
REGISTRADURIA_SERVICE_URL=http://127.0.0.1:8100
REGISTRADURIA_SERVICE_TOKEN=<el API_TOKEN del .env de ese servicio>
REGISTRADURIA_SERVICE_TIMEOUT=320
```

**Sin `REGISTRADURIA_SERVICE_URL` la integración está apagada**: no se encola
nada y nadie sale a la red. Es el estado por defecto y el de la suite de pruebas
(que corre la cola en `sync`). El techo de 320 s cubre el peor caso del servicio
—120 s del intento gratis más 180 s del intento con 2Captcha— con margen.

El servicio se levanta en su propio repo:

```bash
uvicorn app:app --host 127.0.0.1 --port 8100
```

Necesita **un worker de cola corriendo** (`php artisan queue:work`), o los Jobs
se quedan encolados sin ejecutarse.

### Las piezas

| Clase | Qué hace |
| --- | --- |
| `App\Services\Registraduria\RegistraduriaClient` | Único sitio que conoce el contrato del servicio Python (URL, token, claves en mayúsculas). Devuelve un `ResultadoConsulta` con los datos ya traducidos a columnas de `voters`. |
| `App\Services\Registraduria\ResultadoConsulta` | Los tres desenlaces, distinguidos: `esEncontrado()`, `esNoEncontrado()`, `esFallo()`. |
| `App\Services\Registraduria\RegistraduriaSyncService` | Escribe los cinco campos + `voting_place_id` vía `PuestoResolver`. Es la lógica que vivía en `VoterController@actualizarRegistraduria`. |
| `App\Jobs\Registraduria\ConsultarPuestoVotacionJob` | Orquesta: enlaza tenant, comprueba idempotencia, consulta, aplica. |
| `App\Console\Commands\ConsultarPuestosVotantes` | Backfill `voters:consultar-puestos`. |

### Contrato del servicio Python

`POST /api/consultar`, con `Authorization: Bearer <token>`:

```json
{ "documento": "14398737" }
```

```json
{ "estado": "encontrado", "via": "stealth",
  "datos": [ { "NUIP": "...", "DEPARTAMENTO": "TOLIMA", "MUNICIPIO": "IBAGUE",
               "PUESTO": "COLEGIO SAN SIMON", "DIRECCION": "...", "MESA": "12" } ] }
```

| `estado` | HTTP | Cómo lo trata Laravel |
| --- | --- | --- |
| `encontrado` | 200 | escribe el puesto |
| `no_encontrado` | 200 | **no es fallo**: termina bien y no reintenta |
| `captcha_fallido` | 502 | fallo → reintenta con backoff |
| `error` | 502 / 504 | fallo → reintenta con backoff |
| — | 401 | fallo (token mal configurado) |

`via` (`stealth` \| `2captcha`) es telemetría de coste: dice qué camino resolvió.

### Por qué `no_encontrado` no se reintenta

Cada consulta puede acabar pagando un reCAPTCHA en 2Captcha. Que la
Registraduría diga «no tengo esa cédula» es una **respuesta legítima**, no un
error de transporte: reintentarla es pagar por volver a oír lo mismo. El votante
se queda sin puesto y solo se vuelve a intentar si alguien corre el backfill.

### Idempotencia — dónde se decide consultar

La regla vive en **un solo sitio**, `ConsultarPuestoVotacionJob::despacharSiFalta()`,
y se comprueba **dos veces**: al encolar y otra vez dentro del Job, porque entre
una cosa y la otra el puesto pudo llegar por otra vía (un acta, una edición a
mano, o el Job gemelo de dos check-in simultáneos de la misma cédula).

Un votante «tiene puesto» si `departamento_votacion` no está vacío **o**
`voting_place_id` no es nulo. Con cualquiera de los dos, la consulta no aportaría
nada.

### Dónde se engancha el encolado

| Vía | Dónde |
| --- | --- |
| Check-in público de reunión y alta de asistentes desde el panel (Spec 0022) | `AttendanceService::crearVotante()` — el punto por donde nacen **todos** los votantes de ese flujo, incluido el que crea `MeetingAttendeeObserver` |
| Alta manual del votante | `VoterController@store` |

No está repartido por cada copia de la lógica: son los dos únicos sitios donde
nace un votante por acción de un usuario.

### Multi-tenant (Art. III)

En la cola **no hay petición** que enlace `current_tenant_id`, así que lo enlaza
el Job antes de tocar datos y lo restaura al salir (el worker es un proceso
largo: dejarlo puesto convertiría el ámbito de un trabajo en el del siguiente).

Por eso el Job lleva **el id y el tenant, no el modelo**: con `SerializesModels`,
el votante se recargaría en el worker sin ámbito y `TenantScope` no filtraría.
Al buscarlo dentro del ámbito enlazado, un id de otra campaña sencillamente no
aparece. Lo mismo vale para `PuestoResolver`, que sin tenant enlazado usaría los
**alias de la campaña equivocada**.

### Seguridad (Art. VII)

- La **cédula no va a los logs en claro**: se enmascara (`14****37`), igual que
  en el servicio Python. Tampoco entra en los mensajes de excepción del Job.
- `REGISTRADURIA_SERVICE_TOKEN` solo por `.env`. La clave de 2Captcha vive
  únicamente en el `.env` del servicio Python; Laravel nunca la ve.
- El servicio escucha en `127.0.0.1`: expóngalo solo a la red donde vive el
  backend.
- La respuesta no trae más PII que la ubicación del puesto, y `NUIP` **no** se
  escribe de vuelta: la identidad del votante no la fija un scraper.

### Cambios de comportamiento respecto a n8n

- **`mesa_votacion` «12A» ya no se pierde.** El viejo webhook la validaba como
  entero (`nullable|integer`) mientras la columna siempre fue `string(20)`, así
  que rechazaba la petición entera. Al retirar el webhook se retira esa
  validación. (Cierra el punto abierto en `known-issues.md`.)
- **El departamento ya no es obligatorio.** Sin él el resolver no puede dar de
  alta un renglón nuevo —es parte de la clave natural del catálogo—, pero sí
  casar con uno existente. Antes la validación cortaba la petición entera.
- **Ya no hay superficie pública.** No queda ninguna ruta que autenticar por
  secreto de tenant.

### Historia — qué se cerró y por qué

Hasta la Spec 0030 las dos rutas de n8n estaban **fuera del grupo `jwt.auth`**,
sin token, sin firma y sin `throttle`, y consultaban con
`withoutGlobalScope(TenantScope::class)`:

1. `pendientes` **repartía hasta 100 cédulas de cualquier campaña** a quien
   supiera la URL. (El agujero que cerró la Spec 0026 exigía conocer ya la
   cédula; este las entregaba.)
2. `actualizar` **escribía** el puesto de votación en el votante de cualquier
   tenant.
3. Y devolvía `data => $voter->fresh()`, el modelo **completo**: nombres,
   apellidos, correo, teléfono, dirección y `tenant_id`.

Encadenando (1) → (3) se podía vaciar la base de votantes de **todas** las
campañas sin autenticarse. Lo caracterizó la Spec 0011 y lo cerró la 0030 con un
secreto por tenant. La Spec 0091 retira las rutas enteras: una superficie pública
que escribe en `voters` deja de ser un riesgo cuando deja de existir.

La Spec 0075, por su parte, quitó a esa ruta su `VotingPlace::firstOrCreate`
sobre el texto **crudo**, que era el cabo suelto de la 0062: dos grafías del mismo
colegio creaban **dos** renglones, el acta apuntaba al canónico y el votante al
duplicado, y el cruce por `voting_place_id` fallaba **en silencio** —peor que un
nulo, porque el resolver de respaldo por nombre solo rescata los nulos—. Ese
invariante sobrevive intacto en `RegistraduriaSyncService`.

Pruebas: `tests/Unit/Services/Registraduria/` (cliente y guardado),
`tests/Feature/Voters/ConsultarPuestoVotacionJobTest.php` (idempotencia y
aislamiento), `EncoladoConsultaPuestoTest.php` (cuándo se encola),
`RetiroWebhooksRegistraduriaTest.php` (que el retiro no se deshaga) y
`tests/Feature/Console/ConsultarPuestosVotantesTest.php` (backfill).

---

## Comandos de consola

| Comando | Clase | Programado |
| --- | --- | --- |
| `voters:consultar-puestos {--tenant=} {--limit=}` | `ConsultarPuestosVotantes` | no (manual, Spec 0091) |
| `voters:sync {--tenant=}` | `SyncVotersFromAttendees` | sí, `twiceDaily(6, 18)` en `routes/console.php` |
| `voters:sync-attendees {--tenant-id=}` | `SyncAttendeesToVoters` | no |
| `voters:reapuntar-voting-place {--tenant=}` | `ReapuntarVotingPlaceVotantes` | no (manual, Spec 0075) |

`voters:consultar-puestos` **encola** una consulta de Registraduría por cada
votante sin puesto. Reemplaza al `GET .../registraduria/pendientes` de n8n: el
disparo normal ya es automático al nacer el votante, así que esto es la red de
seguridad —los votantes anteriores a la Spec 0091 y los que se quedaron sin
resolver mientras el servicio estuvo caído más de lo que duran los reintentos—.

`--limit` es un **tope de gasto de toda la corrida**, no una paginación ni un
límite por campaña: cada consulta puede acabar pagando un reCAPTCHA. Es
idempotente (no encola a quien ya tiene puesto), así que una segunda corrida no
repite a los ya resueltos. Sin servicio configurado **falla** en vez de encolar
en silencio trabajos que solo pueden fallar.


`voters:reapuntar-voting-place` re-resuelve `voters.voting_place_id` con el resolver
normalizado, corrigiendo los duplicados que dejó el `firstOrCreate` crudo anterior a
la 0075 —un id equivocado **no-nulo** no lo rescata el resolver de respaldo, que
solo mira los nulos—. Es autoritativo como el webhook (da de alta el renglón que
falte, con departamento) e **idempotente**: escribe solo cuando el id cambia, así
que la segunda corrida no toca nada y sobre una base recién sembrada no hace nada.

Recorre **campaña por campaña** enlazando `current_tenant_id`: en consola no hay
petición que lo enlace, y sin él ni `TenantScope` acotaría los votantes ni el
resolver usaría los alias del tenant correcto (Art. III).

⚠️ Los dos de sincronización hacen casi lo mismo. Solo `voters:sync` está
programado; a `voters:sync-attendees` no lo invoca nadie.
