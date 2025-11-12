# Sistema de Autenticación JWT con Refresh Tokens

## Flujo de Autenticación

### 1. Login
**Endpoint:** `POST /api/v1/login`

**Request:**
```json
{
  "email": "usuario@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "token_type": "bearer",
  "expires_in": 3600,
  "expires_at": "2025-10-29T20:00:00.000000Z",
  "refresh_expires_in": 1209600,
  "refresh_expires_at": "2025-11-12T19:00:00.000000Z",
  "user": {
    "id": 1,
    "name": "Usuario",
    "email": "usuario@example.com",
    ...
  },
  "tenant_status": {
    "start_date": "2025-11-04T14:17:00.000000Z",
    "expiration_date": "2025-11-11T14:17:00.000000Z",
    "is_active": true,
    "is_expired": false,
    "is_not_started": false,
    "days_until_expiration": 5
  }
}
```

**Nota sobre `tenant_status`:**
- Solo se incluye si el usuario pertenece a un tenant (usuarios de tenant)
- **No se incluye** para superadmin (tenant_id = null)
- `start_date`: Fecha/hora de inicio del tenant (null si no está configurada)
- `expiration_date`: Fecha/hora de expiración del tenant (null si no está configurada)
- `is_active`: `true` si el tenant está activo (entre start_date y expiration_date)
- `is_expired`: `true` si el tenant ya expiró
- `is_not_started`: `true` si el tenant aún no ha iniciado
- `days_until_expiration`: Días hasta la expiración (negativo si ya expiró, null si no hay fecha de expiración)

**El frontend debe:**
1. Verificar `tenant_status.is_expired` o `tenant_status.is_not_started`
2. Si alguno es `true`, mostrar mensaje de error y no permitir el acceso
3. Si `days_until_expiration` es menor a 7, mostrar advertencia de próxima expiración
```

### 2. Uso del Token
En cada request autenticado, incluir el token en el header:
```
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### 3. Refresh Automático
**El sistema maneja automáticamente el refresh del token:**

- Cuando un token expira (después de 60 minutos), el middleware intenta refrescarlo automáticamente
- Si el refresh es exitoso, la respuesta incluirá el nuevo token en los headers:
  - `Authorization: Bearer {nuevo_token}`
  - `X-Token-Refreshed: true`

**El frontend debe:**
1. Verificar si existe el header `X-Token-Refreshed`
2. Si existe, extraer el nuevo token del header `Authorization`
3. Guardar el nuevo token para futuras peticiones

### 4. Refresh Manual
**Endpoint:** `POST /api/v1/refresh`

**Headers:**
```
Authorization: Bearer {token_actual}
```

**Response:**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "token_type": "bearer",
  "expires_in": 3600,
  "expires_at": "2025-10-29T20:00:00.000000Z",
  "refresh_expires_in": 1209600,
  "refresh_expires_at": "2025-11-12T19:00:00.000000Z",
  "user": { ... }
}
```

### 5. Logout
**Endpoint:** `POST /api/v1/logout`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "message": "Successfully logged out"
}
```

## Manejo de Errores

### Token Expirado (Sin posibilidad de refresh)
**Status:** 401

```json
{
  "error": "token_expired",
  "message": "Token has expired and could not be refreshed. Please login again.",
  "requires_login": true
}
```

### Token Inválido
**Status:** 401

```json
{
  "error": "token_invalid",
  "message": "Token is invalid"
}
```

### Token No Proporcionado
**Status:** 401

```json
{
  "error": "token_absent",
  "message": "Token not provided"
}
```

### Usuario No Encontrado
**Status:** 401

```json
{
  "error": "user_not_found",
  "message": "User not found"
}
```

## Configuración JWT

### Variables de Entorno
```env
JWT_SECRET=tu_secreto_generado          # Generar con: php artisan jwt:secret
JWT_TTL=60                              # Access token: 60 minutos (1 hora)
JWT_REFRESH_TTL=20160                   # Refresh token: 14 días
JWT_ALGO=HS256                          # Algoritmo de encriptación
JWT_BLACKLIST_ENABLED=true              # Habilitar blacklist
JWT_BLACKLIST_GRACE_PERIOD=30           # Período de gracia: 30 segundos
```

### Tiempos de Expiración
- **Access Token:** 60 minutos (1 hora)
- **Refresh Token:** 20,160 minutos (14 días)
- **Blacklist Grace Period:** 30 segundos

## Implementación en Frontend

### Axios Interceptor (Ejemplo)

```javascript
import axios from 'axios';

// Request interceptor: agregar token
axios.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('access_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor: manejar refresh automático
axios.interceptors.response.use(
  (response) => {
    // Verificar si el token fue refrescado automáticamente
    if (response.headers['x-token-refreshed'] === 'true') {
      const newToken = response.headers.authorization?.replace('Bearer ', '');
      if (newToken) {
        localStorage.setItem('access_token', newToken);
        console.log('Token refreshed automatically');
      }
    }
    return response;
  },
  async (error) => {
    const originalRequest = error.config;

    // Si el error es 401 y el token expiró
    if (error.response?.status === 401) {
      const errorData = error.response.data;

      // Si requiere login completo
      if (errorData.requires_login) {
        localStorage.removeItem('access_token');
        window.location.href = '/login';
        return Promise.reject(error);
      }

      // Si no se intentó refrescar aún
      if (!originalRequest._retry) {
        originalRequest._retry = true;

        try {
          // Intentar refresh manual
          const response = await axios.post('/api/v1/refresh');
          const { access_token } = response.data;
          
          localStorage.setItem('access_token', access_token);
          originalRequest.headers.Authorization = `Bearer ${access_token}`;
          
          return axios(originalRequest);
        } catch (refreshError) {
          localStorage.removeItem('access_token');
          window.location.href = '/login';
          return Promise.reject(refreshError);
        }
      }
    }

    return Promise.reject(error);
  }
);
```

### Vue Composable (Ejemplo)

```javascript
// composables/useAuth.js
import { ref, computed } from 'vue';
import axios from 'axios';

const user = ref(null);
const token = ref(localStorage.getItem('access_token'));
const tokenExpiry = ref(localStorage.getItem('token_expiry'));

export function useAuth() {
  const isAuthenticated = computed(() => !!token.value);
  
  const login = async (credentials) => {
    const response = await axios.post('/api/v1/login', credentials);
    const { access_token, expires_at, user: userData } = response.data;
    
    token.value = access_token;
    tokenExpiry.value = expires_at;
    user.value = userData;
    
    localStorage.setItem('access_token', access_token);
    localStorage.setItem('token_expiry', expires_at);
    
    return response.data;
  };

  const logout = async () => {
    try {
      await axios.post('/api/v1/logout');
    } finally {
      token.value = null;
      user.value = null;
      tokenExpiry.value = null;
      
      localStorage.removeItem('access_token');
      localStorage.removeItem('token_expiry');
    }
  };

  const refreshToken = async () => {
    const response = await axios.post('/api/v1/refresh');
    const { access_token, expires_at } = response.data;
    
    token.value = access_token;
    tokenExpiry.value = expires_at;
    
    localStorage.setItem('access_token', access_token);
    localStorage.setItem('token_expiry', expires_at);
    
    return response.data;
  };

  const checkTokenExpiry = () => {
    if (!tokenExpiry.value) return false;
    
    const expiryDate = new Date(tokenExpiry.value);
    const now = new Date();
    const minutesUntilExpiry = (expiryDate - now) / 1000 / 60;
    
    // Si faltan menos de 5 minutos, refrescar proactivamente
    if (minutesUntilExpiry < 5 && minutesUntilExpiry > 0) {
      refreshToken();
    }
    
    return minutesUntilExpiry > 0;
  };

  return {
    user,
    token,
    isAuthenticated,
    login,
    logout,
    refreshToken,
    checkTokenExpiry
  };
}
```

## Ventajas del Sistema

1. **Refresh Automático:** El usuario no necesita hacer login cada hora
2. **Transparente:** El frontend recibe el nuevo token automáticamente en los headers
3. **Seguro:** Los tokens expirados son invalidados en la blacklist
4. **Flexible:** Soporta refresh manual y automático
5. **Informativo:** Los errores indican claramente cuándo se requiere login
6. **Grace Period:** Evita problemas con requests concurrentes

## Consideraciones de Seguridad

1. **HTTPS:** Siempre usar HTTPS en producción
2. **Token Storage:** Guardar tokens en localStorage o sessionStorage, nunca en cookies sin httpOnly
3. **Refresh Token:** El refresh token tiene vida útil de 14 días, después requiere login
4. **Blacklist:** Los tokens invalidados se guardan en blacklist
5. **Grace Period:** Período de 30 segundos permite requests concurrentes sin problemas
