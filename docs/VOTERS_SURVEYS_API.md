# API: Votantes, Encuestas y Llamadas

## Descripción General

Este módulo maneja el registro de votantes (sincronizados desde asistentes de reuniones), la creación de encuestas telefónicas y el registro de llamadas con sus respuestas.

## Tabla de Contenidos

1. [Votantes (Voters)](#votantes-voters)
2. [Encuestas (Surveys)](#encuestas-surveys)
3. [Preguntas de Encuestas (Survey Questions)](#preguntas-de-encuestas)
4. [Llamadas (Calls)](#llamadas-calls)
5. [Sincronización Automática](#sincronización-automática)

---

## Votantes (Voters)

### Listar Votantes

**Endpoint:** `GET /api/v1/voters`

**Parámetros de Query:**
- `per_page` (integer, opcional): Cantidad por página (default: 15)
- `search` (string, opcional): Buscar por cédula, nombre, email o teléfono
- `has_multiple_records` (boolean, opcional): Filtrar votantes con registros múltiples

**Ejemplo de Request:**
```bash
GET /api/v1/voters?per_page=20&search=juan&has_multiple_records=true
Authorization: Bearer {token}
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "tenant_id": 1,
      "cedula": "1032380898",
      "nombres": "Jenny",
      "apellidos": "Jaramillo",
      "full_name": "Jenny Jaramillo",
      "email": "jenny@gmail.com",
      "telefono": "3148976991",
      "direccion": "Calle 45 #23-12",
      "barrio_id": 1,
      "corregimiento_id": null,
      "vereda_id": null,
      "location_type": "barrio",
      "meeting_id": 29,
      "departamento_votacion": "Antioquia",
      "municipio_votacion": "Medellín",
      "puesto_votacion": "INEM José Félix de Restrepo",
      "direccion_puesto": "Carrera 48 #51-01",
      "mesa_votacion": "045",
      "has_multiple_records": false,
      "created_by": 1,
      "created_at": "2025-11-05T17:50:00.000000Z",
      "updated_at": "2025-11-05T17:50:00.000000Z",
      "barrio": {
        "id": 1,
        "nombre": "LA PAZ",
        "commune": {
          "id": 1,
          "nombre": "Comuna 2"
        }
      },
      "meeting": {
        "id": 29,
        "title": "Encuentro con vecinos La Paz"
      },
      "created_by_user": {
        "id": 1,
        "name": "Admin User"
      }
    }
  ],
  "pagination": {
    "total": 150,
    "per_page": 20,
    "current_page": 1,
    "last_page": 8,
    "from": 1,
    "to": 20
  }
}
```

---

### Crear Votante

**Endpoint:** `POST /api/v1/voters`

**Request Body:**
```json
{
  "cedula": "1234567890",
  "nombres": "María",
  "apellidos": "González",
  "email": "maria.gonzalez@email.com",
  "telefono": "3001234567",
  "direccion": "Carrera 50 #30-20 Apto 501",
  "barrio_id": 5,
  "corregimiento_id": null,
  "vereda_id": null,
  "meeting_id": 32,
  "departamento_votacion": "Antioquia",
  "municipio_votacion": "Medellín",
  "puesto_votacion": "Colegio San José",
  "direccion_puesto": "Calle 44 #52-03",
  "mesa_votacion": "012"
}
```

**Campos Requeridos:**
- `cedula` (string, max:20, único por tenant)
- `nombres` (string, max:255)
- `apellidos` (string, max:255)

**Campos Opcionales:**
- `email` (email, max:255)
- `telefono` (string, max:20)
- `direccion` (string, max:500)
- `barrio_id` (integer, exists en barrios)
- `corregimiento_id` (integer, exists en corregimientos)
- `vereda_id` (integer, exists en veredas)
- `meeting_id` (integer, exists en meetings)
- `departamento_votacion` (string, max:255)
- `municipio_votacion` (string, max:255)
- `puesto_votacion` (string, max:255)
- `direccion_puesto` (string, max:500)
- `mesa_votacion` (string, max:20)

**Respuesta Exitosa (201):**
```json
{
  "success": true,
  "message": "Votante creado exitosamente",
  "data": {
    "id": 151,
    "tenant_id": 1,
    "cedula": "1234567890",
    "nombres": "María",
    "apellidos": "González",
    "full_name": "María González",
    "email": "maria.gonzalez@email.com",
    "telefono": "3001234567",
    "direccion": "Carrera 50 #30-20 Apto 501",
    "barrio_id": 5,
    "location_type": "barrio",
    "meeting_id": 32,
    "departamento_votacion": "Antioquia",
    "municipio_votacion": "Medellín",
    "puesto_votacion": "Colegio San José",
    "direccion_puesto": "Calle 44 #52-03",
    "mesa_votacion": "012",
    "has_multiple_records": false,
    "created_by": 2,
    "created_at": "2025-11-05T18:00:00.000000Z",
    "updated_at": "2025-11-05T18:00:00.000000Z"
  }
}
```

**Respuesta de Error (422):**
```json
{
  "success": false,
  "errors": {
    "cedula": [
      "La cédula ya está registrada"
    ],
    "email": [
      "El email debe ser válido"
    ]
  }
}
```

---

### Ver Detalle de Votante

**Endpoint:** `GET /api/v1/voters/{id}`

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "cedula": "1032380898",
    "nombres": "Jenny",
    "apellidos": "Jaramillo",
    "full_name": "Jenny Jaramillo",
    "email": "jenny@gmail.com",
    "telefono": "3148976991",
    "direccion": "Calle 45 #23-12",
    "location_type": "barrio",
    "meeting_id": 29,
    "departamento_votacion": "Antioquia",
    "municipio_votacion": "Medellín",
    "puesto_votacion": "INEM José Félix de Restrepo",
    "direccion_puesto": "Carrera 48 #51-01",
    "mesa_votacion": "045",
    "has_multiple_records": false,
    "created_at": "2025-11-05T17:50:00.000000Z",
    "barrio": {
      "id": 1,
      "nombre": "LA PAZ",
      "commune": {
        "id": 1,
        "nombre": "Comuna 2"
      }
    },
    "meeting": {
      "id": 29,
      "title": "Encuentro con vecinos La Paz",
      "planner": {
        "id": 2,
        "name": "Gildardo Patiño"
      }
    },
    "calls": [
      {
        "id": 1,
        "call_date": "2025-11-05T16:30:00.000000Z",
        "duration_seconds": 180,
        "duration_formatted": "3:00",
        "status": "completed",
        "notes": "Llamada exitosa, votante comprometido",
        "survey": {
          "id": 1,
          "titulo": "Encuesta de Intención de Voto"
        },
        "user": {
          "id": 3,
          "name": "Carlos López"
        }
      }
    ],
    "created_by_user": {
      "id": 1,
      "name": "Admin User"
    }
  }
}
```

---

### Actualizar Votante

**Endpoint:** `PUT /api/v1/voters/{id}`

**Request Body:** (Mismos campos que crear, todos opcionales excepto cedula, nombres, apellidos)

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Votante actualizado exitosamente",
  "data": {
    "id": 1,
    "cedula": "1032380898",
    "nombres": "Jenny",
    "apellidos": "Jaramillo Pérez",
    "full_name": "Jenny Jaramillo Pérez",
    "email": "jenny.new@email.com",
    "telefono": "3148976991",
    "has_multiple_records": false,
    "updated_at": "2025-11-05T18:15:00.000000Z"
  }
}
```

---

### Eliminar Votante

**Endpoint:** `DELETE /api/v1/voters/{id}`

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Votante eliminado exitosamente"
}
```

---

### Buscar Votante por Cédula

**Endpoint:** `GET /api/v1/voters/search/by-cedula?cedula={cedula}`

**Ejemplo:**
```bash
GET /api/v1/voters/search/by-cedula?cedula=1032380898
Authorization: Bearer {token}
```

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "cedula": "1032380898",
    "nombres": "Jenny",
    "apellidos": "Jaramillo",
    "full_name": "Jenny Jaramillo",
    "email": "jenny@gmail.com",
    "telefono": "3148976991"
  }
}
```

**Respuesta si no existe (404):**
```json
{
  "success": false,
  "message": "Votante no encontrado"
}
```

---

### Estadísticas de Votantes

**Endpoint:** `GET /api/v1/voters-stats`

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": {
    "total": 150,
    "with_email": 120,
    "with_phone": 145,
    "with_voting_info": 80,
    "with_multiple_records": 5,
    "by_location_type": {
      "barrio": 100,
      "corregimiento": 30,
      "vereda": 20
    }
  }
}
```

---

## Encuestas (Surveys)

### Listar Encuestas

**Endpoint:** `GET /api/v1/surveys`

**Parámetros de Query:**
- `per_page` (integer, opcional): Cantidad por página
- `is_active` (boolean, opcional): Filtrar por activas/inactivas

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "tenant_id": 1,
      "titulo": "Encuesta de Intención de Voto",
      "descripcion": "Encuesta para medir la intención de voto en las próximas elecciones",
      "is_active": true,
      "is_current": true,
      "starts_at": "2025-11-01T00:00:00.000000Z",
      "ends_at": "2025-12-31T23:59:59.000000Z",
      "questions_count": 5,
      "created_by": 1,
      "created_at": "2025-11-01T10:00:00.000000Z",
      "questions": [
        {
          "id": 1,
          "question_text": "¿Piensa votar en las próximas elecciones?",
          "question_type": "yes_no",
          "order": 1,
          "is_required": true
        },
        {
          "id": 2,
          "question_text": "¿Por qué candidato votaría?",
          "question_type": "multiple_choice",
          "options": ["Candidato A", "Candidato B", "Candidato C", "Voto en blanco"],
          "order": 2,
          "is_required": true
        }
      ]
    }
  ],
  "pagination": {
    "total": 10,
    "per_page": 15,
    "current_page": 1,
    "last_page": 1
  }
}
```

---

### Crear Encuesta

**Endpoint:** `POST /api/v1/surveys`

**Request Body:**
```json
{
  "titulo": "Encuesta de Satisfacción",
  "descripcion": "Encuesta para medir satisfacción con la gestión actual",
  "is_active": true,
  "starts_at": "2025-11-05T00:00:00Z",
  "ends_at": "2025-11-30T23:59:59Z",
  "questions": [
    {
      "question_text": "¿Está satisfecho con la gestión actual?",
      "question_type": "yes_no",
      "order": 1,
      "is_required": true
    },
    {
      "question_text": "Califique del 1 al 5 la gestión en seguridad",
      "question_type": "scale",
      "options": {
        "min": 1,
        "max": 5,
        "labels": {
          "1": "Muy malo",
          "3": "Regular",
          "5": "Excelente"
        }
      },
      "order": 2,
      "is_required": true
    },
    {
      "question_text": "¿Qué le gustaría que mejorara?",
      "question_type": "text",
      "order": 3,
      "is_required": false
    }
  ]
}
```

**Campos Requeridos:**
- `titulo` (string, max:255)
- `questions` (array): Al menos una pregunta

**Campos de Pregunta:**
- `question_text` (string, requerido)
- `question_type` (enum, requerido): `multiple_choice`, `yes_no`, `text`, `scale`
- `options` (json, opcional): Opciones para multiple_choice o configuración para scale
- `order` (integer, opcional): Orden de la pregunta
- `is_required` (boolean, opcional): Default false

**Respuesta Exitosa (201):**
```json
{
  "success": true,
  "message": "Encuesta creada exitosamente",
  "data": {
    "id": 2,
    "titulo": "Encuesta de Satisfacción",
    "descripcion": "Encuesta para medir satisfacción con la gestión actual",
    "is_active": true,
    "is_current": true,
    "starts_at": "2025-11-05T00:00:00.000000Z",
    "ends_at": "2025-11-30T23:59:59.000000Z",
    "questions_count": 3,
    "created_at": "2025-11-05T18:30:00.000000Z"
  }
}
```

---

### Ver Detalle de Encuesta

**Endpoint:** `GET /api/v1/surveys/{id}`

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "titulo": "Encuesta de Intención de Voto",
    "descripcion": "Encuesta para medir la intención de voto",
    "is_active": true,
    "is_current": true,
    "starts_at": "2025-11-01T00:00:00.000000Z",
    "ends_at": "2025-12-31T23:59:59.000000Z",
    "questions_count": 5,
    "questions": [
      {
        "id": 1,
        "question_text": "¿Piensa votar en las próximas elecciones?",
        "question_type": "yes_no",
        "options": null,
        "order": 1,
        "is_required": true
      },
      {
        "id": 2,
        "question_text": "¿Por qué candidato votaría?",
        "question_type": "multiple_choice",
        "options": ["Candidato A", "Candidato B", "Candidato C", "Voto en blanco"],
        "order": 2,
        "is_required": true
      }
    ],
    "created_by": {
      "id": 1,
      "name": "Admin User"
    }
  }
}
```

---

### Activar/Desactivar Encuesta

**Activar:** `POST /api/v1/surveys/{id}/activate`

**Desactivar:** `POST /api/v1/surveys/{id}/deactivate`

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "message": "Encuesta activada exitosamente",
  "data": {
    "id": 1,
    "titulo": "Encuesta de Intención de Voto",
    "is_active": true
  }
}
```

---

### Clonar Encuesta

**Endpoint:** `POST /api/v1/surveys/{id}/clone`

**Request Body (opcional):**
```json
{
  "titulo": "Encuesta de Intención de Voto - Copia 2025"
}
```

**Respuesta Exitosa (201):**
```json
{
  "success": true,
  "message": "Encuesta clonada exitosamente",
  "data": {
    "id": 3,
    "titulo": "Encuesta de Intención de Voto - Copia 2025",
    "is_active": false,
    "questions_count": 5
  }
}
```

---

### Obtener Encuestas Activas

**Endpoint:** `GET /api/v1/surveys-active`

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "titulo": "Encuesta de Intención de Voto",
      "is_current": true,
      "questions_count": 5
    }
  ]
}
```

---

## Preguntas de Encuestas

### Agregar Pregunta a Encuesta

**Endpoint:** `POST /api/v1/surveys/{survey_id}/questions`

**Request Body:**
```json
{
  "question_text": "¿Cuál es su principal preocupación?",
  "question_type": "multiple_choice",
  "options": ["Seguridad", "Salud", "Educación", "Empleo", "Otro"],
  "order": 4,
  "is_required": true
}
```

**Respuesta Exitosa (201):**
```json
{
  "success": true,
  "message": "Pregunta agregada exitosamente",
  "data": {
    "id": 6,
    "survey_id": 1,
    "question_text": "¿Cuál es su principal preocupación?",
    "question_type": "multiple_choice",
    "options": ["Seguridad", "Salud", "Educación", "Empleo", "Otro"],
    "order": 4,
    "is_required": true
  }
}
```

---

### Actualizar Pregunta

**Endpoint:** `PUT /api/v1/questions/{id}`

**Request Body:** (Mismos campos que crear)

---

### Eliminar Pregunta

**Endpoint:** `DELETE /api/v1/questions/{id}`

---

## Llamadas (Calls)

### Registrar Llamada

**Endpoint:** `POST /api/v1/calls`

**Request Body:**
```json
{
  "voter_id": 1,
  "survey_id": 1,
  "call_date": "2025-11-05T16:30:00Z",
  "duration_seconds": 180,
  "status": "completed",
  "notes": "Llamada exitosa, votante comprometido",
  "responses": [
    {
      "survey_question_id": 1,
      "answer_text": "Sí"
    },
    {
      "survey_question_id": 2,
      "answer_text": "Candidato A"
    },
    {
      "survey_question_id": 3,
      "answer_text": "Mejorar la seguridad en el barrio y más alumbrado público"
    }
  ]
}
```

**Campos Requeridos:**
- `voter_id` (integer, exists en voters)
- `call_date` (datetime)
- `status` (enum): `completed`, `no_answer`, `busy`, `rejected`, `wrong_number`, `voicemail`

**Campos Opcionales:**
- `survey_id` (integer, exists en surveys)
- `duration_seconds` (integer)
- `notes` (text)
- `responses` (array): Respuestas a las preguntas de la encuesta

**Estados de Llamada:**
- `completed`: Completada con éxito
- `no_answer`: No contestó
- `busy`: Ocupado
- `rejected`: Rechazó la llamada
- `wrong_number`: Número equivocado
- `voicemail`: Buzón de voz

**Respuesta Exitosa (201):**
```json
{
  "success": true,
  "message": "Llamada registrada exitosamente",
  "data": {
    "id": 1,
    "tenant_id": 1,
    "voter_id": 1,
    "survey_id": 1,
    "user_id": 2,
    "call_date": "2025-11-05T16:30:00.000000Z",
    "duration_seconds": 180,
    "duration_formatted": "3:00",
    "status": "completed",
    "notes": "Llamada exitosa, votante comprometido",
    "responses_count": 3,
    "created_at": "2025-11-05T16:35:00.000000Z"
  }
}
```

---

### Listar Llamadas

**Endpoint:** `GET /api/v1/calls`

**Parámetros de Query:**
- `per_page` (integer, opcional)
- `status` (string, opcional): Filtrar por estado
- `survey_id` (integer, opcional): Filtrar por encuesta

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "call_date": "2025-11-05T16:30:00.000000Z",
      "duration_seconds": 180,
      "duration_formatted": "3:00",
      "status": "completed",
      "notes": "Llamada exitosa",
      "voter": {
        "id": 1,
        "full_name": "Jenny Jaramillo",
        "cedula": "1032380898",
        "telefono": "3148976991"
      },
      "survey": {
        "id": 1,
        "titulo": "Encuesta de Intención de Voto"
      },
      "user": {
        "id": 2,
        "name": "Carlos López"
      }
    }
  ],
  "pagination": {
    "total": 50,
    "per_page": 15,
    "current_page": 1,
    "last_page": 4
  }
}
```

---

### Ver Detalle de Llamada

**Endpoint:** `GET /api/v1/calls/{id}`

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "call_date": "2025-11-05T16:30:00.000000Z",
    "duration_seconds": 180,
    "duration_formatted": "3:00",
    "status": "completed",
    "notes": "Llamada exitosa, votante comprometido",
    "voter": {
      "id": 1,
      "full_name": "Jenny Jaramillo",
      "cedula": "1032380898",
      "telefono": "3148976991",
      "email": "jenny@gmail.com"
    },
    "survey": {
      "id": 1,
      "titulo": "Encuesta de Intención de Voto"
    },
    "user": {
      "id": 2,
      "name": "Carlos López"
    },
    "responses": [
      {
        "id": 1,
        "survey_question_id": 1,
        "answer_text": "Sí",
        "question": {
          "id": 1,
          "question_text": "¿Piensa votar en las próximas elecciones?",
          "question_type": "yes_no"
        }
      },
      {
        "id": 2,
        "survey_question_id": 2,
        "answer_text": "Candidato A",
        "question": {
          "id": 2,
          "question_text": "¿Por qué candidato votaría?",
          "question_type": "multiple_choice",
          "options": ["Candidato A", "Candidato B", "Candidato C"]
        }
      }
    ]
  }
}
```

---

### Llamadas por Votante

**Endpoint:** `GET /api/v1/voters/{voter_id}/calls`

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "call_date": "2025-11-05T16:30:00.000000Z",
      "duration_formatted": "3:00",
      "status": "completed",
      "survey": {
        "id": 1,
        "titulo": "Encuesta de Intención de Voto"
      },
      "user": {
        "id": 2,
        "name": "Carlos López"
      }
    }
  ]
}
```

---

### Estadísticas de Llamadas

**Endpoint:** `GET /api/v1/calls-stats`

**Parámetros de Query:**
- `date_from` (date, opcional): Fecha desde
- `date_to` (date, opcional): Fecha hasta
- `survey_id` (integer, opcional): Por encuesta específica

**Respuesta Exitosa (200):**
```json
{
  "success": true,
  "data": {
    "total_calls": 150,
    "by_status": {
      "completed": 120,
      "no_answer": 15,
      "busy": 8,
      "rejected": 5,
      "wrong_number": 2,
      "voicemail": 0
    },
    "average_duration": 165,
    "total_duration": 19800,
    "unique_voters_contacted": 145,
    "completion_rate": 80,
    "by_survey": [
      {
        "survey_id": 1,
        "titulo": "Encuesta de Intención de Voto",
        "total_calls": 100,
        "completed_calls": 85
      }
    ],
    "by_user": [
      {
        "user_id": 2,
        "name": "Carlos López",
        "total_calls": 50,
        "completed_calls": 45
      }
    ]
  }
}
```

---

## Sincronización Automática

### Comando Manual

Para sincronizar votantes manualmente:

```bash
# Sincronizar todos los tenants
php artisan voters:sync

# Sincronizar un tenant específico
php artisan voters:sync --tenant=1
```

**Salida del Comando:**
```
🔄 Iniciando sincronización de votantes...
📍 Sincronizando solo para tenant ID: 1
📊 Total de asistentes encontrados: 250
 250/250 [============================] 100%

✅ Sincronización completada:
+-------------------------------------------+----------+
| Estado                                    | Cantidad |
+-------------------------------------------+----------+
| Nuevos votantes creados                   | 180      |
| Votantes actualizados                     | 65       |
| Votantes omitidos (sin cambios)           | 3        |
| Votantes marcados con múltiples registros | 2        |
+-------------------------------------------+----------+
```

### Programación Automática

El sistema ejecuta automáticamente la sincronización **2 veces al día**:
- **6:00 AM** (Colombia/Bogotá)
- **6:00 PM** (Colombia/Bogotá)

Configurado en: `routes/console.php`

```php
Schedule::command('voters:sync')
    ->twiceDaily(6, 18)
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->onOneServer();
```

### Lógica de Sincronización

1. **Nuevo Votante:**
   - Si la cédula no existe, crea un nuevo registro
   - Guarda `meeting_id` de la primera reunión donde se registró

2. **Votante Existente:**
   - Actualiza los campos con los datos más recientes del asistente
   - Si detecta datos diferentes en múltiples reuniones, marca `has_multiple_records = true`

3. **Flag `has_multiple_records`:**
   - Indica que el votante tiene información diferente en varias reuniones
   - Permite al usuario revisar y corregir datos inconsistentes

---

## Ejemplos de Integración Frontend

### React: Listar Votantes con Búsqueda

```jsx
import { useState, useEffect } from 'react';
import axios from 'axios';

const VotersList = () => {
  const [voters, setVoters] = useState([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);

  const fetchVoters = async () => {
    setLoading(true);
    try {
      const response = await axios.get('/api/v1/voters', {
        params: { search, per_page: 20 }
      });
      setVoters(response.data.data);
    } catch (error) {
      console.error('Error fetching voters:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const timer = setTimeout(() => {
      fetchVoters();
    }, 500);
    return () => clearTimeout(timer);
  }, [search]);

  return (
    <div>
      <input
        type="text"
        placeholder="Buscar por cédula, nombre..."
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />
      {loading ? (
        <p>Cargando...</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Cédula</th>
              <th>Nombre</th>
              <th>Teléfono</th>
              <th>Puesto</th>
              <th>Mesa</th>
            </tr>
          </thead>
          <tbody>
            {voters.map(voter => (
              <tr key={voter.id}>
                <td>{voter.cedula}</td>
                <td>{voter.full_name}</td>
                <td>{voter.telefono || 'N/A'}</td>
                <td>{voter.puesto_votacion || 'N/A'}</td>
                <td>{voter.mesa_votacion || 'N/A'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};
```

### React: Registrar Llamada con Encuesta

```jsx
const CallForm = ({ voterId }) => {
  const [surveys, setSurveys] = useState([]);
  const [selectedSurvey, setSelectedSurvey] = useState(null);
  const [responses, setResponses] = useState({});
  const [callData, setCallData] = useState({
    status: 'completed',
    duration_seconds: 0,
    notes: ''
  });

  useEffect(() => {
    // Cargar encuestas activas
    axios.get('/api/v1/surveys-active').then(response => {
      setSurveys(response.data.data);
    });
  }, []);

  const handleSurveySelect = async (surveyId) => {
    const response = await axios.get(`/api/v1/surveys/${surveyId}`);
    setSelectedSurvey(response.data.data);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    const payload = {
      voter_id: voterId,
      survey_id: selectedSurvey?.id,
      call_date: new Date().toISOString(),
      ...callData,
      responses: Object.entries(responses).map(([questionId, answer]) => ({
        survey_question_id: parseInt(questionId),
        answer_text: answer
      }))
    };

    try {
      await axios.post('/api/v1/calls', payload);
      alert('Llamada registrada exitosamente');
    } catch (error) {
      console.error('Error:', error);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <select onChange={(e) => handleSurveySelect(e.target.value)}>
        <option value="">Seleccione una encuesta</option>
        {surveys.map(survey => (
          <option key={survey.id} value={survey.id}>
            {survey.titulo}
          </option>
        ))}
      </select>

      {selectedSurvey?.questions.map(question => (
        <div key={question.id}>
          <label>{question.question_text}</label>
          {question.question_type === 'yes_no' && (
            <select
              onChange={(e) => setResponses({
                ...responses,
                [question.id]: e.target.value
              })}
            >
              <option value="">Seleccione</option>
              <option value="Sí">Sí</option>
              <option value="No">No</option>
            </select>
          )}
          {question.question_type === 'multiple_choice' && (
            <select
              onChange={(e) => setResponses({
                ...responses,
                [question.id]: e.target.value
              })}
            >
              <option value="">Seleccione</option>
              {question.options.map(opt => (
                <option key={opt} value={opt}>{opt}</option>
              ))}
            </select>
          )}
          {question.question_type === 'text' && (
            <textarea
              onChange={(e) => setResponses({
                ...responses,
                [question.id]: e.target.value
              })}
            />
          )}
        </div>
      ))}

      <select
        value={callData.status}
        onChange={(e) => setCallData({...callData, status: e.target.value})}
      >
        <option value="completed">Completada</option>
        <option value="no_answer">No contestó</option>
        <option value="busy">Ocupado</option>
        <option value="rejected">Rechazó</option>
      </select>

      <input
        type="number"
        placeholder="Duración (segundos)"
        value={callData.duration_seconds}
        onChange={(e) => setCallData({
          ...callData,
          duration_seconds: parseInt(e.target.value)
        })}
      />

      <textarea
        placeholder="Notas"
        value={callData.notes}
        onChange={(e) => setCallData({...callData, notes: e.target.value})}
      />

      <button type="submit">Registrar Llamada</button>
    </form>
  );
};
```

---

## Notas Técnicas

### Seguridad
- Todos los endpoints requieren autenticación JWT
- Los datos están aislados por tenant (TenantScope)
- La sincronización automática usa `withoutOverlapping()` para evitar ejecuciones concurrentes

### Rendimiento
- Paginación en todos los listados
- Índices en campos de búsqueda frecuente (cedula, nombres, apellidos)
- Eager loading de relaciones para evitar queries N+1

### Base de Datos
- Soft deletes en voters y surveys
- Unique constraint: tenant_id + cedula
- Foreign keys con onDelete apropiados

### Tipos de Preguntas Soportados
1. **yes_no**: Sí/No
2. **multiple_choice**: Opciones múltiples (A, B, C...)
3. **text**: Texto libre
4. **scale**: Escala numérica (1-5, 1-10, etc.)
