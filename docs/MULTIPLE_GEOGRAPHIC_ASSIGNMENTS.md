# Asignaciones Geográficas Múltiples - Guía de Implementación

## 📋 Resumen del Cambio

Se ha actualizado el sistema para permitir que los usuarios puedan ser asignados a **múltiples ubicaciones geográficas** en lugar de solo una por tipo.

### Antes (Relación Uno-a-Uno)
```json
{
  "department_id": 1,
  "municipality_id": 28,
  "barrio_id": 12
}
```

### Ahora (Relación Muchos-a-Muchos)
```json
{
  "department_ids": [1, 2, 3],
  "municipality_ids": [28, 29, 30],
  "barrio_ids": [12, 13, 14, 15]
}
```

### ✅ Flexibilidad Total

**El sistema permite asignaciones completamente flexibles:**

- ✅ Múltiples comunas del **mismo** municipio
- ✅ Múltiples comunas de **diferentes** municipios
- ✅ Múltiples barrios del **mismo** municipio  
- ✅ Múltiples barrios de **diferentes** municipios
- ✅ Combinaciones complejas sin restricciones jerárquicas

**Ejemplo:** Un usuario puede tener comunas de Ibagué Y Bogotá simultáneamente:
```json
{
  "commune_ids": [1, 2, 3, 50, 51],  // 3 de Ibagué + 2 de Bogotá
  "barrio_ids": [12, 13, 20, 21]      // Barrios de ambos municipios
}
```

**El backend NO valida jerarquías geográficas** - el frontend puede enviar cualquier combinación válida de IDs.

---

## 🔧 Cambios en la API

### 1. **Crear Usuario** - `POST /api/v1/users`

#### Nuevo Formato (Recomendado)
```json
{
  "name": "Juan Pérez",
  "email": "juan@example.com",
  "phone": "3001234567",
  "cedula": "123456789",
  "is_team_leader": true,
  "reports_to": 3,
  "roles": ["operator"],
  "password": "123456",
  
  "department_ids": [1],
  "municipality_ids": [28, 29],
  "commune_ids": [5, 6],
  "barrio_ids": [12, 13, 14],
  "corregimiento_ids": [],
  "vereda_ids": []
}
```

#### Formato Antiguo (Todavía Soportado)
```json
{
  "name": "Juan Pérez",
  "email": "juan@example.com",
  "department_id": 1,
  "municipality_id": 28,
  "barrio_id": 12
}
```

### 2. **Actualizar Usuario** - `PUT /api/v1/users/{id}`

Mismo formato que crear. Usa `department_ids[]` para asignaciones múltiples.

```json
{
  "name": "Juan Pérez Actualizado",
  "department_ids": [1, 2],
  "municipality_ids": [28]
}
```

### 3. **Obtener Usuario** - `GET /api/v1/users/{id}`

#### Respuesta Actualizada
```json
{
  "data": {
    "id": 1,
    "name": "Juan Pérez",
    "email": "juan@example.com",
    
    "department_id": 1,
    "municipality_id": 28,
    "barrio_id": 12,
    
    "departments": [
      {
        "id": 1,
        "name": "Tolima",
        "codigo": "73"
      },
      {
        "id": 2,
        "name": "Cundinamarca",
        "codigo": "25"
      }
    ],
    "municipalities": [
      {
        "id": 28,
        "name": "Ibagué",
        "codigo": "73001"
      },
      {
        "id": 29,
        "name": "Bogotá",
        "codigo": "25001"
      }
    ],
    "barrios": [
      {
        "id": 12,
        "name": "San Simón",
        "codigo": "73001012"
      }
    ],
    "corregimientos": [],
    "veredas": []
  }
}
```

### 4. **Mi Equipo** - `GET /api/v1/organization/my-team`

#### Respuesta Actualizada
```json
{
  "data": {
    "id": 1,
    "name": "Líder Principal",
    "email": "lider@example.com",
    
    "geographic_assignments": {
      "departments": [
        {"id": 1, "name": "Tolima", "codigo": "73"}
      ],
      "municipalities": [
        {"id": 28, "name": "Ibagué", "codigo": "73001"},
        {"id": 29, "name": "Espinal", "codigo": "73268"}
      ],
      "communes": [],
      "barrios": [
        {"id": 12, "name": "San Simón", "codigo": "73001012"}
      ],
      "corregimientos": [],
      "veredas": []
    },
    
    "geographic_assignment": {
      "department": {"id": 1, "name": "Tolima"},
      "municipality": {"id": 28, "name": "Ibagué"},
      "barrio": {"id": 12, "name": "San Simón"}
    },
    
    "subordinates": [...]
  }
}
```

---

## 🗄️ Estructura de Base de Datos

### Nueva Tabla: `user_geographic_assignments`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint | ID único |
| `user_id` | bigint | FK a users |
| `tenant_id` | bigint | FK a tenants |
| `assignable_type` | string | Modelo (Department, Municipality, etc.) |
| `assignable_id` | bigint | ID de la ubicación geográfica |
| `created_at` | timestamp | Fecha de creación |
| `updated_at` | timestamp | Fecha de actualización |

**Índices:**
- `user_geographic_unique`: Previene duplicados (user_id, assignable_type, assignable_id)
- `assignable_type + assignable_id`: Búsqueda por tipo de geografía
- `user_id + tenant_id`: Filtrado por usuario y tenant
- `tenant_id + assignable_type`: Filtrado por tenant y tipo

### Columnas Antiguas en `users` (Deprecated)

Las siguientes columnas se mantienen por compatibilidad pero están **deprecadas**:
- `department_id`
- `municipality_id`
- `commune_id`
- `barrio_id`
- `corregimiento_id`
- `vereda_id`

⚠️ **Recomendación**: Usa las nuevas relaciones many-to-many en lugar de estas columnas.

---

## 💻 Ejemplos de Uso

### Ejemplo 1: Crear usuario con múltiples municipios

```bash
curl -X POST http://localhost:8000/api/v1/users \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Coordinador Regional",
    "email": "coordinador@example.com",
    "phone": "3001234567",
    "roles": ["coordinator"],
    "department_ids": [1],
    "municipality_ids": [28, 29, 30]
  }'
```

### Ejemplo 2: Actualizar asignaciones geográficas

```bash
curl -X PUT http://localhost:8000/api/v1/users/5 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "municipality_ids": [28, 29, 30, 31],
    "barrio_ids": [12, 13]
  }'
```

### Ejemplo 3: Obtener usuario con relaciones geográficas

```bash
curl -X GET "http://localhost:8000/api/v1/users/5?include=departments,municipalities,barrios" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 🔄 Migración de Datos

La migración de datos existentes se ejecutó automáticamente con:

```php
php artisan migrate
```

Esto copió todos los datos de las columnas individuales (`department_id`, etc.) a la nueva tabla `user_geographic_assignments`.

### Verificar Migración

```php
php artisan tinker
```

```php
// Ver total de asignaciones migradas
DB::table('user_geographic_assignments')->count();

// Ver asignaciones de un usuario específico
$user = User::find(1);
$user->departments; // Collection de departamentos
$user->municipalities; // Collection de municipios
```

---

## ⚠️ Retrocompatibilidad

### Frontend Antiguo
Si tu frontend todavía usa el formato antiguo (`department_id`), seguirá funcionando:

```json
{
  "department_id": 1,
  "municipality_id": 28
}
```

Esto se guardará tanto en las columnas antiguas como en la nueva tabla pivot.

### Respuestas de API
Las respuestas incluyen AMBOS formatos:
- **Nuevo**: `departments` (array)
- **Antiguo**: `department` (objeto único)

Esto permite una migración gradual del frontend.

---

## 📊 Modelo de Datos (Laravel)

### User Model

```php
// NUEVO: Many-to-Many
$user->departments; // Collection
$user->municipalities; // Collection
$user->barrios; // Collection

// ANTIGUO: One-to-One (deprecated)
$user->department; // Single model
$user->municipality; // Single model
$user->barrio; // Single model

// Sincronizar asignaciones
$user->departments()->sync([1, 2, 3]);
$user->municipalities()->sync([28, 29]);
```

---

## 🎨 Guía de Implementación Frontend

### 1. **Cambios en el Formulario de Creación/Edición de Usuarios**

#### Antes (Select Simple)
```html
<!-- Selección única de departamento -->
<select name="department_id">
  <option value="">Seleccionar departamento</option>
  <option value="1">Tolima</option>
  <option value="2">Cundinamarca</option>
</select>
```

#### Ahora (Multi-Select Recomendado)
```html
<!-- Selección múltiple de departamentos -->
<select name="department_ids" multiple>
  <option value="1">Tolima</option>
  <option value="2">Cundinamarca</option>
  <option value="3">Antioquia</option>
</select>

<!-- O mejor, usar un componente de selección múltiple tipo checkbox/tags -->
<div class="multi-select">
  <label>
    <input type="checkbox" name="department_ids[]" value="1"> Tolima
  </label>
  <label>
    <input type="checkbox" name="department_ids[]" value="2"> Cundinamarca
  </label>
  <label>
    <input type="checkbox" name="department_ids[]" value="3"> Antioquia
  </label>
</div>
```

### 2. **Componente React/Vue Sugerido**

#### React Example
```jsx
import { useState, useEffect } from 'react';
import Select from 'react-select'; // o similar

function UserForm({ user = null }) {
  const [formData, setFormData] = useState({
    name: user?.name || '',
    email: user?.email || '',
    phone: user?.phone || '',
    department_ids: user?.departments?.map(d => d.id) || [],
    municipality_ids: user?.municipalities?.map(m => m.id) || [],
    barrio_ids: user?.barrios?.map(b => b.id) || [],
  });

  const [departments, setDepartments] = useState([]);
  const [municipalities, setMunicipalities] = useState([]);
  const [barrios, setBarrios] = useState([]);

  useEffect(() => {
    // Cargar catálogos
    fetch('/api/v1/departments').then(r => r.json()).then(data => {
      setDepartments(data.data.map(d => ({ value: d.id, label: d.nombre })));
    });
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    const method = user ? 'PUT' : 'POST';
    const url = user ? `/api/v1/users/${user.id}` : '/api/v1/users';
    
    const response = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify(formData)
    });
    
    if (response.ok) {
      alert('Usuario guardado exitosamente');
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="text"
        value={formData.name}
        onChange={e => setFormData({...formData, name: e.target.value})}
        placeholder="Nombre"
        required
      />
      
      <input
        type="email"
        value={formData.email}
        onChange={e => setFormData({...formData, email: e.target.value})}
        placeholder="Email"
        required
      />
      
      {/* Multi-select para departamentos */}
      <Select
        isMulti
        options={departments}
        value={departments.filter(d => formData.department_ids.includes(d.value))}
        onChange={(selected) => {
          setFormData({
            ...formData, 
            department_ids: selected.map(s => s.value)
          });
        }}
        placeholder="Seleccionar departamentos..."
      />
      
      {/* Multi-select para municipios */}
      <Select
        isMulti
        options={municipalities}
        value={municipalities.filter(m => formData.municipality_ids.includes(m.value))}
        onChange={(selected) => {
          setFormData({
            ...formData, 
            municipality_ids: selected.map(s => s.value)
          });
        }}
        placeholder="Seleccionar municipios..."
      />
      
      {/* Multi-select para barrios */}
      <Select
        isMulti
        options={barrios}
        value={barrios.filter(b => formData.barrio_ids.includes(b.value))}
        onChange={(selected) => {
          setFormData({
            ...formData, 
            barrio_ids: selected.map(s => s.value)
          });
        }}
        placeholder="Seleccionar barrios..."
      />
      
      <button type="submit">Guardar Usuario</button>
    </form>
  );
}

export default UserForm;
```

#### Vue Example
```vue
<template>
  <form @submit.prevent="handleSubmit">
    <input v-model="formData.name" placeholder="Nombre" required />
    <input v-model="formData.email" type="email" placeholder="Email" required />
    
    <!-- Multi-select para departamentos -->
    <multiselect
      v-model="selectedDepartments"
      :options="departments"
      :multiple="true"
      :close-on-select="false"
      placeholder="Seleccionar departamentos"
      label="nombre"
      track-by="id"
    />
    
    <!-- Multi-select para municipios -->
    <multiselect
      v-model="selectedMunicipalities"
      :options="municipalities"
      :multiple="true"
      :close-on-select="false"
      placeholder="Seleccionar municipios"
      label="nombre"
      track-by="id"
    />
    
    <button type="submit">Guardar Usuario</button>
  </form>
</template>

<script>
import Multiselect from 'vue-multiselect';

export default {
  components: { Multiselect },
  data() {
    return {
      formData: {
        name: '',
        email: '',
        phone: '',
      },
      departments: [],
      municipalities: [],
      selectedDepartments: [],
      selectedMunicipalities: [],
    };
  },
  computed: {
    department_ids() {
      return this.selectedDepartments.map(d => d.id);
    },
    municipality_ids() {
      return this.selectedMunicipalities.map(m => m.id);
    },
  },
  methods: {
    async loadCatalogs() {
      const depts = await fetch('/api/v1/departments').then(r => r.json());
      this.departments = depts.data;
      
      const munis = await fetch('/api/v1/municipalities').then(r => r.json());
      this.municipalities = munis.data;
    },
    async handleSubmit() {
      const payload = {
        ...this.formData,
        department_ids: this.department_ids,
        municipality_ids: this.municipality_ids,
      };
      
      const response = await fetch('/api/v1/users', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${this.$store.state.token}`
        },
        body: JSON.stringify(payload)
      });
      
      if (response.ok) {
        this.$toast.success('Usuario creado exitosamente');
      }
    },
  },
  mounted() {
    this.loadCatalogs();
  },
};
</script>
```

### 3. **Vista de Detalles de Usuario**

#### Mostrar Asignaciones Múltiples
```jsx
function UserDetail({ userId }) {
  const [user, setUser] = useState(null);

  useEffect(() => {
    fetch(`/api/v1/users/${userId}`)
      .then(r => r.json())
      .then(data => setUser(data.data));
  }, [userId]);

  if (!user) return <div>Cargando...</div>;

  return (
    <div className="user-detail">
      <h2>{user.name}</h2>
      <p>Email: {user.email}</p>
      <p>Teléfono: {user.phone}</p>
      
      {/* Mostrar departamentos asignados */}
      <div className="geographic-assignments">
        <h3>Asignaciones Geográficas</h3>
        
        {user.departments?.length > 0 && (
          <div>
            <strong>Departamentos:</strong>
            <ul>
              {user.departments.map(dept => (
                <li key={dept.id}>
                  {dept.name} <span className="badge">{dept.codigo}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
        
        {user.municipalities?.length > 0 && (
          <div>
            <strong>Municipios:</strong>
            <ul>
              {user.municipalities.map(muni => (
                <li key={muni.id}>
                  {muni.name} <span className="badge">{muni.codigo}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
        
        {user.barrios?.length > 0 && (
          <div>
            <strong>Barrios:</strong>
            <ul>
              {user.barrios.map(barrio => (
                <li key={barrio.id}>
                  {barrio.name} <span className="badge">{barrio.codigo}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </div>
  );
}
```

### 4. **Tabla/Lista de Usuarios**

```jsx
function UsersList() {
  const [users, setUsers] = useState([]);

  useEffect(() => {
    fetch('/api/v1/users')
      .then(r => r.json())
      .then(data => setUsers(data.data));
  }, []);

  return (
    <table>
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Email</th>
          <th>Departamentos</th>
          <th>Municipios</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        {users.map(user => (
          <tr key={user.id}>
            <td>{user.name}</td>
            <td>{user.email}</td>
            <td>
              {user.departments?.length > 0 ? (
                <div className="tags">
                  {user.departments.slice(0, 2).map(d => (
                    <span key={d.id} className="tag">{d.name}</span>
                  ))}
                  {user.departments.length > 2 && (
                    <span className="tag">+{user.departments.length - 2}</span>
                  )}
                </div>
              ) : (
                <span className="text-muted">Sin asignar</span>
              )}
            </td>
            <td>
              {user.municipalities?.length > 0 ? (
                <div className="tags">
                  {user.municipalities.slice(0, 2).map(m => (
                    <span key={m.id} className="tag">{m.name}</span>
                  ))}
                  {user.municipalities.length > 2 && (
                    <span className="tag">+{user.municipalities.length - 2}</span>
                  )}
                </div>
              ) : (
                <span className="text-muted">Sin asignar</span>
              )}
            </td>
            <td>
              <button onClick={() => editUser(user.id)}>Editar</button>
              <button onClick={() => deleteUser(user.id)}>Eliminar</button>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
```

### 5. **Filtros Avanzados**

```jsx
function UserFilters({ onFilter }) {
  const [filters, setFilters] = useState({
    department_ids: [],
    municipality_ids: [],
    barrio_ids: [],
  });

  const handleFilter = () => {
    const queryParams = new URLSearchParams();
    
    if (filters.department_ids.length > 0) {
      filters.department_ids.forEach(id => {
        queryParams.append('department_ids[]', id);
      });
    }
    
    if (filters.municipality_ids.length > 0) {
      filters.municipality_ids.forEach(id => {
        queryParams.append('municipality_ids[]', id);
      });
    }
    
    fetch(`/api/v1/users?${queryParams.toString()}`)
      .then(r => r.json())
      .then(data => onFilter(data.data));
  };

  return (
    <div className="filters">
      <Select
        isMulti
        placeholder="Filtrar por departamentos..."
        onChange={(selected) => {
          setFilters({...filters, department_ids: selected.map(s => s.value)});
        }}
      />
      
      <Select
        isMulti
        placeholder="Filtrar por municipios..."
        onChange={(selected) => {
          setFilters({...filters, municipality_ids: selected.map(s => s.value)});
        }}
      />
      
      <button onClick={handleFilter}>Aplicar Filtros</button>
    </div>
  );
}
```

### 6. **Validación Frontend**

```javascript
function validateUserForm(formData) {
  const errors = {};

  // Validar campos básicos
  if (!formData.name || formData.name.trim() === '') {
    errors.name = 'El nombre es obligatorio';
  }

  if (!formData.email || !isValidEmail(formData.email)) {
    errors.email = 'Email inválido';
  }

  // Validar que al menos tenga una asignación geográfica
  const hasGeographicAssignment = 
    (formData.department_ids && formData.department_ids.length > 0) ||
    (formData.municipality_ids && formData.municipality_ids.length > 0) ||
    (formData.commune_ids && formData.commune_ids.length > 0) ||
    (formData.barrio_ids && formData.barrio_ids.length > 0);

  if (!hasGeographicAssignment) {
    errors.geographic = 'Debe asignar al menos una ubicación geográfica';
  }

  // Validar arrays no vacíos si están presentes
  if (formData.department_ids && !Array.isArray(formData.department_ids)) {
    errors.department_ids = 'Los departamentos deben ser un array';
  }
  
  if (formData.commune_ids && !Array.isArray(formData.commune_ids)) {
    errors.commune_ids = 'Las comunas deben ser un array';
  }

  // ⚠️ IMPORTANTE: NO validar jerarquías geográficas
  // El backend permite comunas/barrios de diferentes municipios
  // Esta es una validación OPCIONAL del frontend si se requiere
  
  // Ejemplo de validación opcional de jerarquía (comentado por defecto):
  /*
  if (formData.commune_ids && formData.commune_ids.length > 0 && 
      formData.municipality_ids && formData.municipality_ids.length > 0) {
    // Aquí podrías validar que las comunas pertenezcan a los municipios seleccionados
    // Pero NO es requerido por el backend
  }
  */

  return {
    isValid: Object.keys(errors).length === 0,
    errors
  };
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}
```

### 7. **Manejo de Estados de Carga**

```jsx
function UserForm() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(false);

  const handleSubmit = async (formData) => {
    setLoading(true);
    setError(null);
    setSuccess(false);

    try {
      const response = await fetch('/api/v1/users', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(formData)
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Error al guardar usuario');
      }

      setSuccess(true);
      // Redirigir o limpiar formulario
      
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      {error && <div className="alert alert-danger">{error}</div>}
      {success && <div className="alert alert-success">Usuario guardado exitosamente</div>}
      {loading && <div className="spinner">Guardando...</div>}
      
      {/* Resto del formulario */}
    </div>
  );
}
```

### 8. **Campos Requeridos por el Backend**

#### Para Crear Usuario (POST)
```javascript
const requiredFields = {
  // Obligatorios
  name: 'string',
  email: 'string (único)',
  password: 'string (mínimo 6 caracteres)',
  
  // Opcionales pero recomendados
  phone: 'string',
  cedula: 'string',
  is_team_leader: 'boolean',
  reports_to: 'integer (user_id del supervisor)',
  roles: ['string'] // Array de nombres de roles
  
  // Asignaciones geográficas (al menos una)
  department_ids: [1, 2], // Array de integers
  municipality_ids: [28, 29], // Array de integers
  commune_ids: [], // Array de integers
  barrio_ids: [], // Array de integers
  corregimiento_ids: [], // Array de integers
  vereda_ids: [] // Array de integers
};
```

#### Para Actualizar Usuario (PUT)
```javascript
const updateFields = {
  // Todos los campos son opcionales
  name: 'string',
  email: 'string',
  phone: 'string',
  
  // Si envías password, debe tener mínimo 6 caracteres
  password: 'string',
  
  // Arrays de asignaciones geográficas
  department_ids: [1, 2],
  municipality_ids: [28],
  
  // Si envías un array vacío, se eliminarán todas las asignaciones de ese tipo
  barrio_ids: [] // Esto elimina todos los barrios asignados
};
```

### 9. **Estilos CSS Sugeridos**

```css
/* Tags para mostrar múltiples ubicaciones */
.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.tag {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  background-color: #007bff;
  color: white;
  border-radius: 1rem;
  font-size: 0.875rem;
}

.tag.secondary {
  background-color: #6c757d;
}

/* Multi-select personalizado */
.multi-select {
  border: 1px solid #ced4da;
  border-radius: 0.25rem;
  padding: 0.5rem;
  max-height: 200px;
  overflow-y: auto;
}

.multi-select label {
  display: block;
  padding: 0.5rem;
  cursor: pointer;
}

.multi-select label:hover {
  background-color: #f8f9fa;
}

.multi-select input[type="checkbox"] {
  margin-right: 0.5rem;
}

/* Geographic assignments display */
.geographic-assignments {
  background-color: #f8f9fa;
  padding: 1rem;
  border-radius: 0.25rem;
  margin-top: 1rem;
}

.geographic-assignments h3 {
  font-size: 1.25rem;
  margin-bottom: 1rem;
}

.geographic-assignments ul {
  list-style: none;
  padding: 0;
}

.geographic-assignments li {
  padding: 0.5rem 0;
  border-bottom: 1px solid #dee2e6;
}

.geographic-assignments li:last-child {
  border-bottom: none;
}

.badge {
  background-color: #e9ecef;
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  margin-left: 0.5rem;
  color: #495057;
}
```

---

## 🚀 Checklist de Implementación Frontend

- [ ] **Actualizar formulario de creación de usuarios**
  - [ ] Cambiar selects simples por multi-selects
  - [ ] Validar que al menos una ubicación geográfica esté seleccionada
  - [ ] Enviar arrays `department_ids[]` en lugar de `department_id`

- [ ] **Actualizar formulario de edición de usuarios**
  - [ ] Cargar asignaciones actuales (usar `user.departments[]`)
  - [ ] Permitir agregar/remover ubicaciones
  - [ ] Mantener sincronizado con el backend

- [ ] **Actualizar vista de detalles**
  - [ ] Mostrar todas las ubicaciones asignadas (no solo la primera)
  - [ ] Usar `user.departments[]` en lugar de `user.department`

- [ ] **Actualizar lista/tabla de usuarios**
  - [ ] Mostrar resumen de múltiples ubicaciones (ej: "3 departamentos")
  - [ ] Agregar tooltips o expandibles para ver todas

- [ ] **Actualizar filtros**
  - [ ] Permitir filtrar por múltiples ubicaciones
  - [ ] Enviar arrays en query params

- [ ] **Testing**
  - [ ] Probar crear usuario con múltiples departamentos
  - [ ] Probar actualizar asignaciones geográficas
  - [ ] Verificar que arrays vacíos eliminan asignaciones
  - [ ] Probar retrocompatibilidad con formato antiguo

---

## � Ejemplos Completos de Payloads

### Ejemplo 1: Usuario Coordinador Regional (Múltiples Municipios)

```json
POST /api/v1/users

{
  "name": "Carlos Rodríguez",
  "email": "carlos.rodriguez@example.com",
  "phone": "3101234567",
  "cedula": "12345678",
  "password": "segura123",
  "is_team_leader": true,
  "reports_to": 2,
  "roles": ["coordinator"],
  
  "department_ids": [1],
  "municipality_ids": [28, 29, 30, 31],
  "barrio_ids": []
}
```

**Respuesta:**
```json
{
  "data": {
    "id": 24,
    "tenant_id": 1,
    "name": "Carlos Rodríguez",
    "email": "carlos.rodriguez@example.com",
    "phone": "3101234567",
    "cedula": "12345678",
    "is_super_admin": false,
    "is_team_leader": true,
    "reports_to": 2,
    
    "departments": [
      {"id": 1, "name": "Tolima", "codigo": "73"}
    ],
    "municipalities": [
      {"id": 28, "name": "Cunday", "codigo": "73226"},
      {"id": 29, "name": "Dolores", "codigo": "73236"},
      {"id": 30, "name": "Espinal", "codigo": "73268"},
      {"id": 31, "name": "Falan", "codigo": "73270"}
    ],
    "barrios": [],
    
    "roles": ["coordinator"],
    "created_at": "2025-11-08T10:30:00.000000Z",
    "updated_at": "2025-11-08T10:30:00.000000Z"
  },
  "message": "User created successfully",
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

### Ejemplo 2: Usuario Operador de Campo (Barrios Específicos)

```json
POST /api/v1/users

{
  "name": "María López",
  "email": "maria.lopez@example.com",
  "phone": "3209876543",
  "password": "miclave456",
  "is_team_leader": false,
  "reports_to": 5,
  "roles": ["operator"],
  
  "department_ids": [1],
  "municipality_ids": [28],
  "barrio_ids": [12, 13, 14, 15]
}
```

**Respuesta:**
```json
{
  "data": {
    "id": 25,
    "name": "María López",
    "email": "maria.lopez@example.com",
    "phone": "3209876543",
    "is_team_leader": false,
    "reports_to": 5,
    
    "departments": [
      {"id": 1, "name": "Tolima", "codigo": "73"}
    ],
    "municipalities": [
      {"id": 28, "name": "Cunday", "codigo": "73226"}
    ],
    "barrios": [
      {"id": 12, "name": "San Simón", "codigo": "73001012"},
      {"id": 13, "name": "La Pola", "codigo": "73001013"},
      {"id": 14, "name": "Jordán", "codigo": "73001014"},
      {"id": 15, "name": "Calarcá", "codigo": "73001015"}
    ],
    
    "roles": ["operator"]
  },
  "message": "User created successfully",
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

### Ejemplo 3: Actualizar Asignaciones Geográficas

```json
PUT /api/v1/users/24

{
  "department_ids": [1, 2],
  "municipality_ids": [28, 29],
  "barrio_ids": []
}
```

**Respuesta:**
```json
{
  "data": {
    "id": 24,
    "name": "Carlos Rodríguez",
    
    "departments": [
      {"id": 1, "name": "Tolima", "codigo": "73"},
      {"id": 2, "name": "Cundinamarca", "codigo": "25"}
    ],
    "municipalities": [
      {"id": 28, "name": "Cunday", "codigo": "73226"},
      {"id": 29, "name": "Dolores", "codigo": "73236"}
    ],
    "barrios": [],
    
    "updated_at": "2025-11-08T11:45:00.000000Z"
  },
  "message": "User updated successfully"
}
```

### Ejemplo 4: Usuario con Comunas de DIFERENTES Municipios ⭐

**Caso de Uso Real:** Coordinador que trabaja en múltiples ciudades con comunas específicas.

```json
POST /api/v1/users

{
  "name": "Ana Martínez",
  "email": "ana.martinez@example.com",
  "phone": "3151234567",
  "password": "password123",
  "is_team_leader": true,
  "roles": ["regional_coordinator"],
  
  "department_ids": [1, 2],           // Tolima y Cundinamarca
  "municipality_ids": [28, 30],       // Ibagué y Bogotá
  "commune_ids": [1, 2, 3, 50, 51],   // 3 comunas de Ibagué + 2 de Bogotá
  "barrio_ids": [12, 13, 20, 21, 22]  // Barrios de ambos municipios
}
```

**Respuesta:**
```json
{
  "data": {
    "id": 26,
    "name": "Ana Martínez",
    "email": "ana.martinez@example.com",
    "is_team_leader": true,
    
    "departments": [
      {"id": 1, "name": "Tolima", "codigo": "73"},
      {"id": 2, "name": "Cundinamarca", "codigo": "25"}
    ],
    "municipalities": [
      {"id": 28, "name": "Ibagué", "codigo": "73001"},
      {"id": 30, "name": "Bogotá", "codigo": "25001"}
    ],
    "communes": [
      {"id": 1, "name": "Comuna 1", "codigo": "73001001"},
      {"id": 2, "name": "Comuna 2", "codigo": "73001002"},
      {"id": 3, "name": "Comuna 3", "codigo": "73001003"},
      {"id": 50, "name": "Comuna Centro", "codigo": "25001050"},
      {"id": 51, "name": "Comuna Norte", "codigo": "25001051"}
    ],
    "barrios": [
      {"id": 12, "name": "San Simón", "codigo": "73001012"},
      {"id": 13, "name": "La Pola", "codigo": "73001013"},
      {"id": 20, "name": "Chapinero", "codigo": "25001020"},
      {"id": 21, "name": "Usaquén", "codigo": "25001021"},
      {"id": 22, "name": "Suba", "codigo": "25001022"}
    ],
    
    "roles": ["regional_coordinator"]
  },
  "message": "User created successfully"
}
```

**✅ Nota Importante:** Las comunas 1, 2, 3 pertenecen a Ibagué y las comunas 50, 51 pertenecen a Bogotá. El sistema permite esta combinación sin restricciones.

### Ejemplo 5: Remover Todas las Asignaciones de un Tipo
    
    "updated_at": "2025-11-08T11:45:00.000000Z"
  },
  "message": "User updated successfully"
}
```

### Ejemplo 4: Remover Todas las Asignaciones de un Tipo

```json
PUT /api/v1/users/24

{
  "municipality_ids": []
}
```

Esto eliminará TODAS las asignaciones de municipios del usuario, pero mantendrá las demás (departamentos, barrios, etc.).

### Ejemplo 5: Modo Compatibilidad (Formato Antiguo)

```json
POST /api/v1/users

{
  "name": "Usuario Legacy",
  "email": "legacy@example.com",
  "password": "password123",
  
  "department_id": 1,
  "municipality_id": 28,
  "barrio_id": 12
}
```

**Respuesta:** Incluirá tanto el formato antiguo como el nuevo:
```json
{
  "data": {
    "id": 26,
    "name": "Usuario Legacy",
    
    "department_id": 1,
    "municipality_id": 28,
    "barrio_id": 12,
    
    "departments": [
      {"id": 1, "name": "Tolima", "codigo": "73"}
    ],
    "municipalities": [
      {"id": 28, "name": "Cunday", "codigo": "73226"}
    ],
    "barrios": [
      {"id": 12, "name": "San Simón", "codigo": "73001012"}
    ],
    
    "department": {"id": 1, "name": "Tolima"},
    "municipality": {"id": 28, "name": "Cunday"},
    "barrio": {"id": 12, "name": "San Simón"}
  }
}
```
