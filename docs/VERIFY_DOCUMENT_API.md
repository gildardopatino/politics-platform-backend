# Verificación de documento (autocompletado por cédula)

Busca los datos de una persona por cédula para rellenar formularios. Consulta
primero **PISAMI** (registro externo del municipio de Ibagué) y, si no responde
o no la encuentra, la tabla local **`leads`**.

Hay **dos rutas** y no son intercambiables. La diferencia no es cosmética: define
en qué tenant se busca y cuánta información sale.

| Ruta | Autenticación | Ámbito de la búsqueda | Devuelve |
| --- | --- | --- | --- |
| `GET /api/v1/meetings/public/{qr_code}/verify-document` | ninguna | el tenant **dueño de la reunión del QR** | nombres, apellidos, teléfono, correo |
| `GET /api/v1/verify-document` | JWT + `view_voters` | el tenant **del usuario** | registro completo |

> **Historia.** `GET /verify-document` era público y vivía fuera del grupo
> `tenant`, así que no había `current_tenant_id` enlazado y `TenantScope` no
> filtraba: cualquiera, sin sesión y sabiendo solo una cédula, obtenía nombre,
> teléfono, correo, dirección y puesto de votación de un lead de **cualquier
> campaña**. Cerrado en la **Spec 0026**.

---

## Ruta pública — bajo el QR de la reunión

```http
GET /api/v1/meetings/public/{qr_code}/verify-document?cedula=71000001
```

La usa el formulario de asistencia que se abre al escanear el QR
(`MeetingCheckIn.tsx`), que dispara la consulta a partir de 6 dígitos.

**El QR es la credencial.** `MeetingController@verifyDocument` resuelve la
reunión por `qr_code`, enlaza `current_tenant_id` con su `tenant_id` y solo
entonces consulta; de ahí en adelante `TenantScope` filtra los leads. Sin un QR
válido no hay consulta: `firstOrFail()` responde 404 antes de tocar la cédula.

`throttle:20,1` — 20 peticiones por minuto e IP. Sin ese límite el endpoint es
un oráculo de cédulas: se puede barrer el espacio de documentos preguntando una
por una.

### Respuesta 200

```json
{
  "success": true,
  "source": "leads",
  "data": {
    "nombres": "Ana",
    "apellidos": "Restrepo",
    "telefono": "3001112233",
    "email": "ana@ejemplo.test"
  }
}
```

`source` es `"pisami"` o `"leads"` según de dónde salió el dato. **Cuatro campos
y nada más**: la dirección y el puesto de votación son PII que no tiene por qué
viajar por una ruta sin sesión, y ese formulario nunca los usó
(`DocumentVerificationService::soloContacto()`).

### Otras respuestas

| Código | Cuándo |
| --- | --- |
| `404` | QR inexistente, o cédula sin resultados en PISAMI ni en los leads de ese tenant |
| `422` | falta `cedula` o pasa de 20 caracteres |
| `429` | más de 20 peticiones por minuto |

Un lead de otro tenant devuelve `404`, igual que uno que no existe: la respuesta
no distingue ambos casos.

---

## Ruta autenticada — pantallas internas

```http
GET /api/v1/verify-document?cedula=71000001
Authorization: Bearer <jwt>
```

Dentro del grupo `['tenant', 'tenant.active']` con `permission:view_voters`. La
usa el formulario del call center (`VoterForm.tsx`), que sí captura dirección y
puesto de votación. Quien la llama está autenticado y acotado a su tenant, así
que devuelve el registro completo:

```json
{
  "success": true,
  "source": "leads",
  "data": {
    "cedula": "71000001",
    "nombres": "Ana",
    "apellidos": "Restrepo",
    "nombre_completo": "Ana Restrepo",
    "fecha_nacimiento": "1990-05-15",
    "telefono": "3001112233",
    "email": "ana@ejemplo.test",
    "direccion": "Calle 50 #45-30",
    "barrio": "Centro",
    "departamento_votacion": "Tolima",
    "municipio_votacion": "Ibagué",
    "puesto_votacion": "IE El Centro",
    "zona_votacion": "Zona 1",
    "mesa_votacion": "012",
    "direccion_votacion": "Calle 10 #5-20",
    "locality_name": "Comuna 1",
    "latitud": "4.4389",
    "longitud": "-75.2322"
  }
}
```

Cuando `source` es `"pisami"` solo vienen `nombres`, `apellidos`, `direccion`,
`telefono` y `email`: es todo lo que expone esa API externa.

| Código | Cuándo |
| --- | --- |
| `401` | sin token o token vencido |
| `403` | sin el permiso `view_voters` |
| `404` | cédula sin resultados dentro del tenant del usuario |
| `422` | falta `cedula` o pasa de 20 caracteres |

---

## Implementación

`App\Services\DocumentVerificationService` concentra la búsqueda y la comparten
las dos rutas. **Depende de que el llamador haya fijado `current_tenant_id`**
antes de invocar `verify()`: en las rutas autenticadas lo hace `EnsureTenant`, en
la pública lo hace el controlador a partir de la reunión. Si algún día se llama
desde un job o un comando de consola, hay que enlazarlo a mano — si no, no
filtra (ver `CLAUDE.md`, sección de multitenencia).

`App\Services\PisamiService` habla con la API externa
(`pisami.ibague.gov.co`, timeout 30 s) y parsea su respuesta, que no es JSON
sino JavaScript del tipo `parent.document.f_pqr.CAMPO.value="..."`. Si falla o
devuelve algo inesperado, registra un aviso y retorna `null`, y la búsqueda cae
en los leads. La URL está en duro en el servicio, no en `config/` — pendiente
registrado en `known-issues.md`.

Nota: ninguna de las dos rutas mira la tabla `voters` ni los asistentes de
reuniones anteriores. Una persona que ya es votante del tenant no se autocompleta
si no está también en `leads` (hueco caracterizado en la Spec 0010).

## Pruebas

- `tests/Feature/Voters/VerifyDocumentTenantScopeTest.php` — el contrato completo
  de las dos rutas, incluido el caso de no-fuga: lead del tenant B consultado con
  el QR del tenant A → 404.
- `tests/Feature/Meetings/MeetingAttendanceDomainTest.php` — el hueco en el flujo
  de asistencia y su cierre.
- Frontend: `src/services/verifyDocument.test.ts` — cada pantalla pide la ruta
  que le corresponde.

PISAMI se mockea siempre con `Http::fake()` + `Http::preventStrayRequests()`: la
suite no sale a la red.
