# Sincronización de votantes: asistentes y Registraduría

## 📋 Resumen

`voters` es la tabla oficial de electores. Se alimenta por **tres** vías:

| Vía | Qué escribe | Dónde |
| --- | --- | --- |
| Check-in de reunión | crea o liga la persona por cédula | `App\Services\AttendanceService` (Spec 0022) |
| Comando programado | recorre `meeting_attendees` y rellena huecos | `voters:sync`, dos veces al día |
| Consulta a Registraduría | escribe el puesto de votación | cola: `ConsultarPuestoVotacionJob` (Spec 0091), ver abajo |

> **Actualizado por la Spec 0022.** La sincronización asistente → votante ya no
> vive en `MeetingAttendeeObserver` con su propia copia de la lógica: el observer
> delega en `AttendanceService`, que normaliza la cédula (sin puntos ni espacios)
> antes de comparar. Antes no lo hacía, y «71.000.001» y «71000001» acababan como
> dos votantes distintos. Lo que sigue describiendo este documento —qué campos se
> rellenan y cuándo se marca `has_multiple_records`— se conserva igual.

> **Actualizado por la Spec 0091.** Los webhooks de n8n **ya no existen**. El
> puesto de votación lo consulta ahora la propia campaña, de salida y en cola,
> contra el servicio propio `platform-politics-registraduria`. Ver «Consulta de
> Registraduría» más abajo.

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
| Consulta a Registraduría (cola) | `resolverRegistraduria()` — **find-or-create** normalizado | es el censo oficial de dónde vota esa persona: si el puesto no está en el catálogo, lo que falta es el renglón (igual que el acta E-14) |
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

Quien no tiene puesto de votación se lo pregunta a la Registraduría. Lo hace la
plataforma, **de salida**, contra un servicio propio.

### Antes (n8n) y ahora

| | Antes (Spec 0030) | Ahora (Spec 0091) |
| --- | --- | --- |
| Quién scrapea | n8n, fuera del monorepo | `platform-politics-registraduria` (FastAPI), repo propio |
| Quién empieza | n8n preguntaba `GET .../pendientes` por lotes | Laravel encola al **nacer** el votante sin puesto |
| Cómo escribe | `POST .../actualizar` con secreto por tenant | `RegistraduriaSyncService`, dentro del proceso |
| Credencial | `tenants.registraduria_secret_hash` (una por campaña) | `REGISTRADURIA_SERVICE_TOKEN` en `.env`, de salida |
| Latencia | la del lote de n8n | segundos tras el check-in |

Las dos rutas (`GET/POST /api/v1/webhook/political/registraduria/*`), el middleware
`webhook.registraduria`, el comando `registraduria:secret` y la columna del secreto
**se eliminaron**. Responden 404; lo fija
`tests/Feature/Voters/RetiroWebhookN8nTest.php`.

### Las piezas

| Pieza | Qué hace |
| --- | --- |
| `App\Services\Registraduria\RegistraduriaClient` | `consultar(string $cedula): ResultadoConsulta`. Llama a `POST {url}/api/consultar` con `Authorization: Bearer`. Mapea el estado del servicio a `encontrado` / `no_encontrado` / `fallo`. |
| `App\Services\Registraduria\RegistraduriaSyncService` | `aplicar(Voter, array $datos)`. Escribe los cinco campos y liga `voting_place_id` con `PuestoResolver::resolverRegistraduria()`; siembra la dirección del puesto solo si estaba vacía. `mapear(array $fila)` traduce el contrato del servicio. |
| `App\Jobs\Registraduria\ConsultarPuestoVotacionJob` | La consulta, en cola: enlaza el tenant, comprueba la guarda de idempotencia, llama al cliente y aplica. |
| `App\Observers\VoterObserver` | El disparo: encola al **crearse** un votante sin puesto. Punto único. |
| `voters:consultar-puestos` | El backfill de los que ya estaban en la base. |

### Configuración

```env
REGISTRADURIA_SERVICE_URL=http://127.0.0.1:8100
REGISTRADURIA_SERVICE_TOKEN=<el mismo API_TOKEN del .env del servicio>
REGISTRADURIA_SERVICE_TIMEOUT=300
```

**Sin `REGISTRADURIA_SERVICE_URL` el flujo queda apagado**: no se encola nada y no
se sale a la red. Es el estado por defecto —y el de las pruebas—, para que una
instalación sin el servicio Python levantado no llene la cola de trabajos
condenados a fallar.

El servicio se levanta en su repo con `uvicorn app:app --host 127.0.0.1 --port 8100`.
Su contrato y su estrategia de dos niveles (intenta gratis, y solo paga 2Captcha si
hace falta) están en el README de `platform-politics-registraduria`.

### El disparo automático

`VoterObserver::created` encola `ConsultarPuestoVotacionJob` cuando el votante nace
**sin** puesto (`departamento_votacion` vacío **y** `voting_place_id` nulo). Está en
el observer y no en cada sitio que crea votantes: los caminos son tres —check-in de
reunión (Spec 0022), alta manual y comandos de sincronización— y repartir el
`dispatch` era garantizar que el cuarto se olvidara.

Quien nace **con** la ubicación tecleada no gasta consulta.

### El Job

- **Enlaza `current_tenant_id`** antes de tocar datos y lo restaura al terminar. En
  la cola no hay petición, así que `EnsureTenant` no corrió: sin ese enlace
  `TenantScope` no acotaría los votantes y `PuestoResolver` usaría los alias de otra
  campaña (Constitución, Art. III).
- **Guarda de idempotencia:** si el votante ya tiene puesto, termina sin consultar.
  Dos check-in casi simultáneos de la misma persona encolan dos Jobs y solo el
  primero gasta la consulta.
- **`no_encontrado` no se reintenta:** esa cédula no está en el censo e insistir no
  la va a poner. Se registra y se termina.
- **Los fallos técnicos sí:** `tries = 3` con `backoff()` de 60 s, 5 min y 15 min.
  Agotados los intentos el Job queda en `failed_jobs` y el votante lo recoge la
  siguiente corrida del backfill.
- Serializa **ids**, nunca la cédula: lo que se guarda en `jobs`/`failed_jobs` no
  lleva PII (Art. VII). En los logs la cédula va enmascarada (`14****37`).

### El backfill

```bash
php artisan voters:consultar-puestos                      # todas las campañas
php artisan voters:consultar-puestos --tenant=1            # solo esa campaña
php artisan voters:consultar-puestos --tenant=1 --limit=50 # y como mucho 50 consultas
```

Recorre campaña por campaña (enlazando el tenant) a quien no tiene puesto y encola
un Job por cada uno. Reemplaza al `pendientes` de n8n. Es **idempotente**: la
segunda corrida no vuelve a encolar a los que ya se resolvieron.

`--limit` no es una comodidad: el servicio cae a 2Captcha cuando el camino gratis
falla, y eso se cobra por consulta. Una reunión de 300 personas son 300 consultas.

### Qué escribe

Lo mismo que escribía el webhook, con el mismo resolver (Spec 0075):
`departamento_votacion`, `municipio_votacion`, `puesto_votacion`,
`direccion_votacion`, `mesa_votacion` y `voting_place_id` **canónico**.

`mesa_votacion` se guarda como texto (la columna es `string(20)`): una mesa «12A»
ya no se rechaza — el webhook la validaba como entero, y esa validación se fue con
él.

Pruebas: `tests/Unit/Services/Registraduria/*`, `tests/Feature/Voters/ConsultarPuestoVotacionJobTest.php`,
`EncoladoConsultaPuestoTest.php`, `RetiroWebhookN8nTest.php` y
`tests/Feature/Console/ConsultarPuestosVotantesTest.php`.

---

## Comandos de consola

| Comando | Clase | Programado |
| --- | --- | --- |
| `voters:consultar-puestos {--tenant=} {--limit=}` | `ConsultarPuestosVotantes` | no (manual, Spec 0091) |
| `voters:sync {--tenant=}` | `SyncVotersFromAttendees` | sí, `twiceDaily(6, 18)` en `routes/console.php` |
| `voters:sync-attendees {--tenant-id=}` | `SyncAttendeesToVoters` | no |
| `voters:reapuntar-voting-place {--tenant=}` | `ReapuntarVotingPlaceVotantes` | no (manual, Spec 0075) |

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
