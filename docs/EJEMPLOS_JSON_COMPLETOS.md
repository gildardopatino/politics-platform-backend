# Ejemplos JSON Completos - API Votantes, Encuestas y Llamadas

Este documento contiene **todos los ejemplos de JSON de entrada y salida** para cada endpoint de la API.

---

## 📌 ÍNDICE

1. [Votantes (Voters)](#votantes-voters)
2. [Encuestas (Surveys)](#encuestas-surveys)
3. [Preguntas de Encuestas](#preguntas-de-encuestas)
4. [Llamadas (Calls)](#llamadas-calls)

---

# VOTANTES (VOTERS)

## 1. GET /api/v1/voters - Listar Votantes

### Request
```http
GET /api/v1/voters?per_page=20&search=jenny&has_multiple_records=false
Authorization: Bearer {token}
```

### Response 200 OK
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
      "direccion_votacion": "Carrera 48 #51-01",
      "mesa_votacion": "045",
      "has_multiple_records": false,
      "created_by": 1,
      "created_at": "2025-11-05T17:50:00.000000Z",
      "updated_at": "2025-11-05T17:50:00.000000Z",
      "deleted_at": null,
      "barrio": {
        "id": 1,
        "nombre": "LA PAZ"
      },
      "corregimiento": null,
      "vereda": null,
      "meeting": {
        "id": 29,
        "title": "Encuentro con vecinos La Paz",
        "starts_at": "2025-11-06T04:00:00.000000Z"
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

## 2. POST /api/v1/voters - Crear Votante

### Request
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
  "direccion_votacion": "Calle 44 #52-03",
  "mesa_votacion": "012"
}
```

### Response 201 Created
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
    "corregimiento_id": null,
    "vereda_id": null,
    "location_type": "barrio",
    "meeting_id": 32,
    "departamento_votacion": "Antioquia",
    "municipio_votacion": "Medellín",
    "puesto_votacion": "Colegio San José",
    "direccion_votacion": "Calle 44 #52-03",
    "mesa_votacion": "012",
    "has_multiple_records": false,
    "created_by": 2,
    "created_at": "2025-11-05T18:00:00.000000Z",
    "updated_at": "2025-11-05T18:00:00.000000Z",
    "deleted_at": null,
    "barrio": {
      "id": 5,
      "nombre": "BOSTON"
    },
    "meeting": {
      "id": 32,
      "title": "Reunión Principal - Raíz"
    }
  }
}
```

### Response 422 Validation Error
```json
{
  "success": false,
  "errors": {
    "cedula": [
      "La cédula ya está registrada"
    ],
    "email": [
      "El formato del email no es válido"
    ],
    "telefono": [
      "El teléfono no puede tener más de 20 caracteres"
    ]
  }
}
```

---

## 3. GET /api/v1/voters/{id} - Ver Detalle de Votante

### Request
```http
GET /api/v1/voters/1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "data": {
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
    "location_type": "barrio",
    "meeting_id": 29,
    "departamento_votacion": "Antioquia",
    "municipio_votacion": "Medellín",
    "puesto_votacion": "INEM José Félix de Restrepo",
    "direccion_votacion": "Carrera 48 #51-01",
    "mesa_votacion": "045",
    "has_multiple_records": false,
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
    "corregimiento": null,
    "vereda": null,
    "meeting": {
      "id": 29,
      "title": "Encuentro con vecinos La Paz",
      "starts_at": "2025-11-06T04:00:00.000000Z",
      "planner": {
        "id": 2,
        "name": "Gildardo Patiño",
        "email": "gildardo@example.com"
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

## 4. PUT /api/v1/voters/{id} - Actualizar Votante

### Request
```json
{
  "cedula": "1032380898",
  "nombres": "Jenny",
  "apellidos": "Jaramillo Pérez",
  "email": "jenny.new@email.com",
  "telefono": "3148976991",
  "direccion": "Nueva Dirección 123",
  "barrio_id": 1,
  "departamento_votacion": "Antioquia",
  "municipio_votacion": "Medellín",
  "puesto_votacion": "INEM José Félix de Restrepo",
  "direccion_votacion": "Carrera 48 #51-01",
  "mesa_votacion": "045"
}
```

### Response 200 OK
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

## 5. DELETE /api/v1/voters/{id} - Eliminar Votante

### Request
```http
DELETE /api/v1/voters/1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Votante eliminado exitosamente"
}
```

---

## 6. GET /api/v1/voters/search/by-cedula - Buscar por Cédula

### Request
```http
GET /api/v1/voters/search/by-cedula?cedula=1032380898
Authorization: Bearer {token}
```

### Response 200 OK
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
    "barrio": {
      "id": 1,
      "nombre": "LA PAZ"
    }
  }
}
```

### Response 404 Not Found
```json
{
  "success": false,
  "message": "Votante no encontrado"
}
```

---

## 7. GET /api/v1/voters-stats - Estadísticas de Votantes

### Request
```http
GET /api/v1/voters-stats
Authorization: Bearer {token}
```

### Response 200 OK
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

# ENCUESTAS (SURVEYS)

## 1. GET /api/v1/surveys - Listar Encuestas

### Request
```http
GET /api/v1/surveys?per_page=15&is_active=true
Authorization: Bearer {token}
```

### Response 200 OK
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
      "updated_at": "2025-11-01T10:00:00.000000Z",
      "deleted_at": null,
      "created_by_user": {
        "id": 1,
        "name": "Admin User"
      },
      "questions": [
        {
          "id": 1,
          "survey_id": 1,
          "question_text": "¿Piensa votar en las próximas elecciones?",
          "question_type": "yes_no",
          "options": null,
          "order": 1,
          "is_required": true,
          "created_at": "2025-11-01T10:05:00.000000Z",
          "updated_at": "2025-11-01T10:05:00.000000Z"
        },
        {
          "id": 2,
          "survey_id": 1,
          "question_text": "¿Por qué candidato votaría?",
          "question_type": "multiple_choice",
          "options": [
            "Candidato A",
            "Candidato B",
            "Candidato C",
            "Voto en blanco"
          ],
          "order": 2,
          "is_required": true,
          "created_at": "2025-11-01T10:06:00.000000Z",
          "updated_at": "2025-11-01T10:06:00.000000Z"
        }
      ]
    }
  ],
  "pagination": {
    "total": 10,
    "per_page": 15,
    "current_page": 1,
    "last_page": 1,
    "from": 1,
    "to": 10
  }
}
```

---

## 2. POST /api/v1/surveys - Crear Encuesta

### Request
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
      "question_text": "¿Cuál es su principal preocupación?",
      "question_type": "multiple_choice",
      "options": [
        "Seguridad",
        "Salud",
        "Educación",
        "Empleo",
        "Otro"
      ],
      "order": 3,
      "is_required": true
    },
    {
      "question_text": "¿Qué le gustaría que mejorara?",
      "question_type": "text",
      "order": 4,
      "is_required": false
    }
  ]
}
```

### Response 201 Created
```json
{
  "success": true,
  "message": "Encuesta creada exitosamente",
  "data": {
    "id": 2,
    "tenant_id": 1,
    "titulo": "Encuesta de Satisfacción",
    "descripcion": "Encuesta para medir satisfacción con la gestión actual",
    "is_active": true,
    "is_current": true,
    "starts_at": "2025-11-05T00:00:00.000000Z",
    "ends_at": "2025-11-30T23:59:59.000000Z",
    "questions_count": 4,
    "created_by": 2,
    "created_at": "2025-11-05T18:30:00.000000Z",
    "updated_at": "2025-11-05T18:30:00.000000Z",
    "questions": [
      {
        "id": 6,
        "question_text": "¿Está satisfecho con la gestión actual?",
        "question_type": "yes_no",
        "options": null,
        "order": 1,
        "is_required": true
      },
      {
        "id": 7,
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
        "id": 8,
        "question_text": "¿Cuál es su principal preocupación?",
        "question_type": "multiple_choice",
        "options": [
          "Seguridad",
          "Salud",
          "Educación",
          "Empleo",
          "Otro"
        ],
        "order": 3,
        "is_required": true
      },
      {
        "id": 9,
        "question_text": "¿Qué le gustaría que mejorara?",
        "question_type": "text",
        "options": null,
        "order": 4,
        "is_required": false
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
    "titulo": [
      "El título es requerido"
    ],
    "questions": [
      "Debe incluir al menos una pregunta"
    ],
    "questions.0.question_type": [
      "El tipo de pregunta debe ser: multiple_choice, yes_no, text o scale"
    ],
    "ends_at": [
      "La fecha de fin debe ser posterior o igual a la fecha de inicio"
    ]
  }
}
```

---

## 3. GET /api/v1/surveys/{id} - Ver Detalle de Encuesta

### Request
```http
GET /api/v1/surveys/1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "data": {
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
    "updated_at": "2025-11-01T10:00:00.000000Z",
    "questions": [
      {
        "id": 1,
        "survey_id": 1,
        "question_text": "¿Piensa votar en las próximas elecciones?",
        "question_type": "yes_no",
        "options": null,
        "order": 1,
        "is_required": true
      },
      {
        "id": 2,
        "survey_id": 1,
        "question_text": "¿Por qué candidato votaría?",
        "question_type": "multiple_choice",
        "options": [
          "Candidato A",
          "Candidato B",
          "Candidato C",
          "Voto en blanco"
        ],
        "order": 2,
        "is_required": true
      },
      {
        "id": 3,
        "survey_id": 1,
        "question_text": "En una escala del 1 al 10, ¿qué tan satisfecho está?",
        "question_type": "scale",
        "options": {
          "min": 1,
          "max": 10
        },
        "order": 3,
        "is_required": true
      },
      {
        "id": 4,
        "survey_id": 1,
        "question_text": "¿Cuál es su principal preocupación?",
        "question_type": "text",
        "options": null,
        "order": 4,
        "is_required": false
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

## 4. PUT /api/v1/surveys/{id} - Actualizar Encuesta

### Request
```json
{
  "titulo": "Encuesta de Intención de Voto 2025 (Actualizada)",
  "descripcion": "Encuesta actualizada para medir intención de voto",
  "is_active": true,
  "starts_at": "2025-11-01T00:00:00Z",
  "ends_at": "2026-01-31T23:59:59Z",
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
      "question_text": "¿Por cuál candidato votaría? (Actualizado)",
      "question_type": "multiple_choice",
      "options": [
        "Candidato A",
        "Candidato B",
        "Candidato C",
        "Candidato D",
        "Voto en blanco"
      ],
      "order": 2,
      "is_required": true
    },
    {
      "question_text": "Nueva pregunta: ¿Recomendaría al candidato?",
      "question_type": "yes_no",
      "order": 3,
      "is_required": false
    }
  ]
}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Encuesta actualizada exitosamente",
  "data": {
    "id": 1,
    "titulo": "Encuesta de Intención de Voto 2025 (Actualizada)",
    "descripcion": "Encuesta actualizada para medir intención de voto",
    "is_active": true,
    "ends_at": "2026-01-31T23:59:59.000000Z",
    "questions_count": 3,
    "updated_at": "2025-11-05T19:00:00.000000Z",
    "questions": [
      {
        "id": 1,
        "question_text": "¿Piensa votar en las próximas elecciones?",
        "question_type": "yes_no",
        "order": 1
      },
      {
        "id": 2,
        "question_text": "¿Por cuál candidato votaría? (Actualizado)",
        "question_type": "multiple_choice",
        "options": [
          "Candidato A",
          "Candidato B",
          "Candidato C",
          "Candidato D",
          "Voto en blanco"
        ],
        "order": 2
      },
      {
        "id": 10,
        "question_text": "Nueva pregunta: ¿Recomendaría al candidato?",
        "question_type": "yes_no",
        "order": 3
      }
    ]
  }
}
```

---

## 5. DELETE /api/v1/surveys/{id} - Eliminar Encuesta

### Request
```http
DELETE /api/v1/surveys/1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Encuesta eliminada exitosamente"
}
```

---

## 6. POST /api/v1/surveys/{id}/activate - Activar Encuesta

### Request
```http
POST /api/v1/surveys/1/activate
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Encuesta activada exitosamente",
  "data": {
    "id": 1,
    "titulo": "Encuesta de Intención de Voto",
    "is_active": true,
    "is_current": true
  }
}
```

---

## 7. POST /api/v1/surveys/{id}/deactivate - Desactivar Encuesta

### Request
```http
POST /api/v1/surveys/1/deactivate
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Encuesta desactivada exitosamente",
  "data": {
    "id": 1,
    "titulo": "Encuesta de Intención de Voto",
    "is_active": false,
    "is_current": false
  }
}
```

---

## 8. POST /api/v1/surveys/{id}/clone - Clonar Encuesta

### Request
```json
{
  "titulo": "Encuesta de Intención de Voto - Copia 2025"
}
```

### Response 201 Created
```json
{
  "success": true,
  "message": "Encuesta clonada exitosamente",
  "data": {
    "id": 3,
    "tenant_id": 1,
    "titulo": "Encuesta de Intención de Voto - Copia 2025",
    "descripcion": "Encuesta para medir la intención de voto en las próximas elecciones",
    "is_active": false,
    "is_current": false,
    "starts_at": null,
    "ends_at": null,
    "questions_count": 5,
    "created_by": 2,
    "created_at": "2025-11-05T19:30:00.000000Z",
    "questions": [
      {
        "id": 11,
        "question_text": "¿Piensa votar en las próximas elecciones?",
        "question_type": "yes_no",
        "order": 1,
        "is_required": true
      },
      {
        "id": 12,
        "question_text": "¿Por qué candidato votaría?",
        "question_type": "multiple_choice",
        "options": [
          "Candidato A",
          "Candidato B",
          "Candidato C"
        ],
        "order": 2,
        "is_required": true
      }
    ]
  }
}
```

---

## 9. GET /api/v1/surveys-active - Encuestas Activas

### Request
```http
GET /api/v1/surveys-active
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "titulo": "Encuesta de Intención de Voto",
      "is_active": true,
      "is_current": true,
      "questions_count": 5,
      "starts_at": "2025-11-01T00:00:00.000000Z",
      "ends_at": "2025-12-31T23:59:59.000000Z",
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
          "options": [
            "Candidato A",
            "Candidato B",
            "Candidato C"
          ],
          "order": 2,
          "is_required": true
        }
      ]
    }
  ]
}
```

---

# PREGUNTAS DE ENCUESTAS

## 1. POST /api/v1/surveys/{survey_id}/questions - Agregar Pregunta

### Request
```json
{
  "question_text": "¿Cuál es su rango de edad?",
  "question_type": "multiple_choice",
  "options": [
    "18-25",
    "26-35",
    "36-45",
    "46-55",
    "56+"
  ],
  "order": 5,
  "is_required": true
}
```

### Response 201 Created
```json
{
  "success": true,
  "message": "Pregunta agregada exitosamente",
  "data": {
    "id": 13,
    "survey_id": 1,
    "question_text": "¿Cuál es su rango de edad?",
    "question_type": "multiple_choice",
    "options": [
      "18-25",
      "26-35",
      "36-45",
      "46-55",
      "56+"
    ],
    "order": 5,
    "is_required": true,
    "created_at": "2025-11-05T20:00:00.000000Z",
    "updated_at": "2025-11-05T20:00:00.000000Z"
  }
}
```

---

## 2. GET /api/v1/questions/{id} - Ver Detalle de Pregunta

### Request
```http
GET /api/v1/questions/1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "data": {
    "id": 1,
    "survey_id": 1,
    "question_text": "¿Piensa votar en las próximas elecciones?",
    "question_type": "yes_no",
    "options": null,
    "order": 1,
    "is_required": true,
    "created_at": "2025-11-01T10:05:00.000000Z",
    "updated_at": "2025-11-01T10:05:00.000000Z",
    "survey": {
      "id": 1,
      "titulo": "Encuesta de Intención de Voto"
    }
  }
}
```

---

## 3. PUT /api/v1/questions/{id} - Actualizar Pregunta

### Request
```json
{
  "question_text": "¿Piensa votar en las próximas elecciones presidenciales?",
  "question_type": "yes_no",
  "order": 1,
  "is_required": true
}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Pregunta actualizada exitosamente",
  "data": {
    "id": 1,
    "question_text": "¿Piensa votar en las próximas elecciones presidenciales?",
    "question_type": "yes_no",
    "options": null,
    "order": 1,
    "is_required": true,
    "updated_at": "2025-11-05T20:15:00.000000Z"
  }
}
```

---

## 4. DELETE /api/v1/questions/{id} - Eliminar Pregunta

### Request
```http
DELETE /api/v1/questions/1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Pregunta eliminada exitosamente"
}
```

---

# LLAMADAS (CALLS)

## 1. GET /api/v1/calls - Listar Llamadas

### Request
```http
GET /api/v1/calls?per_page=15&status=completed&survey_id=1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "data": [
    {
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
      "created_at": "2025-11-05T16:35:00.000000Z",
      "updated_at": "2025-11-05T16:35:00.000000Z",
      "voter": {
        "id": 1,
        "cedula": "1032380898",
        "nombres": "Jenny",
        "apellidos": "Jaramillo",
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
    "last_page": 4,
    "from": 1,
    "to": 15
  }
}
```

---

## 2. POST /api/v1/calls - Registrar Llamada

### Request
```json
{
  "voter_id": 1,
  "survey_id": 1,
  "call_date": "2025-11-05T16:30:00Z",
  "duration_seconds": 180,
  "status": "completed",
  "notes": "Llamada exitosa, votante comprometido. Mostró interés en participar en próximas reuniones.",
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
      "answer_text": "8"
    },
    {
      "survey_question_id": 4,
      "answer_text": "Mejorar la seguridad en el barrio y más alumbrado público en las noches"
    }
  ]
}
```

### Response 201 Created
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
    "notes": "Llamada exitosa, votante comprometido. Mostró interés en participar en próximas reuniones.",
    "created_at": "2025-11-05T16:35:00.000000Z",
    "updated_at": "2025-11-05T16:35:00.000000Z",
    "voter": {
      "id": 1,
      "cedula": "1032380898",
      "nombres": "Jenny",
      "apellidos": "Jaramillo",
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
        "call_id": 1,
        "survey_question_id": 1,
        "voter_id": 1,
        "answer_text": "Sí",
        "created_at": "2025-11-05T16:35:00.000000Z",
        "survey_question": {
          "id": 1,
          "question_text": "¿Piensa votar en las próximas elecciones?",
          "question_type": "yes_no"
        }
      },
      {
        "id": 2,
        "call_id": 1,
        "survey_question_id": 2,
        "voter_id": 1,
        "answer_text": "Candidato A",
        "created_at": "2025-11-05T16:35:00.000000Z",
        "survey_question": {
          "id": 2,
          "question_text": "¿Por qué candidato votaría?",
          "question_type": "multiple_choice"
        }
      },
      {
        "id": 3,
        "call_id": 1,
        "survey_question_id": 3,
        "voter_id": 1,
        "answer_text": "8",
        "created_at": "2025-11-05T16:35:00.000000Z",
        "survey_question": {
          "id": 3,
          "question_text": "En una escala del 1 al 10, ¿qué tan satisfecho está?",
          "question_type": "scale"
        }
      },
      {
        "id": 4,
        "call_id": 1,
        "survey_question_id": 4,
        "voter_id": 1,
        "answer_text": "Mejorar la seguridad en el barrio y más alumbrado público en las noches",
        "created_at": "2025-11-05T16:35:00.000000Z",
        "survey_question": {
          "id": 4,
          "question_text": "¿Cuál es su principal preocupación?",
          "question_type": "text"
        }
      }
    ]
  }
}
```

### Request (Llamada sin responder)
```json
{
  "voter_id": 5,
  "survey_id": null,
  "call_date": "2025-11-05T17:00:00Z",
  "duration_seconds": 0,
  "status": "no_answer",
  "notes": "No contestó después de 3 timbres"
}
```

### Response 201 Created
```json
{
  "success": true,
  "message": "Llamada registrada exitosamente",
  "data": {
    "id": 2,
    "voter_id": 5,
    "survey_id": null,
    "user_id": 2,
    "call_date": "2025-11-05T17:00:00.000000Z",
    "duration_seconds": 0,
    "duration_formatted": "0:00",
    "status": "no_answer",
    "notes": "No contestó después de 3 timbres",
    "created_at": "2025-11-05T17:05:00.000000Z"
  }
}
```

### Response 422 Validation Error
```json
{
  "success": false,
  "errors": {
    "voter_id": [
      "El votante seleccionado no existe"
    ],
    "status": [
      "El estado debe ser: completed, no_answer, busy, rejected, wrong_number, voicemail"
    ],
    "responses.0.survey_question_id": [
      "La pregunta seleccionada no existe"
    ]
  }
}
```

---

## 3. GET /api/v1/calls/{id} - Ver Detalle de Llamada

### Request
```http
GET /api/v1/calls/1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
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
    "created_at": "2025-11-05T16:35:00.000000Z",
    "updated_at": "2025-11-05T16:35:00.000000Z",
    "voter": {
      "id": 1,
      "cedula": "1032380898",
      "nombres": "Jenny",
      "apellidos": "Jaramillo",
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
        "call_id": 1,
        "survey_question_id": 1,
        "voter_id": 1,
        "answer_text": "Sí",
        "created_at": "2025-11-05T16:35:00.000000Z",
        "updated_at": "2025-11-05T16:35:00.000000Z",
        "survey_question": {
          "id": 1,
          "survey_id": 1,
          "question_text": "¿Piensa votar en las próximas elecciones?",
          "question_type": "yes_no",
          "options": null,
          "order": 1,
          "is_required": true
        }
      },
      {
        "id": 2,
        "call_id": 1,
        "survey_question_id": 2,
        "voter_id": 1,
        "answer_text": "Candidato A",
        "created_at": "2025-11-05T16:35:00.000000Z",
        "updated_at": "2025-11-05T16:35:00.000000Z",
        "survey_question": {
          "id": 2,
          "survey_id": 1,
          "question_text": "¿Por qué candidato votaría?",
          "question_type": "multiple_choice",
          "options": [
            "Candidato A",
            "Candidato B",
            "Candidato C",
            "Voto en blanco"
          ],
          "order": 2,
          "is_required": true
        }
      }
    ]
  }
}
```

---

## 4. PUT /api/v1/calls/{id} - Actualizar Llamada

### Request
```json
{
  "voter_id": 1,
  "survey_id": 1,
  "call_date": "2025-11-05T16:30:00Z",
  "duration_seconds": 240,
  "status": "completed",
  "notes": "Llamada exitosa, votante muy comprometido. Actualizó su duración."
}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Llamada actualizada exitosamente",
  "data": {
    "id": 1,
    "voter_id": 1,
    "survey_id": 1,
    "call_date": "2025-11-05T16:30:00.000000Z",
    "duration_seconds": 240,
    "duration_formatted": "4:00",
    "status": "completed",
    "notes": "Llamada exitosa, votante muy comprometido. Actualizó su duración.",
    "updated_at": "2025-11-05T18:00:00.000000Z"
  }
}
```

---

## 5. DELETE /api/v1/calls/{id} - Eliminar Llamada

### Request
```http
DELETE /api/v1/calls/1
Authorization: Bearer {token}
```

### Response 200 OK
```json
{
  "success": true,
  "message": "Llamada eliminada exitosamente"
}
```

---

## 6. GET /api/v1/voters/{voter_id}/calls - Llamadas por Votante

### Request
```http
GET /api/v1/voters/1/calls
Authorization: Bearer {token}
```

### Response 200 OK
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
      "notes": "Llamada exitosa, votante comprometido",
      "survey": {
        "id": 1,
        "titulo": "Encuesta de Intención de Voto"
      },
      "user": {
        "id": 2,
        "name": "Carlos López"
      }
    },
    {
      "id": 5,
      "call_date": "2025-11-03T14:00:00.000000Z",
      "duration_seconds": 0,
      "duration_formatted": "0:00",
      "status": "no_answer",
      "notes": "No contestó",
      "survey": null,
      "user": {
        "id": 2,
        "name": "Carlos López"
      }
    }
  ]
}
```

---

## 7. GET /api/v1/calls-stats - Estadísticas de Llamadas

### Request
```http
GET /api/v1/calls-stats?date_from=2025-11-01&date_to=2025-11-30&survey_id=1
Authorization: Bearer {token}
```

### Response 200 OK
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
      },
      {
        "survey_id": 2,
        "titulo": "Encuesta de Satisfacción",
        "total_calls": 50,
        "completed_calls": 35
      }
    ],
    "by_user": [
      {
        "user_id": 2,
        "name": "Carlos López",
        "total_calls": 50,
        "completed_calls": 45
      },
      {
        "user_id": 3,
        "name": "María Rodríguez",
        "total_calls": 45,
        "completed_calls": 38
      },
      {
        "user_id": 4,
        "name": "Juan Pérez",
        "total_calls": 40,
        "completed_calls": 30
      }
    ]
  }
}
```

---

## 📝 NOTAS IMPORTANTES

### Estados de Llamadas
- `completed`: Llamada completada con éxito
- `no_answer`: No contestó
- `busy`: Línea ocupada
- `rejected`: Rechazó la llamada
- `wrong_number`: Número equivocado
- `voicemail`: Buzón de voz

### Tipos de Preguntas
- `yes_no`: Respuesta Sí/No
- `multiple_choice`: Opciones múltiples
- `text`: Texto libre
- `scale`: Escala numérica

### Autorización
Todos los endpoints requieren:
```
Authorization: Bearer {JWT_TOKEN}
```

### Paginación
Parámetros estándar:
- `per_page`: Cantidad de registros por página (default: 15)
- `page`: Número de página (default: 1)

### Fechas
Formato ISO 8601: `2025-11-05T16:30:00Z`

### Códigos HTTP
- `200`: OK
- `201`: Created
- `422`: Validation Error
- `404`: Not Found
- `500`: Server Error
