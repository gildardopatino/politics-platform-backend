# Geocodificación — dirección a coordenadas

Contrato de `POST /api/v1/geocode` y del proveedor detrás (Spec 0055).

Lo usa el buscador de dirección del formulario de reuniones del panel: el usuario
escribe una dirección, el frontend la manda con el municipio y el departamento
pegados, y con las coordenadas que vuelven pone el pin en el mapa.

Pruebas que lo sostienen:

| Archivo | Qué fija |
| --- | --- |
| `tests/Feature/Geocode/GeocodeEndpointTest.php` (5) | el contrato del endpoint: 200/404/422/401 y que el 500 no filtra detalles |
| `tests/Unit/Services/NominatimGeocoderTest.php` (9) | el proveedor: consulta, identificación, caché, throttle y errores |

---

## El endpoint

`POST /api/v1/geocode`, dentro de `['jwt.auth', 'tenant', 'tenant.active']`. Sin
token → 401.

**Petición**

```json
{ "address": "Calle 15 #3-40, Ibagué, Tolima" }
```

`address` es obligatoria y de máximo 500 caracteres; si falta, 422 con el mensaje
en español.

**200 — encontrada**

```json
{
  "data": {
    "latitude": 4.4389,
    "longitude": -75.2322,
    "formatted_address": "Ibagué, Tolima, Colombia",
    "original_address": "Calle 15 #3-40, Ibagué, Tolima"
  }
}
```

`original_address` devuelve lo que se pidió, tal cual, para que el frontend pueda
mostrar ambas.

**404 — el proveedor respondió bien pero no encontró nada**

```json
{ "message": "No se pudo geocodificar la dirección.", "address": "…" }
```

**500 — el proveedor falló**

```json
{ "message": "Error al procesar la geocodificación." }
```

⚠️ El cuerpo **no** lleva el detalle del fallo. Antes sí (`error` con el mensaje
de la excepción, y en el 404 el código de estado de Google), lo que exponía el
proveedor y su error al cliente. Ahora el detalle va a `Log::error` y el 404 a
`Log::warning`.

## El proveedor

`App\Services\Geocoding\Geocoder` es la interfaz —`resolve(string): ?GeocodeResult`—
y `NominatimGeocoder` la implementación. El controlador solo valida y traduce; el
binding vive en `AppServiceProvider` y sale de `services.geocoding.provider`,
igual que el de SMS.

**Nominatim** es el geocodificador de OpenStreetMap. No pide clave —por eso
sustituyó a la Google Geocoding API— pero su política de uso sí pide tres cosas,
y las tres están en la implementación:

1. **Identificarse.** Cada petición manda `User-Agent: {user_agent} (+{contacto})`.
   Un User-Agent genérico es motivo de bloqueo.
2. **Máximo una petición por segundo.** La marca de la última llamada vive en
   caché, así que el límite vale entre procesos y no solo dentro de uno. Una
   respuesta servida desde caché no cuenta y no espera.
3. **No repetir consultas.** Se cachea por dirección normalizada (minúsculas,
   espacios colapsados) durante `cache_ttl`, y **se cachea también el fallo**: una
   dirección mal escrita se reintenta varias veces y cada intento sería, si no,
   una petición nueva.

La consulta es `GET {nominatim_url}/search` con `format=jsonv2`, `limit=1`,
`addressdetails=1`, `accept-language=es` y `countrycodes=co`. El sesgo a Colombia
es lo que evita que «Calle 15» termine en otro país. Se toma el primer resultado
(`lat`, `lon`, `display_name`); si viene incompleto se trata como «sin
resultados».

`display_name` es más verboso que el `formatted_address` de Google — es el
nombre completo de la ubicación en OSM. Es lo que se devuelve.

## Configuración

`config/services.php` → bloque `geocoding`, todo por `.env` con defaults sanos:

| Variable | Default | Para qué |
| --- | --- | --- |
| `GEOCODING_PROVIDER` | `nominatim` | qué implementación resuelve el contenedor |
| `GEOCODING_NOMINATIM_URL` | `https://nominatim.openstreetmap.org` | **auto-hospedar es cambiar esto** |
| `GEOCODING_USER_AGENT` | `SuiteElectoral/1.0` | nombre de la app en el User-Agent |
| `GEOCODING_CONTACT_EMAIL` | vacío | correo de contacto que se anexa al User-Agent |
| `GEOCODING_CACHE_TTL` | `2592000` (30 días) | cuánto dura una dirección en caché |
| `GEOCODING_TIMEOUT` | `8` | segundos antes de rendirse |

**Antes de producción:** poner `GEOCODING_CONTACT_EMAIL`. Sin él el User-Agent va
sin contacto, que es lo primero que Nominatim mira cuando bloquea a alguien.

El Nominatim público no sirve para volumen alto ni para cargas masivas; el
throttle y la caché mantienen el uso dentro de su política. Si el uso crece,
auto-hospedar (una variable) o escribir otra implementación de `Geocoder`.

## Fuera de alcance

- **Autocompletado** de direcciones mientras se escribe: la opción de OSM es
  Photon, y va en su propia spec.
- **Geocodificación inversa** (coordenadas → dirección): no se usa hoy.
