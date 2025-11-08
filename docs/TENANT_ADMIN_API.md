# API de Administración de Tenants

Documentación completa de los endpoints de administración de tenants (candidatos) del sistema.

**Fecha:** 8 de Noviembre, 2025  
**Versión:** 1.0

---

## Índice

1. [Introducción](#introducción)
2. [Gestión de Tenants (Super Admin)](#gestión-de-tenants-super-admin)
3. [Configuración de Tenant (Self-Service)](#configuración-de-tenant-self-service)

---

## Introducción

El sistema maneja dos niveles de administración de tenants:

1. **Super Admin**: Puede crear, ver, actualizar y eliminar cualquier tenant
2. **Tenant Admin**: Puede ver y actualizar la configuración de su propio tenant

---

## Gestión de Tenants (Super Admin)

Endpoints disponibles **solo para usuarios con rol Super Admin**.

### 1. Listar Todos los Tenants

**Endpoint:** `GET /api/v1/tenants`

**Permisos:** Super Admin únicamente

**Headers:**
```json
{
  "Authorization": "Bearer {super_admin_token}",
  "Content-Type": "application/json"
}
```

**Query Parameters (opcionales):**
```
per_page: 15 (cantidad de resultados por página, default: 15)
page: 1 (número de página, default: 1)
filter[nombre]: "Juan" (filtrar por nombre)
filter[tipo_cargo]: "Alcalde" (filtrar por tipo de cargo)
filter[identificacion]: "123456789" (filtrar por identificación)
sort: "nombre" o "-nombre" (ordenar, - para descendente)
sort: "created_at" o "-created_at"
```

**Ejemplo de request:**
```bash
GET /api/v1/tenants?per_page=20&filter[tipo_cargo]=Alcalde&sort=-created_at
```

**Respuesta exitosa (200):**
```json
{
  "data": [
    {
      "id": 1,
      "slug": "juan-perez-2025",
      "nombre": "Juan Carlos Pérez",
      "tipo_cargo": "Alcalde",
      "identificacion": "123456789",
      "logo": "https://wasabi.url/tenants/logo1.png",
      "sidebar_bg_color": "#1E3A8A",
      "sidebar_text_color": "#FFFFFF",
      "header_bg_color": "#3B82F6",
      "header_text_color": "#FFFFFF",
      "content_bg_color": "#F3F4F6",
      "content_text_color": "#111827",
      "hierarchy_mode": "manual",
      "auto_assign_hierarchy": false,
      "hierarchy_conflict_resolution": "keep_both",
      "require_hierarchy_config": true,
      "biografia_data": {
        "nombre": "Juan Carlos Pérez",
        "cargo": "Candidato a Alcalde",
        "imagen": "https://wasabi.url/juan-perez-2025/landing/biografia/perfil.jpg"
      },
      "created_at": "2025-10-01T10:00:00.000000Z",
      "updated_at": "2025-11-08T10:00:00.000000Z"
    },
    {
      "id": 2,
      "slug": "maria-lopez-2025",
      "nombre": "María López",
      "tipo_cargo": "Gobernadora",
      "identificacion": "987654321",
      "logo": "https://wasabi.url/tenants/logo2.png",
      "sidebar_bg_color": "#7C3AED",
      "sidebar_text_color": "#FFFFFF",
      "header_bg_color": "#A78BFA",
      "header_text_color": "#FFFFFF",
      "content_bg_color": "#F9FAFB",
      "content_text_color": "#111827",
      "hierarchy_mode": "automatic",
      "auto_assign_hierarchy": true,
      "hierarchy_conflict_resolution": "replace",
      "require_hierarchy_config": false,
      "biografia_data": null,
      "created_at": "2025-10-15T10:00:00.000000Z",
      "updated_at": "2025-11-05T10:00:00.000000Z"
    }
  ],
  "meta": {
    "total": 25,
    "current_page": 1,
    "last_page": 2,
    "per_page": 20
  }
}
```

---

### 2. Ver Tenant Específico

**Endpoint:** `GET /api/v1/tenants/{id}`

**Permisos:** Super Admin únicamente

**Headers:**
```json
{
  "Authorization": "Bearer {super_admin_token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "slug": "juan-perez-2025",
    "nombre": "Juan Carlos Pérez",
    "tipo_cargo": "Alcalde",
    "identificacion": "123456789",
    "logo": "https://wasabi.url/tenants/logo1.png",
    "sidebar_bg_color": "#1E3A8A",
    "sidebar_text_color": "#FFFFFF",
    "header_bg_color": "#3B82F6",
    "header_text_color": "#FFFFFF",
    "content_bg_color": "#F3F4F6",
    "content_text_color": "#111827",
    "hierarchy_mode": "manual",
    "auto_assign_hierarchy": false,
    "hierarchy_conflict_resolution": "keep_both",
    "require_hierarchy_config": true,
    "biografia_data": {
      "nombre": "Juan Carlos Pérez",
      "cargo": "Candidato a Alcalde",
      "imagen": "https://wasabi.url/juan-perez-2025/landing/biografia/perfil.jpg",
      "quienEs": {
        "titulo": "¿Quién es Juan Carlos?",
        "descripcion": "Líder comunitario..."
      }
    },
    "users": [
      {
        "id": 1,
        "name": "Admin User",
        "email": "admin@juanperez.com"
      }
    ],
    "meetings": [
      {
        "id": 1,
        "title": "Reunión Comunitaria",
        "date": "2025-11-15"
      }
    ],
    "campaigns": [
      {
        "id": 1,
        "name": "Campaña SMS Noviembre",
        "status": "draft"
      }
    ],
    "created_at": "2025-10-01T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  }
}
```

---

### 3. Crear Nuevo Tenant

**Endpoint:** `POST /api/v1/tenants`

**Permisos:** Super Admin únicamente

**Headers:**
```json
{
  "Authorization": "Bearer {super_admin_token}",
  "Content-Type": "application/json"
}
```

**Body (JSON):**
```json
{
  "slug": "pedro-gomez-2025",
  "nombre": "Pedro Gómez",
  "tipo_cargo": "Alcalde",
  "identificacion": "456789123",
  "logo": "https://example.com/logo.png",
  "sidebar_bg_color": "#1E3A8A",
  "sidebar_text_color": "#FFFFFF",
  "header_bg_color": "#3B82F6",
  "header_text_color": "#FFFFFF",
  "content_bg_color": "#F3F4F6",
  "content_text_color": "#111827",
  "hierarchy_mode": "manual",
  "auto_assign_hierarchy": false,
  "hierarchy_conflict_resolution": "keep_both",
  "require_hierarchy_config": true
}
```

**Campos requeridos:**
- `slug` (string, único, lowercase, sin espacios, max:100)
- `nombre` (string, max:255)
- `tipo_cargo` (string, max:100)
- `identificacion` (string, único, max:50)

**Campos opcionales:**
- `logo` (string URL, max:500)
- `sidebar_bg_color` (string hex color, default: #1E3A8A)
- `sidebar_text_color` (string hex color, default: #FFFFFF)
- `header_bg_color` (string hex color, default: #3B82F6)
- `header_text_color` (string hex color, default: #FFFFFF)
- `content_bg_color` (string hex color, default: #F3F4F6)
- `content_text_color` (string hex color, default: #111827)
- `hierarchy_mode` (enum: disabled|manual|automatic, default: manual)
- `auto_assign_hierarchy` (boolean, default: false)
- `hierarchy_conflict_resolution` (enum: keep_both|replace|newest, default: keep_both)
- `require_hierarchy_config` (boolean, default: true)

**Respuesta exitosa (201):**
```json
{
  "data": {
    "id": 3,
    "slug": "pedro-gomez-2025",
    "nombre": "Pedro Gómez",
    "tipo_cargo": "Alcalde",
    "identificacion": "456789123",
    "logo": "https://example.com/logo.png",
    "sidebar_bg_color": "#1E3A8A",
    "sidebar_text_color": "#FFFFFF",
    "header_bg_color": "#3B82F6",
    "header_text_color": "#FFFFFF",
    "content_bg_color": "#F3F4F6",
    "content_text_color": "#111827",
    "hierarchy_mode": "manual",
    "auto_assign_hierarchy": false,
    "hierarchy_conflict_resolution": "keep_both",
    "require_hierarchy_config": true,
    "biografia_data": null,
    "created_at": "2025-11-08T16:00:00.000000Z",
    "updated_at": "2025-11-08T16:00:00.000000Z"
  },
  "message": "Tenant created successfully"
}
```

**Respuesta de error (422):**
```json
{
  "errors": {
    "slug": ["El slug ya está en uso."],
    "identificacion": ["La identificación ya está en uso."],
    "nombre": ["El campo nombre es obligatorio."]
  }
}
```

---

### 4. Actualizar Tenant

**Endpoint:** `PUT /api/v1/tenants/{id}`

**Permisos:** Super Admin únicamente

**Headers:**
```json
{
  "Authorization": "Bearer {super_admin_token}",
  "Content-Type": "application/json"
}
```

**Body (JSON):**
```json
{
  "nombre": "Pedro Antonio Gómez",
  "tipo_cargo": "Alcalde Municipal",
  "logo": "https://example.com/nuevo-logo.png",
  "sidebar_bg_color": "#7C3AED",
  "hierarchy_mode": "automatic",
  "require_hierarchy_config": false
}
```

**Nota:** Todos los campos son opcionales. Solo envía los que deseas actualizar.

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 3,
    "slug": "pedro-gomez-2025",
    "nombre": "Pedro Antonio Gómez",
    "tipo_cargo": "Alcalde Municipal",
    "identificacion": "456789123",
    "logo": "https://example.com/nuevo-logo.png",
    "sidebar_bg_color": "#7C3AED",
    "sidebar_text_color": "#FFFFFF",
    "header_bg_color": "#3B82F6",
    "header_text_color": "#FFFFFF",
    "content_bg_color": "#F3F4F6",
    "content_text_color": "#111827",
    "hierarchy_mode": "automatic",
    "auto_assign_hierarchy": false,
    "hierarchy_conflict_resolution": "keep_both",
    "require_hierarchy_config": false,
    "biografia_data": null,
    "created_at": "2025-11-08T16:00:00.000000Z",
    "updated_at": "2025-11-08T17:00:00.000000Z"
  },
  "message": "Tenant updated successfully"
}
```

---

### 5. Eliminar Tenant

**Endpoint:** `DELETE /api/v1/tenants/{id}`

**Permisos:** Super Admin únicamente

**Headers:**
```json
{
  "Authorization": "Bearer {super_admin_token}",
  "Content-Type": "application/json"
}
```

**⚠️ ADVERTENCIA:** Esta acción eliminará permanentemente:
- El tenant y toda su configuración
- Todos los usuarios asociados al tenant
- Todas las reuniones, campañas, votantes, etc.
- Todo el contenido de la landing page
- Todos los archivos almacenados en Wasabi

**Respuesta exitosa (200):**
```json
{
  "message": "Tenant deleted successfully"
}
```

**Respuesta de error (403):**
```json
{
  "message": "No tienes permisos para realizar esta acción"
}
```

---

## Configuración de Tenant (Self-Service)

Endpoints para que los usuarios administradores de un tenant gestionen su propia configuración.

### 1. Ver Configuración Actual

**Endpoint:** `GET /api/v1/tenant/settings`

**Permisos:** Usuario autenticado del tenant

**Headers:**
```json
{
  "Authorization": "Bearer {tenant_user_token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "slug": "juan-perez-2025",
    "nombre": "Juan Carlos Pérez",
    "tipo_cargo": "Alcalde",
    "identificacion": "123456789",
    "logo": "https://wasabi.url/tenants/logo1.png",
    "theme": {
      "sidebar_bg_color": "#1E3A8A",
      "sidebar_text_color": "#FFFFFF",
      "header_bg_color": "#3B82F6",
      "header_text_color": "#FFFFFF",
      "content_bg_color": "#F3F4F6",
      "content_text_color": "#111827"
    },
    "hierarchy_settings": {
      "hierarchy_mode": "manual",
      "auto_assign_hierarchy": false,
      "hierarchy_conflict_resolution": "keep_both",
      "require_hierarchy_config": true
    }
  }
}
```

---

### 2. Actualizar Configuración

**Endpoint:** `PUT /api/v1/tenant/settings`

**Permisos:** Usuario autenticado del tenant

**Headers:**
```json
{
  "Authorization": "Bearer {tenant_user_token}",
  "Content-Type": "application/json"
}
```

**Body (JSON):**
```json
{
  "nombre": "Juan Carlos Pérez Gómez",
  "tipo_cargo": "Candidato a Alcalde de Bogotá",
  "logo": "https://wasabi.url/tenants/nuevo-logo.png",
  "sidebar_bg_color": "#7C3AED",
  "sidebar_text_color": "#F3F4F6",
  "header_bg_color": "#A78BFA",
  "header_text_color": "#FFFFFF",
  "content_bg_color": "#FFFFFF",
  "content_text_color": "#1F2937",
  "hierarchy_mode": "automatic",
  "auto_assign_hierarchy": true,
  "hierarchy_conflict_resolution": "replace",
  "require_hierarchy_config": false
}
```

**Campos actualizables:**
- `nombre` (string, max:255)
- `tipo_cargo` (string, max:100)
- `logo` (string URL, max:500)
- `sidebar_bg_color` (string hex color)
- `sidebar_text_color` (string hex color)
- `header_bg_color` (string hex color)
- `header_text_color` (string hex color)
- `content_bg_color` (string hex color)
- `content_text_color` (string hex color)
- `hierarchy_mode` (enum: disabled|manual|automatic)
- `auto_assign_hierarchy` (boolean)
- `hierarchy_conflict_resolution` (enum: keep_both|replace|newest)
- `require_hierarchy_config` (boolean)

**Campos NO actualizables:**
- `slug` (solo puede cambiarlo el super admin)
- `identificacion` (solo puede cambiarlo el super admin)

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "slug": "juan-perez-2025",
    "nombre": "Juan Carlos Pérez Gómez",
    "tipo_cargo": "Candidato a Alcalde de Bogotá",
    "identificacion": "123456789",
    "logo": "https://wasabi.url/tenants/nuevo-logo.png",
    "theme": {
      "sidebar_bg_color": "#7C3AED",
      "sidebar_text_color": "#F3F4F6",
      "header_bg_color": "#A78BFA",
      "header_text_color": "#FFFFFF",
      "content_bg_color": "#FFFFFF",
      "content_text_color": "#1F2937"
    },
    "hierarchy_settings": {
      "hierarchy_mode": "automatic",
      "auto_assign_hierarchy": true,
      "hierarchy_conflict_resolution": "replace",
      "require_hierarchy_config": false
    }
  },
  "message": "Tenant settings updated successfully"
}
```

**Respuesta de error (403):**
```json
{
  "message": "You can only update your own tenant settings."
}
```

---

### 3. Verificar Configuración de Jerarquía

**Endpoint:** `GET /api/v1/tenant/hierarchy-config/check`

**Permisos:** Usuario autenticado del tenant

**Headers:**
```json
{
  "Authorization": "Bearer {tenant_user_token}",
  "Content-Type": "application/json"
}
```

**Descripción:** Verifica si la jerarquía está configurada y si el tenant puede crear reuniones.

**Respuesta exitosa (200) - Configurada:**
```json
{
  "data": {
    "is_configured": true,
    "requires_configuration": true,
    "can_create_meetings": true,
    "hierarchy_mode": "manual",
    "message": "La jerarquía está configurada correctamente."
  }
}
```

**Respuesta exitosa (200) - No configurada pero requerida:**
```json
{
  "data": {
    "is_configured": false,
    "requires_configuration": true,
    "can_create_meetings": false,
    "hierarchy_mode": "disabled",
    "message": "Debe configurar la jerarquía antes de crear reuniones."
  }
}
```

**Respuesta exitosa (200) - No configurada y no requerida:**
```json
{
  "data": {
    "is_configured": false,
    "requires_configuration": false,
    "can_create_meetings": true,
    "hierarchy_mode": "disabled",
    "message": "La jerarquía no está configurada, pero no es obligatoria."
  }
}
```

---

## Configuración de Jerarquía

### Modos de Jerarquía

El sistema soporta 3 modos de jerarquía:

#### 1. `disabled` (Deshabilitado)
- No se utiliza jerarquía
- Los asistentes no tienen relaciones padre-hijo
- Más simple pero menos funcional

#### 2. `manual` (Manual)
- El usuario debe asignar manualmente las relaciones
- Control total sobre la estructura
- Recomendado para estructuras complejas

#### 3. `automatic` (Automático)
- El sistema crea automáticamente relaciones basadas en:
  - El usuario que creó la reunión
  - Los asistentes añadidos
- Menos control pero más rápido

### Auto-asignación de Jerarquía

**`auto_assign_hierarchy` (boolean)**
- `true`: Al agregar un asistente a una reunión, se crea automáticamente una relación con el líder
- `false`: Las relaciones deben crearse manualmente

### Resolución de Conflictos

**`hierarchy_conflict_resolution` (enum)**

Cuando un asistente ya tiene un padre y se intenta asignar otro:

#### `keep_both`
- Mantiene ambas relaciones
- Un asistente puede tener múltiples padres
- Más flexible

#### `replace`
- Reemplaza la relación anterior con la nueva
- Un asistente solo puede tener un padre
- Más simple

#### `newest`
- Mantiene solo la relación más reciente
- Similar a `replace` pero basado en fecha
- Útil para estructuras cambiantes

### Requerir Configuración

**`require_hierarchy_config` (boolean)**
- `true`: No se pueden crear reuniones hasta configurar la jerarquía
- `false`: Se pueden crear reuniones sin configurar jerarquía

---

## Personalización de Tema (Theme)

### Colores Disponibles

El sistema permite personalizar 6 colores del tema:

#### 1. `sidebar_bg_color`
- Color de fondo del sidebar/menú lateral
- Default: `#1E3A8A` (azul oscuro)
- Ejemplos: `#1E3A8A`, `#7C3AED`, `#059669`

#### 2. `sidebar_text_color`
- Color del texto en el sidebar
- Default: `#FFFFFF` (blanco)
- Debe contrastar con `sidebar_bg_color`

#### 3. `header_bg_color`
- Color de fondo del header/navbar
- Default: `#3B82F6` (azul)
- Ejemplos: `#3B82F6`, `#A78BFA`, `#10B981`

#### 4. `header_text_color`
- Color del texto en el header
- Default: `#FFFFFF` (blanco)
- Debe contrastar con `header_bg_color`

#### 5. `content_bg_color`
- Color de fondo del área de contenido
- Default: `#F3F4F6` (gris claro)
- Ejemplos: `#F3F4F6`, `#FFFFFF`, `#F9FAFB`

#### 6. `content_text_color`
- Color del texto del contenido
- Default: `#111827` (gris oscuro/negro)
- Debe contrastar con `content_bg_color`

### Paletas de Colores Recomendadas

#### Tema Azul Profesional (Default)
```json
{
  "sidebar_bg_color": "#1E3A8A",
  "sidebar_text_color": "#FFFFFF",
  "header_bg_color": "#3B82F6",
  "header_text_color": "#FFFFFF",
  "content_bg_color": "#F3F4F6",
  "content_text_color": "#111827"
}
```

#### Tema Verde Esperanza
```json
{
  "sidebar_bg_color": "#065F46",
  "sidebar_text_color": "#FFFFFF",
  "header_bg_color": "#10B981",
  "header_text_color": "#FFFFFF",
  "content_bg_color": "#ECFDF5",
  "content_text_color": "#064E3B"
}
```

#### Tema Púrpura Moderno
```json
{
  "sidebar_bg_color": "#5B21B6",
  "sidebar_text_color": "#F3E8FF",
  "header_bg_color": "#A78BFA",
  "header_text_color": "#FFFFFF",
  "content_bg_color": "#FAF5FF",
  "content_text_color": "#3B0764"
}
```

#### Tema Rojo Poder
```json
{
  "sidebar_bg_color": "#991B1B",
  "sidebar_text_color": "#FFFFFF",
  "header_bg_color": "#EF4444",
  "header_text_color": "#FFFFFF",
  "content_bg_color": "#FEF2F2",
  "content_text_color": "#7F1D1D"
}
```

---

## Ejemplos de Uso

### Ejemplo 1: Super Admin Crea un Nuevo Tenant

```javascript
// JavaScript/Node.js
const crearTenant = async () => {
  const response = await fetch('https://api.example.com/api/v1/tenants', {
    method: 'POST',
    headers: {
      'Authorization': 'Bearer super_admin_token_here',
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      slug: 'candidato-2025',
      nombre: 'Candidato Ejemplo',
      tipo_cargo: 'Alcalde',
      identificacion: '987654321',
      hierarchy_mode: 'automatic',
      require_hierarchy_config: false,
      sidebar_bg_color: '#7C3AED',
      header_bg_color: '#A78BFA'
    })
  });

  const data = await response.json();
  console.log('Tenant creado:', data);
};
```

### Ejemplo 2: Tenant Actualiza Su Configuración

```javascript
// React ejemplo
const ActualizarConfiguracion = () => {
  const [config, setConfig] = useState({});

  const guardarCambios = async () => {
    const response = await fetch('https://api.example.com/api/v1/tenant/settings', {
      method: 'PUT',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        nombre: config.nombre,
        sidebar_bg_color: config.sidebarColor,
        header_bg_color: config.headerColor,
        hierarchy_mode: config.hierarchyMode
      })
    });

    if (response.ok) {
      alert('Configuración actualizada correctamente');
    }
  };

  return (
    <div>
      <input 
        value={config.nombre}
        onChange={(e) => setConfig({...config, nombre: e.target.value})}
        placeholder="Nombre del candidato"
      />
      <input 
        type="color"
        value={config.sidebarColor}
        onChange={(e) => setConfig({...config, sidebarColor: e.target.value})}
      />
      <select 
        value={config.hierarchyMode}
        onChange={(e) => setConfig({...config, hierarchyMode: e.target.value})}
      >
        <option value="disabled">Sin jerarquía</option>
        <option value="manual">Manual</option>
        <option value="automatic">Automática</option>
      </select>
      <button onClick={guardarCambios}>Guardar</button>
    </div>
  );
};
```

### Ejemplo 3: Verificar Configuración de Jerarquía

```javascript
// Verificar antes de crear una reunión
const puedeCrearReunion = async () => {
  const response = await fetch('https://api.example.com/api/v1/tenant/hierarchy-config/check', {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });

  const data = await response.json();

  if (!data.data.can_create_meetings) {
    alert(data.data.message);
    // Redirigir a configuración de jerarquía
    window.location.href = '/configuracion/jerarquia';
    return false;
  }

  return true;
};
```

---

## Notas Importantes

### Permisos
- **Super Admin**: Acceso total a todos los endpoints
- **Tenant Admin**: Solo puede ver/actualizar su propio tenant
- **Usuarios regulares**: No tienen acceso a endpoints de configuración

### Slug del Tenant
- Debe ser único en el sistema
- Solo lowercase, sin espacios, sin caracteres especiales
- Se utiliza para identificar públicamente al tenant
- **No puede cambiarse** excepto por el super admin

### Jerarquía
- Es crítica para el funcionamiento del sistema de reuniones
- Debe configurarse antes de usar funcionalidades avanzadas
- Los cambios afectan todas las reuniones futuras

### Colores del Tema
- Deben ser códigos hexadecimales válidos (#RRGGBB)
- Se recomienda verificar el contraste para accesibilidad
- Los cambios se aplican inmediatamente en el frontend

### Eliminación de Tenants
- **Acción irreversible**
- Solo super admin puede eliminar
- Se eliminan TODOS los datos asociados
- Usar con extremo cuidado

---

**Fin de la Documentación de Administración de Tenants**
