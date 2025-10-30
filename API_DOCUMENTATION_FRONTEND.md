# API Documentation for Frontend (React)

**Base URL:** `https://92fd3b275ee5.ngrok-free.app/api/v1`

**Authentication:** JWT Token (Header: `Authorization: Bearer {token}`)

---

## 🔐 Authentication Endpoints

### Public Routes

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| POST | `/login` | Autentica usuario y devuelve JWT token | `{ email: string, password: string }` | `{ access_token: string, token_type: "bearer", expires_in: number, user: UserResource }` |
| GET | `/meetings/check-in/{qr_code}` | Obtiene información de reunión mediante QR (público) | - | `{ data: MeetingResource }` |
| POST | `/meetings/check-in/{qr_code}` | Registra asistencia mediante QR (público) | `{ cedula: string, nombres: string, apellidos: string, telefono: string, email: string, extra_fields?: object }` | `{ data: MeetingAttendeeResource, message: string }` |

### Protected Routes (Require JWT)

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| POST | `/logout` | Cierra sesión e invalida JWT token | - | `{ message: "Successfully logged out" }` |
| POST | `/refresh` | Refresca el JWT token | - | `{ access_token: string, token_type: "bearer", expires_in: number }` |
| GET | `/me` | Obtiene información del usuario autenticado | - | `{ data: UserResource }` |

---

## 👤 User Management

### Super Admin Only

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| POST | `/register` | Registra nuevo usuario (solo SuperAdmin) | `{ tenant_id: number, name: string, email: string, password: string, password_confirmation: string, telefono?: string, is_team_leader?: boolean, reports_to?: number, roles?: string[] }` | `{ user: UserResource, message: string }` |

---

## 🏢 Tenant Management (Super Admin Only)

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/tenants` | Lista todos los tenants con paginación y filtros | Query: `filter[nombre], filter[tipo_cargo], filter[identificacion], sort, page` | `{ data: TenantResource[], links: {}, meta: {} }` |
| POST | `/tenants` | Crea nuevo tenant | `{ slug: string, nombre: string, tipo_cargo: enum, identificacion: string, metadata?: object }` | `{ data: TenantResource, message: string }` |
| GET | `/tenants/{id}` | Obtiene detalle de tenant con relaciones | - | `{ data: TenantResource }` |
| PUT | `/tenants/{id}` | Actualiza tenant | `{ slug?: string, nombre?: string, tipo_cargo?: enum, identificacion?: string, metadata?: object }` | `{ data: TenantResource, message: string }` |
| DELETE | `/tenants/{id}` | Elimina tenant | - | `{ message: string }` |

---

## 👥 Users (Tenant-scoped)

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/users` | Lista usuarios del tenant con filtros | Query: `filter[name], filter[email], filter[is_team_leader], sort, page` | `{ data: UserResource[], links: {}, meta: {} }` |
| POST | `/users` | Crea nuevo usuario en tenant | `{ name: string, email: string, password: string, password_confirmation: string, telefono?: string, is_team_leader?: boolean, reports_to?: number, roles?: string[] }` | `{ data: UserResource, message: string }` |
| GET | `/users/{id}` | Obtiene detalle de usuario con relaciones | - | `{ data: UserResource }` |
| PUT | `/users/{id}` | Actualiza usuario | `{ name?: string, email?: string, password?: string, password_confirmation?: string, telefono?: string, is_team_leader?: boolean, reports_to?: number, roles?: string[] }` | `{ data: UserResource, message: string }` |
| DELETE | `/users/{id}` | Elimina usuario | - | `{ message: string }` |
| GET | `/users/{id}/team` | Obtiene equipo jerárquico del usuario (subordinados) | - | `{ data: UserResource[] }` |

---

## 📋 Meeting Templates

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/meeting-templates` | Lista plantillas de reuniones con contador | - | `{ data: MeetingTemplateResource[] }` |
| POST | `/meeting-templates` | Crea plantilla de reunión | `{ name: string, description?: string, default_fields?: object }` | `{ data: MeetingTemplateResource, message: string }` |
| GET | `/meeting-templates/{id}` | Obtiene detalle de plantilla | - | `{ data: MeetingTemplateResource }` |
| PUT | `/meeting-templates/{id}` | Actualiza plantilla | `{ name?: string, description?: string, default_fields?: object }` | `{ data: MeetingTemplateResource, message: string }` |
| DELETE | `/meeting-templates/{id}` | Elimina plantilla | - | `{ message: string }` |

---

## 📅 Meetings

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/meetings` | Lista reuniones con filtros | Query: `filter[titulo], filter[status], filter[department_id], filter[city_id], sort, page` | `{ data: MeetingResource[], links: {}, meta: {} }` |
| POST | `/meetings` | Crea reunión y genera QR automáticamente | `{ meeting_template_id?: number, titulo: string, descripcion?: string, fecha_programada: date, direccion?: string, department_id?: number, city_id?: number, commune_id?: number, barrio_id?: number, corregimiento_id?: number, vereda_id?: number, latitud?: number, longitud?: number, metadata?: object }` | `{ data: MeetingResource, message: string }` |
| GET | `/meetings/{id}` | Obtiene detalle de reunión | - | `{ data: MeetingResource }` |
| PUT | `/meetings/{id}` | Actualiza reunión | `{ meeting_template_id?: number, titulo?: string, descripcion?: string, fecha_programada?: date, direccion?: string, status?: enum, department_id?: number, city_id?: number, commune_id?: number, barrio_id?: number, metadata?: object }` | `{ data: MeetingResource, message: string }` |
| DELETE | `/meetings/{id}` | Elimina reunión | - | `{ message: string }` |
| POST | `/meetings/{id}/complete` | Marca reunión como completada | - | `{ data: MeetingResource, message: string }` |
| POST | `/meetings/{id}/cancel` | Cancela reunión | - | `{ data: MeetingResource, message: string }` |
| GET | `/meetings/{id}/qr-code` | Obtiene URL del código QR | - | `{ qr_code_url: string }` |

---

## 👨‍👩‍👧‍👦 Meeting Attendees

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/meetings/{meeting_id}/attendees` | Lista asistentes de reunión con filtros | Query: `filter[checked_in], page` | `{ data: MeetingAttendeeResource[], checked_in_count: number, links: {}, meta: {} }` |
| POST | `/meetings/{meeting_id}/attendees` | Registra nuevo asistente | `{ cedula: string, nombres: string, apellidos: string, telefono?: string, email?: string, extra_fields?: object }` | `{ data: MeetingAttendeeResource, message: string }` |
| GET | `/attendees/{id}` | Obtiene detalle de asistente | - | `{ data: MeetingAttendeeResource }` |
| PUT | `/attendees/{id}` | Actualiza asistente (marca check-in si checked_in=true) | `{ cedula?: string, nombres?: string, apellidos?: string, telefono?: string, email?: string, checked_in?: boolean, extra_fields?: object }` | `{ data: MeetingAttendeeResource, message: string }` |
| DELETE | `/attendees/{id}` | Elimina asistente | - | `{ message: string }` |

---

## 📢 Campaigns

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/campaigns` | Lista campañas con filtros | Query: `filter[titulo], filter[status], filter[channel], sort, page` | `{ data: CampaignResource[], links: {}, meta: {} }` |
| POST | `/campaigns` | Crea campaña y programa envío | `{ titulo: string, mensaje: string, channel: enum(sms\|email\|both), filter_json?: object, scheduled_at?: datetime }` | `{ data: CampaignResource, message: string }` |
| GET | `/campaigns/{id}` | Obtiene detalle de campaña | - | `{ data: CampaignResource }` |
| PUT | `/campaigns/{id}` | Actualiza campaña (solo si status=pending) | `{ titulo?: string, mensaje?: string, channel?: enum, filter_json?: object, scheduled_at?: datetime }` | `{ data: CampaignResource, message: string }` |
| DELETE | `/campaigns/{id}` | Elimina campaña (no permite si in_progress) | - | `{ message: string }` |
| POST | `/campaigns/{id}/send` | Envía campaña manualmente | - | `{ message: string }` |
| POST | `/campaigns/{id}/cancel` | Cancela campaña (no permite si completed) | - | `{ data: CampaignResource, message: string }` |
| GET | `/campaigns/{id}/recipients` | Lista destinatarios con estado de envío | Query: `page` | `{ data: CampaignRecipientResource[], links: {}, meta: {} }` |

---

## ✅ Commitments

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/commitments` | Lista compromisos con filtros | Query: `filter[status], filter[meeting_id], filter[assigned_user_id], filter[priority_id], sort, page` | `{ data: CommitmentResource[], links: {}, meta: {} }` |
| POST | `/commitments` | Crea compromiso | `{ meeting_id: number, assigned_user_id: number, priority_id: number, descripcion: string, fecha_compromiso: date, notas?: string }` | `{ data: CommitmentResource, message: string }` |
| GET | `/commitments/{id}` | Obtiene detalle de compromiso | - | `{ data: CommitmentResource }` |
| PUT | `/commitments/{id}` | Actualiza compromiso | `{ meeting_id?: number, assigned_user_id?: number, priority_id?: number, descripcion?: string, fecha_compromiso?: date, fecha_cumplimiento?: date, status?: enum, notas?: string }` | `{ data: CommitmentResource, message: string }` |
| DELETE | `/commitments/{id}` | Elimina compromiso | - | `{ message: string }` |
| POST | `/commitments/{id}/complete` | Marca compromiso como cumplido | - | `{ data: CommitmentResource, message: string }` |
| GET | `/commitments/overdue` | Lista compromisos vencidos | Query: `page` | `{ data: CommitmentResource[], links: {}, meta: {} }` |

---

## 💰 Resource Allocations

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/resource-allocations` | Lista asignaciones con filtros | Query: `filter[type], filter[meeting_id], filter[leader_user_id], sort, page` | `{ data: ResourceAllocationResource[], links: {}, meta: {} }` |
| POST | `/resource-allocations` | Crea asignación de recurso | `{ meeting_id: number, leader_user_id: number, type: enum(cash\|material\|service), descripcion?: string, amount: number, fecha_asignacion: date }` | `{ data: ResourceAllocationResource, message: string }` |
| GET | `/resource-allocations/{id}` | Obtiene detalle de asignación | - | `{ data: ResourceAllocationResource }` |
| PUT | `/resource-allocations/{id}` | Actualiza asignación | `{ meeting_id?: number, leader_user_id?: number, type?: enum, descripcion?: string, amount?: number, fecha_asignacion?: date }` | `{ data: ResourceAllocationResource, message: string }` |
| DELETE | `/resource-allocations/{id}` | Elimina asignación | - | `{ message: string }` |
| GET | `/resource-allocations/by-meeting/{meeting_id}` | Lista recursos por reunión con totales | - | `{ data: ResourceAllocationResource[], totals: { cash: number, material: number, service: number } }` |
| GET | `/resource-allocations/by-leader/{user_id}` | Lista recursos por líder con resumen | - | `{ data: ResourceAllocationResource[], summary: { total_amount: number, count: number } }` |

---

## 🌍 Geography (Hierarchical)

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/departments` | Lista todos los departamentos | - | `{ data: GeographyResource[] }` |
| GET | `/departments/{id}/cities` | Lista ciudades de un departamento | - | `{ data: GeographyResource[] }` |
| GET | `/cities/{id}/communes` | Lista comunas de una ciudad | - | `{ data: GeographyResource[] }` |
| GET | `/communes/{id}/barrios` | Lista barrios de una comuna | - | `{ data: GeographyResource[] }` |

---

## 📊 Reports & Statistics

| Method | Endpoint | Description | Request Body | Response |
|--------|----------|-------------|--------------|----------|
| GET | `/reports/meetings` | Estadísticas de reuniones | - | `{ total: number, by_status: object, upcoming: number, completed: number, avg_attendees: number, total_attendees: number }` |
| GET | `/reports/campaigns` | Estadísticas de campañas | - | `{ total: number, by_status: object, by_channel: object, total_sent: number, total_failed: number, total_recipients: number }` |
| GET | `/reports/commitments` | Estadísticas de compromisos | - | `{ total: number, by_status: object, overdue: number, completed: number, by_priority: object }` |
| GET | `/reports/resources` | Estadísticas de recursos | - | `{ total: number, by_type: object, totals: { cash: number, material: number, service: number } }` |
| GET | `/reports/team-performance` | Desempeño de líderes de equipo | - | `{ data: [{ user: UserResource, meetings_planned: number, commitments_assigned: number, resources_allocated: number }] }` |

---

## 📦 Data Structures (TypeScript Interfaces)

### UserResource
```typescript
interface UserResource {
  id: number;
  tenant_id: number;
  name: string;
  email: string;
  telefono?: string;
  is_super_admin: boolean;
  is_team_leader: boolean;
  reports_to?: number;
  supervisor?: UserResource;
  tenant?: TenantResource;
  roles?: string[];
  permissions?: string[];
  created_at: string;
  updated_at: string;
}
```

### TenantResource
```typescript
interface TenantResource {
  id: number;
  slug: string;
  nombre: string;
  tipo_cargo: 'alcalde' | 'gobernador' | 'concejal' | 'diputado' | 'senador' | 'representante' | 'otro';
  identificacion: string;
  metadata?: object;
  users_count?: number;
  meetings_count?: number;
  campaigns_count?: number;
  created_at: string;
  updated_at: string;
}
```

### MeetingResource
```typescript
interface MeetingResource {
  id: number;
  titulo: string;
  descripcion?: string;
  fecha_programada: string;
  fecha_realizacion?: string;
  direccion?: string;
  status: 'scheduled' | 'completed' | 'cancelled';
  qr_code?: string;
  department?: GeographyResource;
  city?: GeographyResource;
  commune?: GeographyResource;
  barrio?: GeographyResource;
  latitud?: number;
  longitud?: number;
  planned_by?: UserResource;
  template?: MeetingTemplateResource;
  attendees_count?: number;
  commitments_count?: number;
  metadata?: object;
  created_at: string;
  updated_at: string;
}
```

### MeetingTemplateResource
```typescript
interface MeetingTemplateResource {
  id: number;
  name: string;
  description?: string;
  default_fields?: object;
  meetings_count?: number;
  created_at: string;
  updated_at: string;
}
```

### MeetingAttendeeResource
```typescript
interface MeetingAttendeeResource {
  id: number;
  meeting_id: number;
  cedula: string;
  nombres: string;
  apellidos: string;
  full_name: string;
  telefono?: string;
  email?: string;
  extra_fields?: object;
  checked_in: boolean;
  checked_in_at?: string;
  meeting?: MeetingResource;
  created_at: string;
  updated_at: string;
}
```

### CampaignResource
```typescript
interface CampaignResource {
  id: number;
  titulo: string;
  mensaje: string;
  channel: 'sms' | 'email' | 'both';
  filter_json?: object;
  scheduled_at?: string;
  started_at?: string;
  completed_at?: string;
  status: 'pending' | 'in_progress' | 'completed' | 'cancelled';
  total_recipients: number;
  sent_count: number;
  failed_count: number;
  progress_percentage: number;
  created_by?: UserResource;
  created_at: string;
  updated_at: string;
}
```

### CampaignRecipientResource
```typescript
interface CampaignRecipientResource {
  id: number;
  campaign_id: number;
  recipient_type: 'email' | 'phone';
  recipient_value: string;
  status: 'pending' | 'sent' | 'failed';
  sent_at?: string;
  error_message?: string;
  created_at: string;
  updated_at: string;
}
```

### CommitmentResource
```typescript
interface CommitmentResource {
  id: number;
  descripcion: string;
  fecha_compromiso: string;
  fecha_cumplimiento?: string;
  status: 'pending' | 'in_progress' | 'completed' | 'cancelled';
  notas?: string;
  meeting?: MeetingResource;
  assigned_user?: UserResource;
  priority?: {
    id: number;
    name: string;
    color: string;
  };
  created_at: string;
  updated_at: string;
}
```

### ResourceAllocationResource
```typescript
interface ResourceAllocationResource {
  id: number;
  type: 'cash' | 'material' | 'service';
  descripcion?: string;
  amount: number;
  fecha_asignacion: string;
  meeting?: MeetingResource;
  allocated_by?: UserResource;
  leader?: UserResource;
  created_at: string;
  updated_at: string;
}
```

### GeographyResource
```typescript
interface GeographyResource {
  id: number;
  codigo?: string;
  nombre: string;
  latitud?: number;
  longitud?: number;
  department_id?: number;
  city_id?: number;
  commune_id?: number;
  created_at: string;
  updated_at: string;
}
```

---

## 🔑 Enumerations

```typescript
enum TipoCargo {
  ALCALDE = 'alcalde',
  GOBERNADOR = 'gobernador',
  CONCEJAL = 'concejal',
  DIPUTADO = 'diputado',
  SENADOR = 'senador',
  REPRESENTANTE = 'representante',
  OTRO = 'otro'
}

enum MeetingStatus {
  SCHEDULED = 'scheduled',
  COMPLETED = 'completed',
  CANCELLED = 'cancelled'
}

enum CampaignChannel {
  SMS = 'sms',
  EMAIL = 'email',
  BOTH = 'both'
}

enum CampaignStatus {
  PENDING = 'pending',
  IN_PROGRESS = 'in_progress',
  COMPLETED = 'completed',
  CANCELLED = 'cancelled'
}

enum CommitmentStatus {
  PENDING = 'pending',
  IN_PROGRESS = 'in_progress',
  COMPLETED = 'completed',
  CANCELLED = 'cancelled'
}

enum ResourceType {
  CASH = 'cash',
  MATERIAL = 'material',
  SERVICE = 'service'
}

enum RecipientStatus {
  PENDING = 'pending',
  SENT = 'sent',
  FAILED = 'failed'
}
```

---

## 🛡️ Roles & Permissions

### Roles
- **SuperAdmin**: Acceso total al sistema
- **Admin**: Administrador de tenant
- **Coordinator**: Coordinador de campaña
- **User**: Usuario básico

### Permissions
- `view_meetings`, `create_meetings`, `edit_meetings`, `delete_meetings`
- `view_campaigns`, `create_campaigns`, `edit_campaigns`, `delete_campaigns`
- `view_commitments`, `create_commitments`, `edit_commitments`, `delete_commitments`
- `view_resources`, `create_resources`, `edit_resources`, `delete_resources`
- `view_reports`
- `manage_users`, `manage_roles`

---

## 🚨 Error Responses

```typescript
interface ErrorResponse {
  message: string;
  errors?: {
    [field: string]: string[];
  };
}
```

**HTTP Status Codes:**
- `200` - Success
- `201` - Created
- `204` - No Content (Delete)
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `500` - Server Error

---

## 📝 Notes for Frontend Development

1. **JWT Token Management**: Store token in localStorage/sessionStorage and include in all requests via `Authorization: Bearer {token}`
2. **Pagination**: All list endpoints return `{ data, links, meta }` structure compatible with Laravel pagination
3. **Filtering**: Use query parameters like `filter[field]=value` for filtering
4. **Sorting**: Use `sort=field` or `sort=-field` (descending)
5. **Relationships**: Use `include` parameter when available (e.g., `include=tenant,roles`)
6. **Tenant Isolation**: All tenant-scoped endpoints automatically filter by authenticated user's tenant
7. **QR Codes**: Store QR code URLs and display as images in frontend
8. **Real-time Updates**: Consider implementing WebSockets for campaign status and meeting check-ins
9. **Date Formats**: All dates are in ISO 8601 format (e.g., `2025-10-29T17:30:00Z`)
10. **File Uploads**: Currently not implemented - can be added for meeting attachments

---

**Generated:** October 29, 2025  
**API Version:** v1  
**Laravel Version:** 12.36.1
