# Dashboard & Calendar API Documentation

## Overview
API endpoints for dashboard statistics and calendar visualization for political campaign management system.

**Base URL**: `/api/v1`

**Authentication**: Required - JWT Bearer Token

**Middleware**: `jwt.auth`, `tenant` (tenant-scoped data)

---

## Dashboard Statistics

### GET /dashboard

Retrieves comprehensive statistics for the platform including meetings, commitments, campaigns, and performance metrics.

#### Headers
```
Authorization: Bearer {jwt_token}
Accept: application/json
```

#### Query Parameters
None

#### Response 200 OK

```json
{
  "data": {
    "totals": {
      "meetings": 25,
      "meetings_scheduled": 10,
      "meetings_completed": 12,
      "meetings_cancelled": 3,
      "attendees": 350,
      "attendees_checked_in": 280,
      "commitments": 78,
      "commitments_pending": 20,
      "commitments_in_progress": 35,
      "commitments_completed": 20,
      "commitments_overdue": 8,
      "campaigns": 15,
      "campaigns_sent": 12,
      "users": 45,
      "team_leaders": 8
    },
    "commitments_by_priority": [
      {
        "priority_id": 1,
        "priority_name": "Alta",
        "priority_color": "#EF4444",
        "total": 35
      },
      {
        "priority_id": 2,
        "priority_name": "Media",
        "priority_color": "#F59E0B",
        "total": 28
      },
      {
        "priority_id": 3,
        "priority_name": "Baja",
        "priority_color": "#10B981",
        "total": 15
      }
    ],
    "meetings_by_month": [
      {
        "year": 2025,
        "month": 1,
        "month_name": "Enero",
        "total": 5
      },
      {
        "year": 2025,
        "month": 2,
        "month_name": "Febrero",
        "total": 8
      }
      // ... últimos 12 meses
    ],
    "attendees_by_month": [
      {
        "year": 2025,
        "month": 1,
        "month_name": "Enero",
        "total": 45
      },
      {
        "year": 2025,
        "month": 2,
        "month_name": "Febrero",
        "total": 62
      }
      // ... últimos 12 meses
    ],
    "avg_attendees_per_meeting": 14.5,
    "avg_commitments_per_meeting": 3.12,
    "top_meetings_by_attendees": [
      {
        "id": 5,
        "title": "Reunión Sectorial Norte",
        "starts_at": "2025-02-15T14:00:00.000000Z",
        "attendees_count": 45
      }
      // ... top 5
    ],
    "top_users_by_commitments": [
      {
        "id": 12,
        "name": "Juan Pérez",
        "commitments_count": 15
      }
      // ... top 5
    ],
    "commitment_completion_rate": {
      "total": 78,
      "completed": 20,
      "rate": 25.64
    },
    "resources_by_type": [
      {
        "type": "cash",
        "total": 25,
        "total_amount": 5000000
      },
      {
        "type": "volunteers",
        "total": 18,
        "total_amount": 120
      },
      {
        "type": "materials",
        "total": 12,
        "total_amount": 0
      }
    ],
    "total_budget": 5000000,
    "upcoming_meetings": [
      {
        "id": 28,
        "title": "Reunión con Líderes Comunales",
        "starts_at": "2025-02-20T10:00:00.000000Z",
        "lugar_nombre": "Casa Comunal La Estrella"
      }
      // ... próximas 5 reuniones
    ],
    "recent_overdue_commitments": [
      {
        "id": 45,
        "description": "Gestionar ayudas con alcaldía",
        "due_date": "2025-02-10T00:00:00.000000Z",
        "days_overdue": -5,
        "meeting": {
          "id": 15,
          "title": "Reunión Sectorial Sur"
        },
        "assigned_user": {
          "id": 8,
          "name": "María González"
        },
        "priority": {
          "name": "Alta",
          "color": "#EF4444"
        }
      }
      // ... últimos 5 compromisos vencidos
    ]
  }
}
```

#### Statistics Breakdown

**totals**: Contadores principales de entidades
- `meetings`: Total de reuniones
- `meetings_scheduled`: Reuniones programadas
- `meetings_completed`: Reuniones completadas
- `meetings_cancelled`: Reuniones canceladas
- `attendees`: Total de asistentes registrados
- `attendees_checked_in`: Asistentes con check-in confirmado
- `commitments`: Total de compromisos
- `commitments_pending`: Compromisos pendientes
- `commitments_in_progress`: Compromisos en progreso
- `commitments_completed`: Compromisos completados
- `commitments_overdue`: Compromisos vencidos
- `campaigns`: Total de campañas
- `campaigns_sent`: Campañas enviadas
- `users`: Total de usuarios
- `team_leaders`: Líderes de equipo

**commitments_by_priority**: Distribución de compromisos por prioridad (para gráfico circular/barras)

**meetings_by_month**: Reuniones por mes (últimos 12 meses) - para gráfico de línea/barras temporal

**attendees_by_month**: Asistentes por mes (últimos 12 meses) - para análisis de crecimiento

**avg_attendees_per_meeting**: Promedio de asistentes por reunión

**avg_commitments_per_meeting**: Promedio de compromisos generados por reunión

**top_meetings_by_attendees**: Top 5 reuniones con más asistentes

**top_users_by_commitments**: Top 5 usuarios con más compromisos asignados

**commitment_completion_rate**: Tasa de cumplimiento de compromisos (porcentaje)

**resources_by_type**: Distribución de recursos asignados por tipo

**total_budget**: Presupuesto total asignado (cash)

**upcoming_meetings**: Próximas 5 reuniones programadas

**recent_overdue_commitments**: Últimos 5 compromisos vencidos con detalles

#### Use Cases for Frontend
- Dashboard widgets con KPIs principales
- Gráficos de torta: compromisos por prioridad, recursos por tipo
- Gráficos de línea/barras: reuniones por mes, asistentes por mes
- Tablas: top reuniones, top usuarios, compromisos vencidos
- Cards: próximas reuniones, tasa de cumplimiento

---

## Calendar Events

### GET /calendar

Retrieves events (meetings and commitments) for calendar visualization within a date range.

#### Headers
```
Authorization: Bearer {jwt_token}
Accept: application/json
```

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `start` | date | No | Current month start | Start date (YYYY-MM-DD) |
| `end` | date | No | Current month end | End date (YYYY-MM-DD) |

#### Example Request
```
GET /api/v1/calendar?start=2025-02-01&end=2025-02-28
```

#### Response 200 OK

```json
{
  "data": {
    "meetings": [
      {
        "type": "meeting",
        "id": 15,
        "title": "Reunión Sectorial Norte",
        "description": "Discusión de propuestas para el sector norte",
        "start": "2025-02-15T14:00:00.000000Z",
        "end": "2025-02-15T17:00:00.000000Z",
        "location": "Casa Comunal La Estrella",
        "municipality": "Medellín",
        "status": "scheduled",
        "planner": {
          "id": 5,
          "name": "Carlos Ramírez"
        },
        "color": "#3B82F6"
      }
      // ... más reuniones
    ],
    "commitments": [
      {
        "type": "commitment",
        "id": 28,
        "title": "Gestionar ayudas con alcaldía",
        "description": "Contactar secretaría de gobierno para solicitar apoyo",
        "start": "2025-02-20T00:00:00.000000Z",
        "end": "2025-02-20T00:00:00.000000Z",
        "status": "pending",
        "meeting": {
          "id": 15,
          "title": "Reunión Sectorial Norte"
        },
        "assigned_user": {
          "id": 8,
          "name": "María González"
        },
        "priority": {
          "id": 1,
          "name": "Alta",
          "color": "#EF4444"
        },
        "color": "#EF4444"
      }
      // ... más compromisos
    ],
    "all_events": [
      // Todos los eventos mezclados y ordenados por fecha (start)
    ]
  }
}
```

#### Response Fields

**Common Fields for All Events:**
- `type`: Tipo de evento (`"meeting"` o `"commitment"`)
- `id`: ID único del evento
- `title`: Título del evento
- `description`: Descripción detallada
- `start`: Fecha/hora de inicio (ISO 8601)
- `end`: Fecha/hora de fin (ISO 8601)
- `color`: Color hexadecimal para visualización

**Meeting-specific Fields:**
- `location`: Nombre del lugar de la reunión
- `municipality`: Municipio donde se realiza
- `status`: Estado (`scheduled`, `in_progress`, `completed`, `cancelled`)
- `planner`: Usuario que planificó la reunión

**Meeting Status Colors:**
- `scheduled`: `#3B82F6` (Azul)
- `in_progress`: `#F59E0B` (Naranja)
- `completed`: `#10B981` (Verde)
- `cancelled`: `#EF4444` (Rojo)

**Commitment-specific Fields:**
- `status`: Estado (`pending`, `in_progress`, `completed`)
- `meeting`: Reunión que generó el compromiso (ID + título)
- `assigned_user`: Usuario asignado al compromiso
- `priority`: Prioridad del compromiso (incluye color)
- `color`: Heredado de la prioridad

#### Response Collections

The endpoint returns three collections:

1. **`meetings`**: Solo reuniones en el rango de fechas
2. **`commitments`**: Solo compromisos en el rango de fechas
3. **`all_events`**: Todos los eventos mezclados y ordenados cronológicamente

Use `all_events` para renderizar un calendario unificado, o use `meetings`/`commitments` por separado para vistas filtradas.

#### Use Cases for Frontend

**Full Calendar Integration:**
```javascript
// FullCalendar, React Big Calendar, etc.
const events = response.data.all_events.map(event => ({
  id: event.id,
  title: event.title,
  start: event.start,
  end: event.end,
  backgroundColor: event.color,
  extendedProps: {
    type: event.type,
    description: event.description,
    meeting: event.meeting, // Para commitments
    status: event.status
  }
}));
```

**Separate Views:**
- Vista solo reuniones: use `response.data.meetings`
- Vista solo compromisos: use `response.data.commitments`
- Vista combinada: use `response.data.all_events`

**Event Click Handler:**
```javascript
const handleEventClick = (event) => {
  if (event.type === 'meeting') {
    // Navigate to /meetings/{id}
  } else if (event.type === 'commitment') {
    // Navigate to /commitments/{id}
    // Show related meeting: event.meeting
  }
};
```

#### Filtering Examples

**Current month:**
```
GET /api/v1/calendar
```

**Specific month:**
```
GET /api/v1/calendar?start=2025-03-01&end=2025-03-31
```

**Week view:**
```
GET /api/v1/calendar?start=2025-02-17&end=2025-02-23
```

**Year view:**
```
GET /api/v1/calendar?start=2025-01-01&end=2025-12-31
```

---

## Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
  "message": "User does not have the right permissions."
}
```

---

## Data Filtering (Tenant Scope)

Both endpoints automatically filter data by tenant:
- **Super Admin** (tenant_id = null): Sees all data across all tenants
- **Tenant Users** (tenant_id = specific tenant): Only see their tenant's data

No additional filtering parameters required.

---

## Notes for Frontend Integration

1. **Dashboard**: Ideal para landing page después del login
2. **Calendar**: Use bibliotecas como FullCalendar, React Big Calendar, vue-cal
3. **Date Format**: Todas las fechas en formato ISO 8601 UTC
4. **Colors**: Colores en formato hexadecimal (#RRGGBB) listos para usar
5. **Relationships**: Los compromisos incluyen el meeting que los generó
6. **Refresh**: Considerar auto-refresh cada 5-10 minutos para datos en tiempo real
7. **Charts**: Librerías recomendadas: Chart.js, Recharts, ApexCharts

---

## Example Frontend Components

### Dashboard KPI Cards
```javascript
const stats = response.data.totals;

<Card title="Reuniones">
  <h2>{stats.meetings}</h2>
  <div>
    <span>Programadas: {stats.meetings_scheduled}</span>
    <span>Completadas: {stats.meetings_completed}</span>
  </div>
</Card>

<Card title="Compromisos">
  <h2>{stats.commitments}</h2>
  <div>
    <span>Pendientes: {stats.commitments_pending}</span>
    <span>Vencidos: {stats.commitments_overdue}</span>
  </div>
</Card>
```

### Pie Chart - Commitments by Priority
```javascript
const chartData = response.data.commitments_by_priority.map(item => ({
  name: item.priority_name,
  value: item.total,
  color: item.priority_color
}));
```

### Line Chart - Meetings by Month
```javascript
const chartData = response.data.meetings_by_month.map(item => ({
  month: item.month_name,
  meetings: item.total
}));
```

### Calendar View
```javascript
<Calendar
  events={response.data.all_events}
  onEventClick={handleEventClick}
  onDateSelect={handleDateSelect}
/>
```

---

## Performance Considerations

- Dashboard endpoint ejecuta múltiples queries agregadas
- Tiempo de respuesta típico: 200-500ms
- Considerar caching en frontend (5-10 minutos)
- Calendar endpoint filtrado por fechas es más eficiente
- Use rangos de fechas razonables (max 1 año)

---

## Versioning

Current Version: **v1**

Base Path: `/api/v1`

---

## Support

For issues or questions about these endpoints, contact the development team.
