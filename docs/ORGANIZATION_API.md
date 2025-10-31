# API de Estructura Organizacional

## 🎯 Overview

API para obtener la estructura jerárquica del equipo de cada tenant. Permite visualizar el árbol organizacional, reportes directos, cadena de mando y más.

**Características:**
- ✅ Árbol jerárquico completo recursivo
- ✅ Solo usuarios del tenant (excluye super admins)
- ✅ Estadísticas por usuario (reuniones, compromisos, etc.)
- ✅ Lista plana con supervisores
- ✅ Equipo directo de cada usuario
- ✅ Cadena de mando completa

---

## 📊 Endpoints Disponibles

### 1. GET /api/v1/organization/tree
Obtiene el árbol completo de la organización desde los nodos raíz.

### 2. GET /api/v1/organization/list
Lista plana de todos los usuarios con información de supervisores.

### 3. GET /api/v1/organization/my-team
Obtiene los subordinados directos del usuario autenticado.

### 4. GET /api/v1/organization/chain-of-command
Obtiene la cadena de supervisores hasta el nivel más alto.

---

## 🌳 1. Árbol Organizacional Completo

### GET /api/v1/organization/tree

Devuelve la estructura jerárquica completa del tenant en forma de árbol recursivo. Ideal para visualizaciones tipo organigramas.

#### Request
```bash
GET /api/v1/organization/tree
Authorization: Bearer {jwt_token}
```

#### Response 200 OK
```json
{
  "data": [
    {
      "id": 5,
      "name": "Carlos Ramírez",
      "email": "carlos@example.com",
      "phone": "+573001234567",
      "cedula": "1234567890",
      "is_team_leader": true,
      "roles": ["admin", "coordinator"],
      "subordinates_count": 3,
      "stats": {
        "total_team_size": 8,
        "direct_reports": 3,
        "meetings_planned": 15,
        "commitments_assigned": 25,
        "commitments_completed": 18
      },
      "subordinates": [
        {
          "id": 12,
          "name": "María González",
          "email": "maria@example.com",
          "phone": "+573009876543",
          "cedula": "9876543210",
          "is_team_leader": true,
          "roles": ["coordinator"],
          "subordinates_count": 2,
          "stats": {
            "total_team_size": 2,
            "direct_reports": 2,
            "meetings_planned": 8,
            "commitments_assigned": 12,
            "commitments_completed": 10
          },
          "subordinates": [
            {
              "id": 25,
              "name": "Juan Pérez",
              "email": "juan@example.com",
              "phone": "+573001112222",
              "cedula": "1112223334",
              "is_team_leader": false,
              "roles": ["operator"],
              "subordinates_count": 0,
              "stats": {
                "total_team_size": 0,
                "direct_reports": 0,
                "meetings_planned": 3,
                "commitments_assigned": 8,
                "commitments_completed": 6
              },
              "subordinates": []
            },
            {
              "id": 26,
              "name": "Ana López",
              "email": "ana@example.com",
              "phone": "+573003334444",
              "cedula": "4445556667",
              "is_team_leader": false,
              "roles": ["operator"],
              "subordinates_count": 0,
              "stats": {
                "total_team_size": 0,
                "direct_reports": 0,
                "meetings_planned": 2,
                "commitments_assigned": 5,
                "commitments_completed": 4
              },
              "subordinates": []
            }
          ]
        },
        {
          "id": 13,
          "name": "Pedro Martínez",
          "email": "pedro@example.com",
          "phone": "+573005556666",
          "cedula": "5556667778",
          "is_team_leader": false,
          "roles": ["operator"],
          "subordinates_count": 0,
          "stats": {
            "total_team_size": 0,
            "direct_reports": 0,
            "meetings_planned": 5,
            "commitments_assigned": 10,
            "commitments_completed": 8
          },
          "subordinates": []
        }
      ]
    },
    {
      "id": 6,
      "name": "Laura Sánchez",
      "email": "laura@example.com",
      "phone": "+573007778888",
      "cedula": "7778889990",
      "is_team_leader": true,
      "roles": ["coordinator"],
      "subordinates_count": 2,
      "stats": {
        "total_team_size": 2,
        "direct_reports": 2,
        "meetings_planned": 10,
        "commitments_assigned": 15,
        "commitments_completed": 12
      },
      "subordinates": [...]
    }
  ]
}
```

#### Estructura de Respuesta

**Campos por usuario:**
- `id`: ID del usuario
- `name`: Nombre completo
- `email`: Email
- `phone`: Teléfono
- `cedula`: Número de identificación
- `is_team_leader`: Si es líder de equipo
- `roles`: Array de roles asignados
- `subordinates_count`: Cantidad de subordinados directos
- `subordinates`: Array recursivo con los subordinados

**stats (estadísticas):**
- `total_team_size`: Total de personas en su equipo (incluyendo subniveles)
- `direct_reports`: Reportes directos (primer nivel)
- `meetings_planned`: Reuniones que ha planificado
- `commitments_assigned`: Compromisos asignados a él
- `commitments_completed`: Compromisos completados

---

## 📋 2. Lista Plana de Usuarios

### GET /api/v1/organization/list

Devuelve lista plana de todos los usuarios del tenant con información de su supervisor.

#### Request
```bash
GET /api/v1/organization/list
Authorization: Bearer {jwt_token}
```

#### Response 200 OK
```json
{
  "data": [
    {
      "id": 5,
      "name": "Carlos Ramírez",
      "email": "carlos@example.com",
      "phone": "+573001234567",
      "cedula": "1234567890",
      "is_team_leader": true,
      "reports_to": null,
      "supervisor": null,
      "roles": ["admin", "coordinator"],
      "subordinates_count": 3
    },
    {
      "id": 12,
      "name": "María González",
      "email": "maria@example.com",
      "phone": "+573009876543",
      "cedula": "9876543210",
      "is_team_leader": true,
      "reports_to": 5,
      "supervisor": {
        "id": 5,
        "name": "Carlos Ramírez",
        "email": "carlos@example.com"
      },
      "roles": ["coordinator"],
      "subordinates_count": 2
    },
    {
      "id": 25,
      "name": "Juan Pérez",
      "email": "juan@example.com",
      "phone": "+573001112222",
      "cedula": "1112223334",
      "is_team_leader": false,
      "reports_to": 12,
      "supervisor": {
        "id": 12,
        "name": "María González",
        "email": "maria@example.com"
      },
      "roles": ["operator"],
      "subordinates_count": 0
    }
  ]
}
```

**Uso común:** Selectores, autocomplete, búsqueda de usuarios.

---

## 👥 3. Mi Equipo Directo

### GET /api/v1/organization/my-team

Devuelve los subordinados directos del usuario autenticado.

#### Request
```bash
GET /api/v1/organization/my-team
Authorization: Bearer {jwt_token}
```

#### Response 200 OK
```json
{
  "data": [
    {
      "id": 12,
      "name": "María González",
      "email": "maria@example.com",
      "phone": "+573009876543",
      "is_team_leader": true,
      "roles": ["coordinator"],
      "subordinates_count": 2,
      "stats": {
        "meetings_planned": 8,
        "commitments_assigned": 12,
        "commitments_completed": 10
      }
    },
    {
      "id": 13,
      "name": "Pedro Martínez",
      "email": "pedro@example.com",
      "phone": "+573005556666",
      "is_team_leader": false,
      "roles": ["operator"],
      "subordinates_count": 0,
      "stats": {
        "meetings_planned": 5,
        "commitments_assigned": 10,
        "commitments_completed": 8
      }
    }
  ]
}
```

**Uso común:** Dashboard de supervisor, panel "Mi Equipo".

---

## 🔗 4. Cadena de Mando

### GET /api/v1/organization/chain-of-command

Devuelve todos los supervisores del usuario autenticado hasta el nivel más alto.

#### Request
```bash
GET /api/v1/organization/chain-of-command
Authorization: Bearer {jwt_token}
```

#### Response 200 OK
```json
{
  "data": [
    {
      "id": 12,
      "name": "María González",
      "email": "maria@example.com",
      "is_team_leader": true,
      "roles": ["coordinator"]
    },
    {
      "id": 5,
      "name": "Carlos Ramírez",
      "email": "carlos@example.com",
      "is_team_leader": true,
      "roles": ["admin", "coordinator"]
    }
  ]
}
```

**Orden:** De supervisor inmediato hasta el nivel más alto.

**Uso común:** Breadcrumbs, escalamiento de tareas, flujos de aprobación.

---

## 💻 Integración Frontend

### React/Vue - Componente de Árbol

```javascript
// Fetch organization tree
const fetchOrgTree = async () => {
  const response = await fetch('/api/v1/organization/tree', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  
  const data = await response.json();
  return data.data;
};

// Componente recursivo para árbol
const OrgTreeNode = ({ user }) => {
  return (
    <div className="org-node">
      <div className="user-card">
        <h4>{user.name}</h4>
        <p>{user.roles.join(', ')}</p>
        <span>Equipo: {user.stats.total_team_size}</span>
        <span>Reportes: {user.stats.direct_reports}</span>
      </div>
      
      {user.subordinates.length > 0 && (
        <div className="subordinates">
          {user.subordinates.map(subordinate => (
            <OrgTreeNode key={subordinate.id} user={subordinate} />
          ))}
        </div>
      )}
    </div>
  );
};

// Renderizar árbol completo
const OrgChart = () => {
  const [orgTree, setOrgTree] = useState([]);
  
  useEffect(() => {
    fetchOrgTree().then(setOrgTree);
  }, []);
  
  return (
    <div className="org-chart">
      {orgTree.map(rootUser => (
        <OrgTreeNode key={rootUser.id} user={rootUser} />
      ))}
    </div>
  );
};
```

### CSS para Organigrama
```css
.org-chart {
  display: flex;
  flex-direction: column;
  gap: 2rem;
  padding: 2rem;
}

.org-node {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.user-card {
  background: white;
  border: 2px solid #3B82F6;
  border-radius: 8px;
  padding: 1rem;
  min-width: 200px;
  text-align: center;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.subordinates {
  display: flex;
  gap: 2rem;
  padding-left: 2rem;
  border-left: 2px solid #E5E7EB;
}

.user-card h4 {
  margin: 0;
  font-size: 1.1rem;
  color: #1F2937;
}

.user-card p {
  margin: 0.5rem 0;
  color: #6B7280;
  font-size: 0.9rem;
}

.user-card span {
  display: block;
  font-size: 0.85rem;
  color: #9CA3AF;
}
```

### Librería Recomendada: react-organizational-chart

```bash
npm install react-organizational-chart
```

```javascript
import { Tree, TreeNode } from 'react-organizational-chart';

const OrgChart = ({ data }) => {
  const renderNode = (user) => (
    <TreeNode
      label={
        <div className="user-card">
          <strong>{user.name}</strong>
          <div>{user.roles.join(', ')}</div>
          <small>Equipo: {user.stats.total_team_size}</small>
        </div>
      }
    >
      {user.subordinates.map(sub => renderNode(sub))}
    </TreeNode>
  );
  
  return (
    <Tree
      lineWidth="2px"
      lineColor="#3B82F6"
      lineBorderRadius="10px"
      label={<div>Organización</div>}
    >
      {data.map(rootUser => renderNode(rootUser))}
    </Tree>
  );
};
```

---

## 📊 Visualización con D3.js

```javascript
import * as d3 from 'd3';

const renderOrgChart = (data, container) => {
  const width = 1200;
  const height = 800;
  
  const svg = d3.select(container)
    .append('svg')
    .attr('width', width)
    .attr('height', height);
  
  const g = svg.append('g')
    .attr('transform', 'translate(40,40)');
  
  const tree = d3.tree()
    .size([height - 80, width - 160]);
  
  // Convertir datos a jerarquía
  const hierarchy = d3.hierarchy({
    name: 'Root',
    children: data
  }, d => d.subordinates);
  
  const root = tree(hierarchy);
  
  // Links (líneas)
  g.selectAll('.link')
    .data(root.links())
    .enter()
    .append('path')
    .attr('class', 'link')
    .attr('d', d3.linkHorizontal()
      .x(d => d.y)
      .y(d => d.x));
  
  // Nodos
  const node = g.selectAll('.node')
    .data(root.descendants())
    .enter()
    .append('g')
    .attr('class', 'node')
    .attr('transform', d => `translate(${d.y},${d.x})`);
  
  node.append('circle')
    .attr('r', 8)
    .attr('fill', d => d.data.is_team_leader ? '#3B82F6' : '#10B981');
  
  node.append('text')
    .attr('dx', 12)
    .attr('dy', 4)
    .text(d => d.data.name);
};
```

---

## 🎨 Casos de Uso

### 1. Organigrama Visual
```javascript
// Componente principal
<OrgChart data={orgTree} />
```

### 2. Selector de Supervisor
```javascript
// Lista plana para dropdown
const supervisors = await fetch('/api/v1/organization/list');

<select name="reports_to">
  <option value="">Sin supervisor</option>
  {supervisors.data
    .filter(u => u.is_team_leader)
    .map(user => (
      <option key={user.id} value={user.id}>
        {user.name} - {user.roles.join(', ')}
      </option>
    ))}
</select>
```

### 3. Dashboard de Equipo
```javascript
// Vista "Mi Equipo"
const MyTeamDashboard = () => {
  const [team, setTeam] = useState([]);
  
  useEffect(() => {
    fetch('/api/v1/organization/my-team')
      .then(r => r.json())
      .then(data => setTeam(data.data));
  }, []);
  
  return (
    <div className="team-dashboard">
      <h2>Mi Equipo ({team.length})</h2>
      {team.map(member => (
        <TeamMemberCard key={member.id} member={member} />
      ))}
    </div>
  );
};
```

### 4. Breadcrumb de Jerarquía
```javascript
// Mostrar cadena de mando
const HierarchyBreadcrumb = () => {
  const [chain, setChain] = useState([]);
  
  useEffect(() => {
    fetch('/api/v1/organization/chain-of-command')
      .then(r => r.json())
      .then(data => setChain(data.data.reverse())); // Invertir para mostrar de arriba a abajo
  }, []);
  
  return (
    <nav className="breadcrumb">
      {chain.map((supervisor, idx) => (
        <span key={supervisor.id}>
          {supervisor.name}
          {idx < chain.length - 1 && ' > '}
        </span>
      ))}
      <span className="current">Tú</span>
    </nav>
  );
};
```

---

## 🔍 Filtrado y Búsqueda

### Búsqueda en el árbol
```javascript
const searchInTree = (nodes, searchTerm) => {
  const results = [];
  
  const search = (node) => {
    if (node.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        node.email.toLowerCase().includes(searchTerm.toLowerCase())) {
      results.push(node);
    }
    
    node.subordinates.forEach(sub => search(sub));
  };
  
  nodes.forEach(root => search(root));
  return results;
};

// Uso
const results = searchInTree(orgTree, 'maria');
```

### Filtrar por rol
```javascript
const filterByRole = (nodes, roleName) => {
  const filtered = [];
  
  const filter = (node) => {
    if (node.roles.includes(roleName)) {
      filtered.push(node);
    }
    node.subordinates.forEach(sub => filter(sub));
  };
  
  nodes.forEach(root => filter(root));
  return filtered;
};

// Uso
const coordinators = filterByRole(orgTree, 'coordinator');
```

---

## 📈 Estadísticas Agregadas

### Calcular totales del tenant
```javascript
const calculateTotals = (orgTree) => {
  let totalUsers = 0;
  let totalLeaders = 0;
  let totalMeetings = 0;
  let totalCommitments = 0;
  
  const traverse = (node) => {
    totalUsers++;
    if (node.is_team_leader) totalLeaders++;
    totalMeetings += node.stats.meetings_planned;
    totalCommitments += node.stats.commitments_assigned;
    
    node.subordinates.forEach(sub => traverse(sub));
  };
  
  orgTree.forEach(root => traverse(root));
  
  return {
    totalUsers,
    totalLeaders,
    totalMeetings,
    totalCommitments
  };
};
```

---

## 🔒 Permisos

- **Todos los endpoints** requieren autenticación (JWT)
- Solo devuelve datos del **tenant del usuario autenticado**
- **Super admins globales** (tenant_id = null) NO aparecen en las listas
- Usuarios solo ven la estructura de su tenant

---

## ⚡ Performance

- **Eager loading** de relaciones para evitar N+1 queries
- **Recursión optimizada** con relaciones pre-cargadas
- **Cache recomendado** en frontend (5-10 minutos)

---

## 🚀 Próximas Mejoras

- [ ] Endpoint para reorganizar equipo (cambiar supervisor)
- [ ] Exportar organigrama como imagen/PDF
- [ ] Vista de "matriz" (users × roles)
- [ ] Comparación de performance entre equipos
- [ ] Timeline de cambios en la estructura

---

## 📚 Referencias

- Librería: [react-organizational-chart](https://github.com/daniel-hauser/react-organizational-chart)
- Librería: [d3-hierarchy](https://github.com/d3/d3-hierarchy)
- Librería: [orgchart.js](https://github.com/dabeng/OrgChart)
