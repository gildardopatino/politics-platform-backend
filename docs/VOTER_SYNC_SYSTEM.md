# Sistema de Sincronización Automática: Asistentes → Voters

## 📋 Resumen

El sistema ahora sincroniza automáticamente todos los asistentes de reuniones (`meeting_attendees`) a la tabla oficial de electores (`voters`).

**Tabla Oficial de Electores:** `voters`
**Tabla de Asistentes:** `meeting_attendees`

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
- direccion_puesto
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
