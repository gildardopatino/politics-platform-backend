# API de Roles y Permisos - Sistema Multi-Tenant

Esta documentación describe los endpoints para gestionar roles y permisos en el sistema. Los **roles son por tenant** (cada organización administra sus propios roles), mientras que los **permisos son globales** (definidos a nivel del sistema).

---

## 📌 ÍNDICE

1. [Roles](#roles)
   - [Listar Roles](#1-get-apiv1roles---listar-roles)
   - [Crear Rol](#2-post-apiv1roles---crear-rol)
   - [Ver Detalle de Rol](#3-get-apiv1rolesid---ver-detalle-de-rol)
   - [Actualizar Rol](#4-put-apiv1rolesid---actualizar-rol)
   - [Eliminar Rol](#5-delete-apiv1rolesid---eliminar-rol)
   - [Asignar Permisos a Rol](#6-post-apiv1rolesidassign-permissions---asignar-permisos)
2. [Permisos](#permisos)
   - [Listar Permisos](#1-get-apiv1permissions---listar-permisos)

---

# ROLES

Los roles son específicos por tenant. Cada organización puede crear y gestionar sus propios roles.

## 1. GET /api/v1/roles - Listar Roles

Lista todos los roles del tenant actual con sus permisos asignados.

### Request

```http
GET /api/v1/roles?per_page=15&search=admin
Authorization: Bearer {token}
```

### Query Parameters

| Parámetro | Tipo   | Requerido | Descripción                    |
|-----------|--------|-----------|--------------------------------|
| per_page  | int    | ❌ No     | Cantidad de registros por página (default: 15) |
| search    | string | ❌ No     | Buscar por nombre de rol       |

### Response 200 OK

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "admin",
      "guard_name": "web",
      "tenant_id": 1,
      "created_at": "2025-11-06T08:30:00.000000Z",
      "updated_at": "2025-11-06T08:30:00.000000Z",
      "permissions": [
        {
          "id": 1,
          "name": "view_users"
        },
        {
          "id": 2,
          "name": "create_users"
        },
        {
          "id": 3,
          "name": "edit_users"
        },
        {
          "id": 4,
          "name": "delete_users"
        },
        {
          "id": 5,
          "name": "view_meetings"
        }
      ]
    },
    {
      "id": 2,
      "name": "coordinator",
      "guard_name": "web",
      "tenant_id": 1,
      "created_at": "2025-11-06T08:31:00.000000Z",
      "updated_at": "2025-11-06T08:31:00.000000Z",
      "permissions": [
        {
          "id": 1,
          "name": "view_users"
        },
        {
          "id": 5,
          "name": "view_meetings"
        },
        {
          "id": 6,
          "name": "create_meetings"
        }
      ]
    }
  ],
  "pagination": {
    "total": 4,
    "per_page": 15,
    "current_page": 1,
    "last_page": 1,
    "from": 1,
    "to": 4
  }
}
```

---

## 2. POST /api/v1/roles - Crear Rol

Crea un nuevo rol para el tenant actual con permisos opcionales.

### Request

```json
{
  "name": "supervisor",
  "permissions": [1, 2, 5, 6, 9, 10]
}
```

### Validaciones

| Campo       | Tipo   | Requerido | Validaciones                                        |
|-------------|--------|-----------|-----------------------------------------------------|
| name        | string | ✅ Sí     | - Requerido<br>- Máximo 255 caracteres<br>- Único por tenant |
| permissions | array  | ❌ No     | - Debe ser array<br>- Cada ID debe existir en tabla permissions |

### Response 201 Created

```json
{
  "success": true,
  "message": "Rol creado exitosamente",
  "data": {
    "id": 5,
    "name": "supervisor",
    "guard_name": "web",
    "tenant_id": 1,
    "created_at": "2025-11-06T09:00:00.000000Z",
    "updated_at": "2025-11-06T09:00:00.000000Z",
    "permissions": [
      {
        "id": 1,
        "name": "view_users"
      },
      {
        "id": 2,
        "name": "create_users"
      },
      {
        "id": 5,
        "name": "view_meetings"
      },
      {
        "id": 6,
        "name": "create_meetings"
      },
      {
        "id": 9,
        "name": "view_campaigns"
      },
      {
        "id": 10,
        "name": "create_campaigns"
      }
    ]
  }
}
```

### Response 422 Validation Error

```json
{
  "success": false,
  "errors": {
    "name": [
      "Ya existe un rol con este nombre en tu organización"
    ],
    "permissions.2": [
      "Uno o más permisos seleccionados no existen"
    ]
  }
}
```

---

## 3. GET /api/v1/roles/{id} - Ver Detalle de Rol

Obtiene los detalles completos de un rol, incluyendo permisos y usuarios asignados.

### Request

```http
GET /api/v1/roles/1
Authorization: Bearer {token}
```

### Response 200 OK

```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "admin",
    "guard_name": "web",
    "tenant_id": 1,
    "created_at": "2025-11-06T08:30:00.000000Z",
    "updated_at": "2025-11-06T08:30:00.000000Z",
    "permissions": [
      {
        "id": 1,
        "name": "view_users"
      },
      {
        "id": 2,
        "name": "create_users"
      },
      {
        "id": 3,
        "name": "edit_users"
      },
      {
        "id": 4,
        "name": "delete_users"
      }
    ],
    "users": [
      {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
      },
      {
        "id": 2,
        "name": "Gildardo Patiño",
        "email": "gildardo@example.com"
      }
    ],
    "users_count": 2
  }
}
```

### Response 404 Not Found

```json
{
  "success": false,
  "message": "Rol no encontrado"
}
```

---

## 4. PUT /api/v1/roles/{id} - Actualizar Rol

Actualiza el nombre y/o permisos de un rol existente.

### Request

```json
{
  "name": "supervisor_general",
  "permissions": [1, 2, 3, 5, 6, 7, 9, 10, 11]
}
```

### Validaciones

| Campo       | Tipo   | Requerido | Validaciones                                        |
|-------------|--------|-----------|-----------------------------------------------------|
| name        | string | ✅ Sí     | - Requerido<br>- Máximo 255 caracteres<br>- Único por tenant (excepto el rol actual) |
| permissions | array  | ❌ No     | - Debe ser array<br>- Cada ID debe existir en tabla permissions |

### Response 200 OK

```json
{
  "success": true,
  "message": "Rol actualizado exitosamente",
  "data": {
    "id": 5,
    "name": "supervisor_general",
    "guard_name": "web",
    "tenant_id": 1,
    "created_at": "2025-11-06T09:00:00.000000Z",
    "updated_at": "2025-11-06T09:15:00.000000Z",
    "permissions": [
      {
        "id": 1,
        "name": "view_users"
      },
      {
        "id": 2,
        "name": "create_users"
      },
      {
        "id": 3,
        "name": "edit_users"
      },
      {
        "id": 5,
        "name": "view_meetings"
      },
      {
        "id": 6,
        "name": "create_meetings"
      },
      {
        "id": 7,
        "name": "edit_meetings"
      },
      {
        "id": 9,
        "name": "view_campaigns"
      },
      {
        "id": 10,
        "name": "create_campaigns"
      },
      {
        "id": 11,
        "name": "edit_campaigns"
      }
    ]
  }
}
```

### Response 422 Validation Error

```json
{
  "success": false,
  "errors": {
    "name": [
      "Ya existe un rol con este nombre en tu organización"
    ]
  }
}
```

---

## 5. DELETE /api/v1/roles/{id} - Eliminar Rol

Elimina un rol. No se puede eliminar si tiene usuarios asignados.

### Request

```http
DELETE /api/v1/roles/5
Authorization: Bearer {token}
```

### Response 200 OK

```json
{
  "success": true,
  "message": "Rol eliminado exitosamente"
}
```

### Response 422 Unprocessable Entity - Tiene usuarios asignados

```json
{
  "success": false,
  "message": "No se puede eliminar el rol porque tiene 5 usuario(s) asignado(s)"
}
```

### Response 404 Not Found

```json
{
  "success": false,
  "message": "Rol no encontrado"
}
```

---

## 6. POST /api/v1/roles/{id}/assign-permissions - Asignar Permisos

Asigna o sincroniza permisos a un rol. Reemplaza todos los permisos actuales con la nueva lista.

### Request

```json
{
  "permissions": [1, 2, 3, 4, 5, 6, 7, 8]
}
```

### Validaciones

| Campo            | Tipo  | Requerido | Validaciones                                        |
|------------------|-------|-----------|-----------------------------------------------------|
| permissions      | array | ✅ Sí     | - Requerido<br>- Debe tener al menos 1 elemento<br>- Cada ID debe existir en tabla permissions |

### Response 200 OK

```json
{
  "success": true,
  "message": "Permisos asignados exitosamente",
  "data": {
    "id": 2,
    "name": "coordinator",
    "guard_name": "web",
    "tenant_id": 1,
    "created_at": "2025-11-06T08:31:00.000000Z",
    "updated_at": "2025-11-06T08:31:00.000000Z",
    "permissions": [
      {
        "id": 1,
        "name": "view_users"
      },
      {
        "id": 2,
        "name": "create_users"
      },
      {
        "id": 3,
        "name": "edit_users"
      },
      {
        "id": 4,
        "name": "delete_users"
      },
      {
        "id": 5,
        "name": "view_meetings"
      },
      {
        "id": 6,
        "name": "create_meetings"
      },
      {
        "id": 7,
        "name": "edit_meetings"
      },
      {
        "id": 8,
        "name": "delete_meetings"
      }
    ]
  }
}
```

### Response 422 Validation Error

```json
{
  "success": false,
  "errors": {
    "permissions": [
      "Debe seleccionar al menos un permiso"
    ],
    "permissions.5": [
      "Uno o más permisos seleccionados no existen"
    ]
  }
}
```

---

# PERMISOS

Los permisos son globales (no son por tenant). Están definidos a nivel del sistema y no pueden ser creados ni eliminados por los usuarios.

## 1. GET /api/v1/permissions - Listar Permisos

Obtiene la lista completa de permisos disponibles en el sistema.

### Request Simple

```http
GET /api/v1/permissions
Authorization: Bearer {token}
```

### Response 200 OK - Lista Simple

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "view_users",
      "display_name": "Ver Usuarios"
    },
    {
      "id": 2,
      "name": "create_users",
      "display_name": "Crear Usuarios"
    },
    {
      "id": 3,
      "name": "edit_users",
      "display_name": "Editar Usuarios"
    },
    {
      "id": 4,
      "name": "delete_users",
      "display_name": "Eliminar Usuarios"
    },
    {
      "id": 5,
      "name": "view_meetings",
      "display_name": "Ver Reuniones"
    },
    {
      "id": 6,
      "name": "create_meetings",
      "display_name": "Crear Reuniones"
    },
    {
      "id": 7,
      "name": "edit_meetings",
      "display_name": "Editar Reuniones"
    },
    {
      "id": 8,
      "name": "delete_meetings",
      "display_name": "Eliminar Reuniones"
    },
    {
      "id": 9,
      "name": "view_campaigns",
      "display_name": "Ver Campañas"
    },
    {
      "id": 10,
      "name": "create_campaigns",
      "display_name": "Crear Campañas"
    },
    {
      "id": 11,
      "name": "edit_campaigns",
      "display_name": "Editar Campañas"
    },
    {
      "id": 12,
      "name": "delete_campaigns",
      "display_name": "Eliminar Campañas"
    },
    {
      "id": 13,
      "name": "view_commitments",
      "display_name": "Ver Compromisos"
    },
    {
      "id": 14,
      "name": "create_commitments",
      "display_name": "Crear Compromisos"
    },
    {
      "id": 15,
      "name": "edit_commitments",
      "display_name": "Editar Compromisos"
    },
    {
      "id": 16,
      "name": "delete_commitments",
      "display_name": "Eliminar Compromisos"
    },
    {
      "id": 17,
      "name": "view_resources",
      "display_name": "Ver Recursos"
    },
    {
      "id": 18,
      "name": "create_resources",
      "display_name": "Crear Recursos"
    },
    {
      "id": 19,
      "name": "edit_resources",
      "display_name": "Editar Recursos"
    },
    {
      "id": 20,
      "name": "delete_resources",
      "display_name": "Eliminar Recursos"
    },
    {
      "id": 21,
      "name": "view_reports",
      "display_name": "Ver Reportes"
    }
  ]
}
```

### Request Agrupado por Categoría

```http
GET /api/v1/permissions?group_by_category=true
Authorization: Bearer {token}
```

### Query Parameters

| Parámetro         | Tipo    | Requerido | Descripción                    |
|-------------------|---------|-----------|--------------------------------|
| group_by_category | boolean | ❌ No     | Agrupar permisos por categoría (default: false) |

### Response 200 OK - Agrupado por Categoría

```json
{
  "success": true,
  "data": [
    {
      "category": "users",
      "permissions": [
        {
          "id": 1,
          "name": "view_users",
          "display_name": "Ver Usuarios"
        },
        {
          "id": 2,
          "name": "create_users",
          "display_name": "Crear Usuarios"
        },
        {
          "id": 3,
          "name": "edit_users",
          "display_name": "Editar Usuarios"
        },
        {
          "id": 4,
          "name": "delete_users",
          "display_name": "Eliminar Usuarios"
        }
      ]
    },
    {
      "category": "meetings",
      "permissions": [
        {
          "id": 5,
          "name": "view_meetings",
          "display_name": "Ver Reuniones"
        },
        {
          "id": 6,
          "name": "create_meetings",
          "display_name": "Crear Reuniones"
        },
        {
          "id": 7,
          "name": "edit_meetings",
          "display_name": "Editar Reuniones"
        },
        {
          "id": 8,
          "name": "delete_meetings",
          "display_name": "Eliminar Reuniones"
        }
      ]
    },
    {
      "category": "campaigns",
      "permissions": [
        {
          "id": 9,
          "name": "view_campaigns",
          "display_name": "Ver Campañas"
        },
        {
          "id": 10,
          "name": "create_campaigns",
          "display_name": "Crear Campañas"
        },
        {
          "id": 11,
          "name": "edit_campaigns",
          "display_name": "Editar Campañas"
        },
        {
          "id": 12,
          "name": "delete_campaigns",
          "display_name": "Eliminar Campañas"
        }
      ]
    },
    {
      "category": "commitments",
      "permissions": [
        {
          "id": 13,
          "name": "view_commitments",
          "display_name": "Ver Compromisos"
        },
        {
          "id": 14,
          "name": "create_commitments",
          "display_name": "Crear Compromisos"
        },
        {
          "id": 15,
          "name": "edit_commitments",
          "display_name": "Editar Compromisos"
        },
        {
          "id": 16,
          "name": "delete_commitments",
          "display_name": "Eliminar Compromisos"
        }
      ]
    },
    {
      "category": "resources",
      "permissions": [
        {
          "id": 17,
          "name": "view_resources",
          "display_name": "Ver Recursos"
        },
        {
          "id": 18,
          "name": "create_resources",
          "display_name": "Crear Recursos"
        },
        {
          "id": 19,
          "name": "edit_resources",
          "display_name": "Editar Recursos"
        },
        {
          "id": 20,
          "name": "delete_resources",
          "display_name": "Eliminar Recursos"
        }
      ]
    },
    {
      "category": "reports",
      "permissions": [
        {
          "id": 21,
          "name": "view_reports",
          "display_name": "Ver Reportes"
        }
      ]
    }
  ]
}
```

---

## 📝 NOTAS IMPORTANTES

### Tenant Scope en Roles

- ✅ Los **roles son por tenant**: cada organización gestiona sus propios roles
- ✅ El `TenantScope` se aplica automáticamente a todas las consultas
- ✅ El `tenant_id` se asigna automáticamente al crear un rol
- ❌ No se pueden ver ni modificar roles de otros tenants

### Permisos Globales

- ✅ Los **permisos son globales**: definidos a nivel del sistema
- ❌ **No se pueden crear** nuevos permisos desde la API
- ❌ **No se pueden eliminar** permisos existentes
- ✅ Están disponibles para todos los tenants
- ✅ Se definen mediante seeder en `RolesAndPermissionsSeeder`

### Nombres de Permisos

Los permisos siguen el patrón: `{acción}_{recurso}`

**Acciones disponibles:**
- `view` - Ver/Listar
- `create` - Crear
- `edit` - Editar
- `delete` - Eliminar

**Recursos disponibles:**
- `users` - Usuarios
- `meetings` - Reuniones
- `campaigns` - Campañas
- `commitments` - Compromisos
- `resources` - Recursos
- `reports` - Reportes
- `voters` - Votantes (futuro)
- `surveys` - Encuestas (futuro)
- `calls` - Llamadas (futuro)

### Validación de Unicidad

- El nombre del rol debe ser único **por tenant**
- Dos tenants diferentes pueden tener roles con el mismo nombre
- La validación se hace con: `unique:roles,name,NULL,id,tenant_id,{tenant_id}`

### Restricciones de Eliminación

- No se puede eliminar un rol que tenga usuarios asignados
- Primero se deben reasignar los usuarios a otro rol
- El sistema retorna error 422 indicando cuántos usuarios están asignados

### Display Names

- Los permisos incluyen un `display_name` traducido al español
- Útil para mostrar en interfaces de usuario
- Se genera automáticamente desde el nombre técnico

---

## 🔧 CÓDIGOS HTTP

| Código | Descripción                                    |
|--------|------------------------------------------------|
| 200    | OK - Operación exitosa                         |
| 201    | Created - Recurso creado exitosamente          |
| 404    | Not Found - Recurso no encontrado              |
| 422    | Unprocessable Entity - Error de validación     |
| 500    | Internal Server Error - Error del servidor     |

---

## 🔐 AUTENTICACIÓN

Todos los endpoints requieren:
```
Authorization: Bearer {JWT_TOKEN}
```

Y el middleware `tenant` para verificar que el usuario pertenece a un tenant válido.
