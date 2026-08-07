# Sincronización de votantes: asistentes y Registraduría

## 📋 Resumen

`voters` es la tabla oficial de electores. Se alimenta por **tres** vías:

| Vía | Qué escribe | Dónde |
| --- | --- | --- |
| Check-in de reunión | crea o liga la persona por cédula | `App\Services\AttendanceService` (Spec 0022) |
| Comando programado | recorre `meeting_attendees` y rellena huecos | `voters:sync`, dos veces al día |
| Webhooks de Registraduría | escribe el puesto de votación | rutas públicas, ver abajo |

> **Actualizado por la Spec 0022.** La sincronización asistente → votante ya no
> vive en `MeetingAttendeeObserver` con su propia copia de la lógica: el observer
> delega en `AttendanceService`, que normaliza la cédula (sin puntos ni espacios)
> antes de comparar. Antes no lo hacía, y «71.000.001» y «71000001» acababan como
> dos votantes distintos. Lo que sigue describiendo este documento —qué campos se
> rellenan y cuándo se marca `has_multiple_records`— se conserva igual.

> **Contrato observado (Spec 0011).** Los webhooks están verificados con
> `tests/Feature/Voters/RegistraduriaWebhookCharacterizationTest.php`.

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

## Webhooks de Registraduría

Dos rutas para que n8n complete la información electoral de los votantes.
**Autenticadas con un secreto por tenant** desde la Spec 0030 (antes eran
públicas; ver «Historia» al final de la sección).

### Autenticación

Cada campaña tiene **su propio secreto**, que viaja en la cabecera:

```
X-Registraduria-Secret: <64 caracteres>
```

El secreto **autentica e identifica a la vez**: no hay campo de tenant en el
payload que un atacante pueda elegir, y una fuga compromete una campaña, no la
plataforma. Al validarlo, el middleware `webhook.registraduria` enlaza
`current_tenant_id`, así que el resto de la petición está acotada por
`TenantScope` igual que las rutas con sesión.

En la tabla `tenants` solo se guarda el **SHA-256** del secreto
(`registraduria_secret_hash`), no el secreto: quien lea la base no puede firmar
peticiones. Sin sal a propósito —son 64 caracteres aleatorios, no hay
diccionario que atacar— para que la búsqueda sea por índice y no un recorrido de
todos los tenants.

#### Generar o rotar el secreto

```bash
php artisan registraduria:secret {id-o-slug-del-tenant}
php artisan registraduria:secret mi-campania --rotate
```

Imprime el valor en claro **una sola vez**. Si se pierde, hay que rotarlo y
reconfigurar n8n. Rotar invalida el anterior de inmediato.

La columna es **nullable**: un tenant recién creado no tiene secreto y **no
puede sincronizar** hasta que se le genere. Es el estado seguro por defecto.

#### Respuestas de rechazo

| Situación | Código | `error` |
| --- | --- | --- |
| Falta la cabecera o viene vacía | 401 | `WEBHOOK_SECRET_MISSING` |
| El secreto no es de nadie (o el tenant fue borrado) | 401 | `WEBHOOK_SECRET_INVALID` |
| Vigencia de la campaña expirada | 403 | `TENANT_EXPIRED` |
| Vigencia aún no iniciada | 403 | `TENANT_NOT_STARTED` |
| Más de 60 peticiones por minuto e IP | 429 | — |

El `throttle:60,1` va **delante** de la verificación para que el secreto no se
pueda tantear a fuerza bruta.

> ⚠️ **Configuración de n8n.** Antes bastaba una llamada para obtener los
> pendientes de **toda** la plataforma. Ahora el flujo es **por campaña**: n8n
> necesita una credencial por tenant y debe recorrerlas. Es la consecuencia
> directa de acotar por tenant, y es intencional.

### `GET /api/v1/webhook/political/registraduria/pendientes`

Devuelve un **array crudo**, sin envoltorio ni paginación, con hasta **100**
votantes **del tenant del secreto** que no tienen `departamento_votacion`.

```json
[ { "id": 12, "cedula": "71000001" } ]
```

Solo `id` y `cedula`. (Antes se colaban además un `full_name` vacío y un
`location_type` nulo por los `$appends` del modelo, pese al `select`.)

Sin cursor ni paginación: la única forma de avanzar es actualizar los primeros
100 y volver a llamar.

### `POST /api/v1/webhook/political/registraduria/actualizar`

| Campo | Regla |
| --- | --- |
| `id` | requerido, entero, **debe ser un votante del tenant del secreto** |
| `departamento_votacion` | requerido, máx 255 |
| `municipio_votacion` | requerido, máx 255 |
| `puesto_votacion` | requerido, máx 255 |
| `direccion_votacion` | opcional, máx 500 |
| `mesa_votacion` | opcional, **entero** |

Crea el `VotingPlace` si no existía (`firstOrCreate` por departamento +
municipio + puesto), lo liga en `voters.voting_place_id` y escribe los cinco
campos en el votante.

Un `id` de otra campaña se rechaza **igual que uno inexistente** —mismo código y
mismo cuerpo—, así que el webhook no sirve para averiguar qué ids tiene la
competencia.

```json
{ "success": true,
  "message": "Información de registraduría actualizada correctamente.",
  "data": { "id": 12, "updated": true } }
```

**Acuse mínimo**: ni PII ni `tenant_id`. Errores: **422** con
`{ success: false, errors: {...} }`.

⚠️ `mesa_votacion` se valida como **entero** aunque `voters.mesa_votacion` sea
`string(20)` y el formulario interno la acepte como texto: una mesa «12A» se
rechaza aquí. Sigue abierto en `known-issues.md`.

⚠️ La rama que devuelve `404 «Votante no encontrado»` es **inalcanzable**: la
regla `exists` corta antes con un 422. Se conserva como segunda cerradura.

### Historia — qué se cerró y por qué

Hasta la Spec 0030 las dos rutas estaban **fuera del grupo `jwt.auth`**, sin
token, sin firma y sin `throttle`, y consultaban con
`withoutGlobalScope(TenantScope::class)`:

1. `pendientes` **repartía hasta 100 cédulas de cualquier campaña** a quien
   supiera la URL. (El agujero que cerró la Spec 0026 exigía conocer ya la
   cédula; este las entregaba.)
2. `actualizar` **escribía** el puesto de votación en el votante de cualquier
   tenant.
3. Y devolvía `data => $voter->fresh()`, el modelo **completo**: nombres,
   apellidos, correo, teléfono, dirección y `tenant_id`.

Encadenando (1) → (3) se podía vaciar la base de votantes de **todas** las
campañas sin autenticarse. Lo caracterizó la Spec 0011 y lo cerró la 0030.

Pruebas: `tests/Feature/Voters/RegistraduriaWebhookSecurityTest.php` (contrato
de seguridad) y `RegistraduriaWebhookCharacterizationTest.php` (lo que hacen).

---

## Comandos de consola

| Comando | Clase | Programado |
| --- | --- | --- |
| `registraduria:secret {tenant} [--rotate]` | `GenerateRegistraduriaSecret` | no (manual, Spec 0030) |
| `voters:sync {--tenant=}` | `SyncVotersFromAttendees` | sí, `twiceDaily(6, 18)` en `routes/console.php` |
| `voters:sync-attendees {--tenant-id=}` | `SyncAttendeesToVoters` | no |

⚠️ Los dos de sincronización hacen casi lo mismo. Solo `voters:sync` está
programado; a `voters:sync-attendees` no lo invoca nadie.
