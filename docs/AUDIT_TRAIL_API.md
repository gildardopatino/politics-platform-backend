# API de Auditoría - Sistema de Seguimiento de Actividades

## Descripción General

Este documento describe la API de auditoría que registra automáticamente todas las acciones realizadas en el sistema. El sistema captura quién realizó cada acción, cuándo, desde qué dirección IP, y qué cambios específicos se hicieron en los registros.

## ⚠️ Requisito de Permisos

**IMPORTANTE**: Para acceder a cualquier endpoint de auditorías, el usuario debe tener el permiso **`view_audits`**. 

- Este permiso está asignado por defecto al rol **`admin`**
- Usuarios sin este permiso recibirán un error 403 (Forbidden)
- Para asignar el permiso a otros roles:
  ```php
  $role->givePermissionTo('view_audits');
  ```

## Características

- ✅ **Registro Automático**: Todas las operaciones de creación, actualización y eliminación se registran automáticamente
- ✅ **Trazabilidad Completa**: Usuario, fecha/hora, IP, user agent
- ✅ **Cambios Detallados**: Valores anteriores y nuevos (before/after)
- ✅ **Filtros Avanzados**: Por usuario, fecha, modelo, tipo de acción
- ✅ **Multi-tenant**: Cada tenant solo ve sus propias auditorías
- ✅ **Estadísticas**: Dashboard con métricas de actividad
- ✅ **Control de Acceso**: Solo usuarios con permiso `view_audits` pueden acceder

## Modelos Auditados

Los siguientes modelos están siendo auditados:

- `User` - Usuarios del sistema
- `Meeting` - Reuniones
- `Commitment` - Compromisos
- `Campaign` - Campañas
- `ResourceItem` - Recursos
- `Voter` - Votantes

## Endpoints

### 1. Listar Todas las Auditorías

```http
GET /api/v1/audits
```

**Parámetros de Query (Filtros)**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `user_id` | integer | ID del usuario que realizó la acción |
| `model` | string | Nombre del modelo (User, Meeting, Campaign, etc.) |
| `event` | string | Tipo de evento: `created`, `updated`, `deleted`, `restored` |
| `start_date` | date | Fecha inicial (formato: YYYY-MM-DD) |
| `end_date` | date | Fecha final (formato: YYYY-MM-DD) |
| `ip_address` | string | Dirección IP específica |
| `auditable_id` | integer | ID del registro auditado |
| `per_page` | integer | Resultados por página (default: 15) |

**Headers**
```http
Authorization: Bearer {token}
Accept: application/json
```

**Ejemplo de Request**

```bash
curl -X GET "http://api.example.com/api/v1/audits?user_id=3&event=updated&start_date=2025-01-01&end_date=2025-01-31&per_page=20" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

**Ejemplo de Respuesta**

```json
{
  "data": [
    {
      "id": 145,
      "event": "updated",
      "auditable_type": "User",
      "auditable_type_full": "App\\Models\\User",
      "auditable_id": 24,
      "old_values": {
        "name": "Jose Mendez",
        "email": "jose@example.com",
        "phone": "3001234567"
      },
      "new_values": {
        "name": "Jose Mendez Garcia",
        "email": "jose.mendez@example.com",
        "phone": "3009876543"
      },
      "changes": {
        "name": {
          "old": "Jose Mendez",
          "new": "Jose Mendez Garcia",
          "label": "Name"
        },
        "email": {
          "old": "jose@example.com",
          "new": "jose.mendez@example.com",
          "label": "Email"
        },
        "phone": {
          "old": "3001234567",
          "new": "3009876543",
          "label": "Phone"
        }
      },
      "url": "http://api.example.com/api/v1/users/24",
      "ip_address": "192.168.1.100",
      "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
      "tags": null,
      "user": {
        "id": 3,
        "name": "María García",
        "email": "maria@example.com"
      },
      "user_id": 3,
      "created_at": "2025-01-15T14:30:22.000000Z",
      "created_at_human": "2 hours ago",
      "updated_at": "2025-01-15T14:30:22.000000Z"
    }
  ],
  "links": {
    "first": "http://api.example.com/api/v1/audits?page=1",
    "last": "http://api.example.com/api/v1/audits?page=10",
    "prev": null,
    "next": "http://api.example.com/api/v1/audits?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 10,
    "per_page": 15,
    "to": 15,
    "total": 145
  }
}
```

---

### 2. Ver Auditoría Específica

```http
GET /api/v1/audits/{id}
```

**Parámetros de URL**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `id` | integer | ID de la auditoría |

**Ejemplo de Request**

```bash
curl -X GET "http://api.example.com/api/v1/audits/145" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

**Respuesta**: Igual estructura que un item individual del listado.

---

### 3. Auditorías por Usuario

```http
GET /api/v1/audits/user/{userId}
```

**Parámetros de URL**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `userId` | integer | ID del usuario |

**Parámetros de Query (Opcionales)**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `start_date` | date | Fecha inicial |
| `end_date` | date | Fecha final |
| `event` | string | Tipo de evento |
| `per_page` | integer | Resultados por página |

**Ejemplo de Request**

```bash
curl -X GET "http://api.example.com/api/v1/audits/user/3?start_date=2025-01-01" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

**Uso**: Ideal para ver todas las acciones realizadas por un usuario específico.

---

### 4. Auditorías por Modelo/Registro

```http
GET /api/v1/audits/model/{model}/{id}
```

**Parámetros de URL**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `model` | string | Nombre del modelo (User, Meeting, Campaign, etc.) |
| `id` | integer | ID del registro |

**Parámetros de Query (Opcionales)**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `start_date` | date | Fecha inicial |
| `end_date` | date | Fecha final |
| `per_page` | integer | Resultados por página |

**Ejemplo de Request**

```bash
# Ver historial completo de un usuario específico (User ID 24)
curl -X GET "http://api.example.com/api/v1/audits/model/User/24" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"

# Ver historial de una reunión específica (Meeting ID 10)
curl -X GET "http://api.example.com/api/v1/audits/model/Meeting/10" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

**Uso**: Ideal para ver el historial completo de cambios de un registro específico (timeline de un usuario, reunión, campaña, etc.).

---

### 5. Estadísticas de Auditoría

```http
GET /api/v1/audits/statistics
```

**Parámetros de Query (Opcionales)**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `start_date` | date | Fecha inicial |
| `end_date` | date | Fecha final |

**Ejemplo de Request**

```bash
curl -X GET "http://api.example.com/api/v1/audits/statistics?start_date=2025-01-01&end_date=2025-01-31" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

**Ejemplo de Respuesta**

```json
{
  "success": true,
  "data": {
    "total_audits": 1456,
    "by_event": {
      "created": 523,
      "updated": 789,
      "deleted": 134,
      "restored": 10
    },
    "by_model": {
      "App\\Models\\User": 345,
      "App\\Models\\Meeting": 567,
      "App\\Models\\Campaign": 234,
      "App\\Models\\Commitment": 310
    },
    "top_users": [
      {
        "user_id": 3,
        "count": 234
      },
      {
        "user_id": 5,
        "count": 189
      },
      {
        "user_id": 12,
        "count": 156
      }
    ],
    "by_date": {
      "2025-01-31": 56,
      "2025-01-30": 78,
      "2025-01-29": 45,
      "2025-01-28": 89
    }
  }
}
```

**Uso**: Para dashboard de actividad del sistema, gráficas y métricas.

---

## Estructura de Datos

### Objeto Audit

```typescript
interface Audit {
  id: number;
  event: 'created' | 'updated' | 'deleted' | 'restored';
  auditable_type: string;           // Nombre corto del modelo
  auditable_type_full: string;      // Nombre completo con namespace
  auditable_id: number;              // ID del registro auditado
  old_values: Record<string, any>;   // Valores anteriores
  new_values: Record<string, any>;   // Valores nuevos
  changes: Record<string, Change>;   // Cambios formateados
  url: string | null;                // URL del request
  ip_address: string;                // IP del usuario
  user_agent: string;                // User agent del navegador
  tags: string | null;
  user: {                            // Usuario que realizó la acción
    id: number;
    name: string;
    email: string;
  } | null;
  user_id: number | null;
  created_at: string;                // ISO 8601 format
  created_at_human: string;          // "2 hours ago"
  updated_at: string;
}

interface Change {
  old: any;
  new: any;
  label: string;  // Campo en formato legible
}
```

---

## Ejemplos de Uso Frontend

### React - Componente de Historial

```jsx
import React, { useState, useEffect } from 'react';
import axios from 'axios';

const AuditHistory = ({ userId }) => {
  const [audits, setAudits] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filters, setFilters] = useState({
    start_date: '',
    end_date: '',
    event: '',
  });

  useEffect(() => {
    fetchAudits();
  }, [userId, filters]);

  const fetchAudits = async () => {
    try {
      const params = new URLSearchParams({
        ...(filters.start_date && { start_date: filters.start_date }),
        ...(filters.end_date && { end_date: filters.end_date }),
        ...(filters.event && { event: filters.event }),
      });

      const response = await axios.get(
        `/api/v1/audits/user/${userId}?${params}`,
        {
          headers: {
            Authorization: `Bearer ${localStorage.getItem('token')}`,
          },
        }
      );

      setAudits(response.data.data);
      setLoading(false);
    } catch (error) {
      console.error('Error fetching audits:', error);
      setLoading(false);
    }
  };

  const renderChange = (change) => (
    <div className="change-item">
      <span className="field-label">{change.label}:</span>
      <span className="old-value">{change.old || '(vacío)'}</span>
      <span className="arrow">→</span>
      <span className="new-value">{change.new}</span>
    </div>
  );

  if (loading) return <div>Cargando historial...</div>;

  return (
    <div className="audit-history">
      <h3>Historial de Actividades</h3>
      
      {/* Filtros */}
      <div className="filters">
        <input
          type="date"
          value={filters.start_date}
          onChange={(e) => setFilters({ ...filters, start_date: e.target.value })}
          placeholder="Fecha inicial"
        />
        <input
          type="date"
          value={filters.end_date}
          onChange={(e) => setFilters({ ...filters, end_date: e.target.value })}
          placeholder="Fecha final"
        />
        <select
          value={filters.event}
          onChange={(e) => setFilters({ ...filters, event: e.target.value })}
        >
          <option value="">Todas las acciones</option>
          <option value="created">Creación</option>
          <option value="updated">Actualización</option>
          <option value="deleted">Eliminación</option>
          <option value="restored">Restauración</option>
        </select>
      </div>

      {/* Timeline de auditorías */}
      <div className="audit-timeline">
        {audits.map((audit) => (
          <div key={audit.id} className="audit-item">
            <div className="audit-header">
              <span className={`event-badge ${audit.event}`}>
                {audit.event}
              </span>
              <span className="model-name">{audit.auditable_type}</span>
              <span className="timestamp">{audit.created_at_human}</span>
            </div>
            
            <div className="audit-body">
              <p>
                <strong>{audit.user?.name}</strong> {getActionText(audit.event)} {' '}
                {audit.auditable_type} #{audit.auditable_id}
              </p>
              
              {audit.event === 'updated' && (
                <div className="changes">
                  <h4>Cambios realizados:</h4>
                  {Object.values(audit.changes).map((change, idx) => (
                    <div key={idx}>{renderChange(change)}</div>
                  ))}
                </div>
              )}
              
              <div className="audit-meta">
                <span>IP: {audit.ip_address}</span>
                <span>Fecha: {new Date(audit.created_at).toLocaleString()}</span>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

const getActionText = (event) => {
  const actions = {
    created: 'creó',
    updated: 'actualizó',
    deleted: 'eliminó',
    restored: 'restauró',
  };
  return actions[event] || event;
};

export default AuditHistory;
```

### Vue - Componente de Estadísticas

```vue
<template>
  <div class="audit-statistics">
    <h3>Estadísticas de Actividad</h3>
    
    <div class="date-range-picker">
      <input v-model="dateRange.start" type="date" placeholder="Fecha inicial" />
      <input v-model="dateRange.end" type="date" placeholder="Fecha final" />
      <button @click="fetchStatistics">Actualizar</button>
    </div>

    <div v-if="loading">Cargando estadísticas...</div>
    
    <div v-else class="stats-grid">
      <div class="stat-card">
        <h4>Total de Actividades</h4>
        <p class="stat-number">{{ statistics.total_audits }}</p>
      </div>

      <div class="stat-card">
        <h4>Por Tipo de Evento</h4>
        <ul>
          <li v-for="(count, event) in statistics.by_event" :key="event">
            {{ event }}: <strong>{{ count }}</strong>
          </li>
        </ul>
      </div>

      <div class="stat-card">
        <h4>Usuarios Más Activos</h4>
        <ul>
          <li v-for="user in statistics.top_users" :key="user.user_id">
            Usuario #{{ user.user_id }}: <strong>{{ user.count }}</strong> acciones
          </li>
        </ul>
      </div>

      <div class="stat-card">
        <h4>Por Modelo</h4>
        <ul>
          <li v-for="(count, model) in statistics.by_model" :key="model">
            {{ getModelName(model) }}: <strong>{{ count }}</strong>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

export default {
  name: 'AuditStatistics',
  data() {
    return {
      statistics: {
        total_audits: 0,
        by_event: {},
        by_model: {},
        top_users: [],
        by_date: {},
      },
      dateRange: {
        start: '',
        end: '',
      },
      loading: false,
    };
  },
  mounted() {
    this.fetchStatistics();
  },
  methods: {
    async fetchStatistics() {
      this.loading = true;
      try {
        const params = new URLSearchParams();
        if (this.dateRange.start) params.append('start_date', this.dateRange.start);
        if (this.dateRange.end) params.append('end_date', this.dateRange.end);

        const response = await axios.get(`/api/v1/audits/statistics?${params}`, {
          headers: {
            Authorization: `Bearer ${localStorage.getItem('token')}`,
          },
        });

        this.statistics = response.data.data;
      } catch (error) {
        console.error('Error fetching statistics:', error);
      } finally {
        this.loading = false;
      }
    },
    getModelName(fullPath) {
      return fullPath.split('\\').pop();
    },
  },
};
</script>
```

---

## Casos de Uso Comunes

### 1. Ver todas las acciones de un usuario

```bash
GET /api/v1/audits/user/3
```

### 2. Ver historial de cambios de un registro específico

```bash
GET /api/v1/audits/model/User/24
```

### 3. Buscar acciones realizadas en un rango de fechas

```bash
GET /api/v1/audits?start_date=2025-01-01&end_date=2025-01-31
```

### 4. Buscar solo eliminaciones

```bash
GET /api/v1/audits?event=deleted
```

### 5. Buscar acciones desde una IP específica

```bash
GET /api/v1/audits?ip_address=192.168.1.100
```

### 6. Dashboard de actividad del último mes

```bash
GET /api/v1/audits/statistics?start_date=2025-01-01&end_date=2025-01-31
```

---

## Notas Importantes

1. **Permisos**: Se requiere el permiso `view_audits` para acceder a todos los endpoints de auditoría. Este permiso está asignado al rol `admin` por defecto.

2. **Multi-tenancy**: Todas las auditorías están filtradas automáticamente por tenant. Los usuarios solo ven auditorías de su propio tenant.

3. **Paginación**: Por defecto, se devuelven 15 registros por página. Use el parámetro `per_page` para cambiar esto.

4. **Campos sensibles**: Las contraseñas y otros campos sensibles no se registran en las auditorías.

5. **Zona horaria**: Todas las fechas están en formato UTC (ISO 8601). El frontend debe convertirlas a la zona horaria local.

6. **Performance**: Para consultas grandes, use filtros adecuados para mejorar el rendimiento.

7. **old_values y new_values**: Solo contienen los campos que cambiaron (en updates) o que se establecieron (en creates).

---

## Códigos de Estado HTTP

| Código | Descripción |
|--------|-------------|
| 200 | Éxito |
| 401 | No autenticado |
| 403 | Sin permisos (no tiene permiso `view_audits`) |
| 404 | Auditoría no encontrada |
| 422 | Parámetros inválidos |
| 500 | Error del servidor |

---

## Preguntas Frecuentes

**Q: ¿Quién puede ver las auditorías?**  
A: Solo usuarios con el permiso `view_audits`. Por defecto, este permiso está asignado al rol `admin`. Los super admins necesitan tener este permiso asignado explícitamente también.

**Q: ¿Se pueden editar o eliminar auditorías?**  
A: No. Las auditorías son inmutables para mantener la integridad del historial.

**Q: ¿Cuánto tiempo se conservan las auditorías?**  
A: Las auditorías se conservan indefinidamente. El administrador puede implementar políticas de retención según necesidad.

**Q: ¿Se pueden desactivar las auditorías para ciertos modelos?**  
A: Sí, removiendo el trait `Auditable` del modelo específico.

**Q: ¿Las auditorías afectan el rendimiento?**  
A: El impacto es mínimo. Las auditorías se pueden mover a una cola si hay problemas de rendimiento.

**Q: ¿Cómo asigno el permiso a otros roles?**  
A: Use el método `givePermissionTo` de Spatie:
```php
$role = Role::findByName('coordinador');
$role->givePermissionTo('view_audits');
```

---

## Soporte

Para preguntas o problemas con la API de auditoría, contacte al equipo de desarrollo.
