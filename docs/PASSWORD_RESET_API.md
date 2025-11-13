# Sistema de Recuperación de Contraseñas - Guía para Frontend

## Flujo Completo del Usuario

```
1. Usuario hace clic en "Olvidé mi contraseña" en el login
2. Usuario ingresa su email
3. Sistema envía correo con enlace de reset
4. Usuario hace clic en el enlace del correo
5. Usuario ingresa nueva contraseña (dos veces)
6. Sistema confirma cambio exitoso
7. Usuario es redirigido al login
```

---

## Paso 1: Pantalla "Olvidé mi contraseña"

### Ruta sugerida
`/forgot-password` o `/recuperar-password`

### UI Recomendada
```
┌────────────────────────────────────┐
│  Recuperar Contraseña              │
├────────────────────────────────────┤
│                                    │
│  Ingresa tu email y te enviaremos  │
│  un enlace para restablecer tu     │
│  contraseña.                       │
│                                    │
│  Email: [________________]         │
│                                    │
│  [Enviar enlace de recuperación]   │
│                                    │
│  ← Volver al login                 │
│                                    │
└────────────────────────────────────┘
```

### Request que debe hacer el frontend

**Endpoint:** `POST /api/v1/password/forgot`

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "email": "usuario@ejemplo.com"
}
```

**Código de ejemplo (TypeScript/React):**
```typescript
const handleForgotPassword = async (email: string) => {
  try {
    const response = await fetch('http://localhost:8000/api/v1/password/forgot', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ email }),
    });

    const data = await response.json();

    if (response.ok) {
      // ✅ SIEMPRE muestra este mensaje (por seguridad)
      toast.success('Si el email existe en nuestro sistema, recibirás un correo con instrucciones');
      // Opcional: mostrar mensaje adicional para revisar spam
      setTimeout(() => {
        toast.info('Revisa también tu carpeta de spam/correo no deseado');
      }, 2000);
    } else {
      toast.error('Hubo un error. Por favor intenta de nuevo');
    }
  } catch (error) {
    console.error('Error:', error);
    toast.error('Error de conexión. Verifica tu internet');
  }
};
```

### Response del backend

**Success (200):**
```json
{
  "message": "If the email exists, a reset link will be sent."
}
```

**IMPORTANTE:** El backend SIEMPRE retorna 200 aunque el email no exista (para evitar que alguien averigüe qué emails están registrados).

### Validaciones en el frontend

```typescript
const validateEmail = (email: string): string | null => {
  if (!email) {
    return 'El email es requerido';
  }
  
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!emailRegex.test(email)) {
    return 'Ingresa un email válido';
  }
  
  return null; // null = válido
};
```

### Componente completo de ejemplo (React)

```typescript
import { useState } from 'react';
import { toast } from 'react-toastify';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    // Validar
    const error = validateEmail(email);
    if (error) {
      toast.error(error);
      return;
    }

    setLoading(true);

    try {
      const response = await fetch('http://localhost:8000/api/v1/password/forgot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email }),
      });

      if (response.ok) {
        setSent(true);
        toast.success('Correo enviado. Revisa tu bandeja de entrada');
      } else {
        toast.error('Error al enviar el correo. Intenta de nuevo');
      }
    } catch (error) {
      toast.error('Error de conexión');
    } finally {
      setLoading(false);
    }
  };

  if (sent) {
    return (
      <div className="card">
        <h1>📧 Correo Enviado</h1>
        <p>Si tu email está registrado, recibirás un correo con instrucciones para restablecer tu contraseña.</p>
        <p className="text-muted">No olvides revisar tu carpeta de spam.</p>
        <button onClick={() => window.location.href = '/login'}>
          Volver al Login
        </button>
      </div>
    );
  }

  return (
    <div className="card">
      <h1>Recuperar Contraseña</h1>
      <p>Ingresa tu email y te enviaremos un enlace para restablecer tu contraseña.</p>
      
      <form onSubmit={handleSubmit}>
        <div className="form-group">
          <label>Email</label>
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="tu@email.com"
            required
            disabled={loading}
          />
        </div>

        <button type="submit" disabled={loading}>
          {loading ? 'Enviando...' : 'Enviar enlace de recuperación'}
        </button>
      </form>

      <a href="/login" className="link-back">
        ← Volver al login
      </a>
    </div>
  );
}
```

---

## Paso 2: Usuario recibe el correo

### Contenido del correo

El usuario recibirá un correo con un mensaje similar a:

```
Hola [Nombre del Usuario],

Has solicitado restablecer tu contraseña. Haz click en el siguiente enlace para cambiarla:

[Restablecer contraseña]

Si no solicitaste esto, ignora este correo.
```

### Enlace en el correo

El enlace tendrá este formato:
```
http://localhost:3000/reset-password?token=TOKEN_AQUI&email=usuario@ejemplo.com
```

**Parámetros en la URL:**
- `token`: Token único de 64 caracteres (ejemplo: `umVqh8GzhRBdwmiXIgBhufOOFxbqfOLGyuHty41pVk...`)
- `email`: Email del usuario que solicitó el reset

---

## Paso 3: Pantalla de Reset de Contraseña

### Ruta sugerida
`/reset-password`

### UI Recomendada
```
┌────────────────────────────────────┐
│  Restablecer Contraseña            │
├────────────────────────────────────┤
│                                    │
│  Ingresa tu nueva contraseña       │
│                                    │
│  Nueva contraseña:                 │
│  [________________]                │
│  Mínimo 8 caracteres               │
│                                    │
│  Confirmar contraseña:             │
│  [________________]                │
│                                    │
│  [Cambiar contraseña]              │
│                                    │
└────────────────────────────────────┘
```

### Obtener token y email de la URL

```typescript
import { useSearchParams } from 'react-router-dom';
// o si usas Next.js:
// import { useSearchParams } from 'next/navigation';

export default function ResetPasswordPage() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token');
  const email = searchParams.get('email');

  // Validar que existen
  if (!token || !email) {
    return (
      <div className="error">
        <h1>❌ Enlace Inválido</h1>
        <p>Este enlace de recuperación no es válido o ha expirado.</p>
        <a href="/forgot-password">Solicitar nuevo enlace</a>
      </div>
    );
  }

  // ... resto del componente
}
```

### Request que debe hacer el frontend

**Endpoint:** `POST /api/v1/password/reset`

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "email": "usuario@ejemplo.com",
  "token": "umVqh8GzhRBdwmiXIgBhufOOFxbqfOLGyuHty41pVk...",
  "password": "nuevaContraseña123",
  "password_confirmation": "nuevaContraseña123"
}
```

**IMPORTANTE:** 
- `password` y `password_confirmation` DEBEN ser idénticos
- `password` debe tener mínimo 8 caracteres
- `email` debe ser exactamente el mismo que viene en la URL
- `token` debe ser exactamente el mismo que viene en la URL

### Response del backend

**Success (200):**
```json
{
  "message": "Password reset successfully."
}
```

**Error: Token Inválido (422):**
```json
{
  "message": "Invalid token or email"
}
```

**Error: Token Expirado (422):**
```json
{
  "message": "Token expired. Please request a new reset link."
}
```

**Error: Validación (422):**
```json
{
  "message": "The password field must be at least 8 characters.",
  "errors": {
    "password": ["The password field must be at least 8 characters."],
    "password_confirmation": ["The password confirmation does not match."]
  }
}
```

### Validaciones en el frontend

```typescript
interface ValidationErrors {
  password?: string;
  passwordConfirmation?: string;
}

const validatePasswords = (
  password: string, 
  passwordConfirmation: string
): ValidationErrors => {
  const errors: ValidationErrors = {};

  if (!password) {
    errors.password = 'La contraseña es requerida';
  } else if (password.length < 8) {
    errors.password = 'La contraseña debe tener mínimo 8 caracteres';
  }

  if (!passwordConfirmation) {
    errors.passwordConfirmation = 'Debes confirmar la contraseña';
  } else if (password !== passwordConfirmation) {
    errors.passwordConfirmation = 'Las contraseñas no coinciden';
  }

  return errors;
};
```

### Componente completo de ejemplo (React)

```typescript
import { useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { toast } from 'react-toastify';

export default function ResetPasswordPage() {
  const searchParams = useSearchParams();
  const navigate = useNavigate();
  
  const token = searchParams.get('token');
  const email = searchParams.get('email');

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  // Validar que el enlace tiene los parámetros necesarios
  if (!token || !email) {
    return (
      <div className="error-page">
        <h1>❌ Enlace Inválido</h1>
        <p>Este enlace de recuperación no es válido o ha expirado.</p>
        <button onClick={() => navigate('/forgot-password')}>
          Solicitar nuevo enlace
        </button>
      </div>
    );
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Validar
    const errors = validatePasswords(password, passwordConfirmation);
    if (Object.keys(errors).length > 0) {
      if (errors.password) toast.error(errors.password);
      if (errors.passwordConfirmation) toast.error(errors.passwordConfirmation);
      return;
    }

    setLoading(true);

    try {
      const response = await fetch('http://localhost:8000/api/v1/password/reset', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          email,
          token,
          password,
          password_confirmation: passwordConfirmation,
        }),
      });

      const data = await response.json();

      if (response.ok) {
        toast.success('✅ Contraseña cambiada exitosamente');
        // Redirigir al login después de 2 segundos
        setTimeout(() => {
          navigate('/login');
        }, 2000);
      } else {
        // Manejar errores específicos
        if (data.message.includes('expired')) {
          toast.error('⏰ El enlace ha expirado. Solicita uno nuevo');
          setTimeout(() => {
            navigate('/forgot-password');
          }, 3000);
        } else if (data.message.includes('Invalid')) {
          toast.error('❌ Enlace inválido. Solicita uno nuevo');
        } else {
          toast.error(data.message || 'Error al cambiar contraseña');
        }
      }
    } catch (error) {
      console.error('Error:', error);
      toast.error('Error de conexión. Verifica tu internet');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card">
      <h1>🔐 Restablecer Contraseña</h1>
      <p className="text-muted">Email: {email}</p>

      <form onSubmit={handleSubmit}>
        <div className="form-group">
          <label>Nueva Contraseña</label>
          <div className="password-input">
            <input
              type={showPassword ? 'text' : 'password'}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Mínimo 8 caracteres"
              required
              disabled={loading}
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="toggle-password"
            >
              {showPassword ? '👁️' : '👁️‍🗨️'}
            </button>
          </div>
          <small className="help-text">Mínimo 8 caracteres</small>
        </div>

        <div className="form-group">
          <label>Confirmar Contraseña</label>
          <input
            type={showPassword ? 'text' : 'password'}
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            placeholder="Repite la contraseña"
            required
            disabled={loading}
          />
        </div>

        {/* Indicador de fortaleza de contraseña */}
        {password && (
          <div className="password-strength">
            <div className="strength-bar">
              <div
                className={`bar ${getPasswordStrength(password)}`}
                style={{ width: `${getPasswordStrengthPercent(password)}%` }}
              />
            </div>
            <small>{getPasswordStrengthText(password)}</small>
          </div>
        )}

        <button type="submit" disabled={loading || !password || !passwordConfirmation}>
          {loading ? 'Cambiando contraseña...' : 'Cambiar contraseña'}
        </button>
      </form>
    </div>
  );
}

// Helpers para fortaleza de contraseña
function getPasswordStrength(password: string): string {
  if (password.length < 8) return 'weak';
  if (password.length < 12) return 'medium';
  if (!/[A-Z]/.test(password) || !/[0-9]/.test(password)) return 'medium';
  return 'strong';
}

function getPasswordStrengthPercent(password: string): number {
  const strength = getPasswordStrength(password);
  if (strength === 'weak') return 33;
  if (strength === 'medium') return 66;
  return 100;
}

function getPasswordStrengthText(password: string): string {
  const strength = getPasswordStrength(password);
  if (strength === 'weak') return '⚠️ Contraseña débil';
  if (strength === 'medium') return '✓ Contraseña aceptable';
  return '✓✓ Contraseña fuerte';
}
```

---

## Paso 4: Después del Reset Exitoso

### Flujo Recomendado

1. Mostrar mensaje de éxito
2. Esperar 2 segundos
3. Redirigir automáticamente al login

```typescript
if (response.ok) {
  toast.success('✅ Contraseña cambiada exitosamente');
  toast.info('Redirigiendo al login...', { autoClose: 1500 });
  
  setTimeout(() => {
    navigate('/login');
  }, 2000);
}
```

---

## Manejo de Errores Completo

### Tabla de Errores y Cómo Manejarlos

| Error | Status | Mensaje Backend | Acción Frontend |
|-------|--------|----------------|-----------------|
| Email no existe | 200 | "If the email exists..." | Mostrar mensaje genérico (no revelar) |
| Token inválido | 422 | "Invalid token or email" | Mostrar error, botón "Solicitar nuevo enlace" |
| Token expirado | 422 | "Token expired..." | Mostrar error, redirigir a /forgot-password |
| Contraseña corta | 422 | "must be at least 8 characters" | Mostrar error en el campo |
| Confirmación no coincide | 422 | "confirmation does not match" | Mostrar error en campo de confirmación |
| Error de red | - | - | Mostrar "Error de conexión" |

### Código para manejo de errores

```typescript
const handleResetError = (response: Response, data: any, navigate: any) => {
  if (response.status === 422) {
    const message = data.message;
    
    if (message.includes('expired')) {
      toast.error('⏰ El enlace ha expirado (válido por 60 minutos)');
      toast.info('Solicita un nuevo enlace de recuperación');
      setTimeout(() => navigate('/forgot-password'), 3000);
    } 
    else if (message.includes('Invalid')) {
      toast.error('❌ El enlace es inválido');
      toast.info('Solicita un nuevo enlace de recuperación');
    }
    else if (data.errors) {
      // Errores de validación
      Object.values(data.errors).flat().forEach((error: any) => {
        toast.error(error);
      });
    }
    else {
      toast.error(message);
    }
  } 
  else if (response.status === 500) {
    toast.error('Error del servidor. Intenta más tarde');
  }
  else {
    toast.error('Error desconocido. Intenta de nuevo');
  }
};
```

---

## Configuración de Variables de Entorno

### Frontend (.env o .env.local)

```env
# URL base del API
NEXT_PUBLIC_API_URL=http://localhost:8000
# o para React/Vite:
VITE_API_URL=http://localhost:8000

# URL del frontend (para testing)
NEXT_PUBLIC_APP_URL=http://localhost:3000
```

### Uso en el código

```typescript
const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

// Luego en los fetch:
fetch(`${API_URL}/api/v1/password/forgot`, { ... })
fetch(`${API_URL}/api/v1/password/reset`, { ... })
```

---

## Checklist de Implementación

### Para el Desarrollador Frontend

- [ ] Crear página `/forgot-password`
  - [ ] Formulario con campo email
  - [ ] Validación de email
  - [ ] Llamada a POST `/api/v1/password/forgot`
  - [ ] Mostrar mensaje de éxito (genérico)
  - [ ] Botón "Volver al login"

- [ ] Crear página `/reset-password`
  - [ ] Obtener `token` y `email` de query params
  - [ ] Validar que existen los parámetros
  - [ ] Formulario con password y password_confirmation
  - [ ] Validación de contraseñas (mínimo 8 caracteres, deben coincidir)
  - [ ] Indicador de fortaleza de contraseña (opcional)
  - [ ] Llamada a POST `/api/v1/password/reset`
  - [ ] Manejo de errores (token expirado, inválido, etc.)
  - [ ] Redirección al login después de éxito

- [ ] Añadir link "Olvidé mi contraseña" en el login
  - [ ] Link apunta a `/forgot-password`

- [ ] Testing
  - [ ] Probar flujo completo end-to-end
  - [ ] Probar con email que no existe (debe mostrar mensaje genérico)
  - [ ] Probar con token expirado (debe mostrar error apropiado)
  - [ ] Probar con contraseñas que no coinciden
  - [ ] Probar con contraseña muy corta
  - [ ] Probar sin conexión a internet

---

## Preguntas Frecuentes (FAQ)

### ¿Cuánto tiempo es válido el enlace?
**60 minutos** desde que se genera. Después el usuario debe solicitar uno nuevo.

### ¿Qué pasa si el usuario solicita múltiples enlaces?
Solo el **más reciente** será válido. Los anteriores se invalidan automáticamente.

### ¿El enlace se puede usar múltiples veces?
**No**. El token se elimina después del primer uso exitoso (one-time use).

### ¿Qué pasa si el email no existe?
El backend retorna el **mismo mensaje** que si existiera (por seguridad). Esto evita que alguien averigüe qué emails están registrados.

### ¿Puedo personalizar el correo que se envía?
Sí, el correo se envía vía webhook de n8n. Puedes personalizar la plantilla en tu flujo de n8n.

### ¿Dónde se guardan los tokens?
En la tabla `password_resets` en la base de datos, **hasheados con bcrypt** (no en texto plano).

---

## Ejemplos de Testing Manual

### Test 1: Flujo Completo Exitoso

1. Ir a `/login`
2. Click en "Olvidé mi contraseña"
3. Ingresar email válido: `usuario@ejemplo.com`
4. Verificar mensaje: "Si el email existe..."
5. Abrir correo (revisar logs del backend si no llega)
6. Click en el enlace del correo
7. Ingresar nueva contraseña (mínimo 8 caracteres)
8. Confirmar contraseña (igual a la anterior)
9. Click en "Cambiar contraseña"
10. Verificar mensaje: "Contraseña cambiada exitosamente"
11. Verificar redirección a `/login`
12. Intentar login con la nueva contraseña ✅

### Test 2: Email No Existe

1. Ir a `/forgot-password`
2. Ingresar email que no existe: `noexiste@ejemplo.com`
3. Verificar que muestra el **mismo mensaje** que con email válido
4. No debe revelar que el email no existe

### Test 3: Token Expirado

1. Solicitar reset de contraseña
2. Esperar más de 60 minutos
3. Intentar usar el enlace
4. Verificar error: "Token expired..."
5. Verificar que muestra botón para solicitar nuevo enlace

### Test 4: Contraseñas No Coinciden

1. Llegar a `/reset-password` con token válido
2. Ingresar password: `MiContraseña123`
3. Ingresar confirmation: `OtraContraseña456`
4. Intentar enviar
5. Verificar error: "Las contraseñas no coinciden"

---

## Resumen de Endpoints

| Endpoint | Método | Autenticado | Descripción |
|----------|--------|-------------|-------------|
| `/api/v1/password/forgot` | POST | ❌ No | Solicita reset de contraseña |
| `/api/v1/password/reset` | POST | ❌ No | Confirma reset con token |

**Nota:** Ambos endpoints son **públicos** (no requieren token JWT de autenticación).
