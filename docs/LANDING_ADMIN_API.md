# API de Administración de Landing Page

Documentación completa de los endpoints de administración para la Landing Page del sistema de campañas políticas.

**Fecha:** 8 de Noviembre, 2025  
**Versión:** 1.0

---

## Índice

1. [Autenticación](#autenticación)
2. [Banners](#banners)
3. [Propuestas](#propuestas)
4. [Eventos](#eventos)
5. [Galería](#galería)
6. [Testimonios](#testimonios)
7. [Social Feed](#social-feed)
8. [Biografía](#biografía)

---

## Autenticación

Todos los endpoints de administración requieren autenticación mediante JWT Token.

**Header requerido:**
```
Authorization: Bearer {token}
```

---

## Banners

Gestión de banners principales de la landing page.

### 1. Listar Banners

**Endpoint:** `GET /api/v1/landingpage/admin/banners`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Juntos por un Mejor Futuro",
      "subtitle": "Transformando nuestra comunidad",
      "description": "Trabajando día a día por el progreso de nuestro municipio",
      "image": "https://wasabi.url/tenant-slug/landing/banners/banner1.jpg",
      "cta_text": "Conoce más",
      "cta_link": "/propuestas",
      "order": 1,
      "is_active": true,
      "created_at": "2025-11-01T10:00:00.000000Z",
      "updated_at": "2025-11-01T10:00:00.000000Z"
    }
  ]
}
```

---

### 2. Crear Banner

**Endpoint:** `POST /api/v1/landingpage/admin/banners`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
title: "Juntos por un Mejor Futuro" (requerido, string, max:255)
subtitle: "Transformando nuestra comunidad" (opcional, string, max:255)
description: "Trabajando día a día..." (opcional, string)
image: [archivo de imagen] (requerido, jpeg|png|jpg|webp, max:5MB)
cta_text: "Conoce más" (opcional, string, max:100)
cta_link: "/propuestas" (opcional, string, max:500)
order: 1 (opcional, integer)
is_active: true (opcional, boolean)
```

**Respuesta exitosa (201):**
```json
{
  "data": {
    "id": 1,
    "title": "Juntos por un Mejor Futuro",
    "subtitle": "Transformando nuestra comunidad",
    "description": "Trabajando día a día por el progreso de nuestro municipio",
    "image": "https://wasabi.url/tenant-slug/landing/banners/banner1.jpg",
    "cta_text": "Conoce más",
    "cta_link": "/propuestas",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  },
  "message": "Banner creado exitosamente"
}
```

**Respuesta de error (422):**
```json
{
  "errors": {
    "title": ["El campo título es obligatorio."],
    "image": ["El campo imagen es obligatorio."]
  }
}
```

---

### 3. Ver Banner Específico

**Endpoint:** `GET /api/v1/landingpage/admin/banners/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "title": "Juntos por un Mejor Futuro",
    "subtitle": "Transformando nuestra comunidad",
    "description": "Trabajando día a día por el progreso de nuestro municipio",
    "image": "https://wasabi.url/tenant-slug/landing/banners/banner1.jpg",
    "cta_text": "Conoce más",
    "cta_link": "/propuestas",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  }
}
```

---

### 4. Actualizar Banner

**Endpoint:** `PUT /api/v1/landingpage/admin/banners/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
title: "Nuevo título" (opcional, string, max:255)
subtitle: "Nuevo subtítulo" (opcional, string, max:255)
description: "Nueva descripción" (opcional, string)
image: [nuevo archivo de imagen] (opcional, jpeg|png|jpg|webp, max:5MB)
cta_text: "Nueva acción" (opcional, string, max:100)
cta_link: "/nueva-ruta" (opcional, string, max:500)
order: 2 (opcional, integer)
is_active: false (opcional, boolean)
```

**Nota:** Si se envía una nueva imagen, la imagen anterior será eliminada automáticamente.

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "title": "Nuevo título",
    "subtitle": "Nuevo subtítulo",
    "description": "Nueva descripción",
    "image": "https://wasabi.url/tenant-slug/landing/banners/banner1-updated.jpg",
    "cta_text": "Nueva acción",
    "cta_link": "/nueva-ruta",
    "order": 2,
    "is_active": false,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-08T15:30:00.000000Z"
  },
  "message": "Banner actualizado exitosamente"
}
```

---

### 5. Eliminar Banner

**Endpoint:** `DELETE /api/v1/landingpage/admin/banners/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "message": "Banner eliminado exitosamente"
}
```

**Nota:** La imagen asociada será eliminada automáticamente del storage.

---

## Propuestas

Gestión de propuestas políticas de la landing page.

### 1. Listar Propuestas

**Endpoint:** `GET /api/v1/landingpage/admin/propuestas`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": [
    {
      "id": 1,
      "categoria": "Seguridad",
      "titulo": "Seguridad para Todos",
      "descripcion": "Implementaremos un sistema integral de seguridad ciudadana que incluya modernización de la policía, cámaras de vigilancia y programas de prevención.",
      "puntos_clave": [
        "Modernización de equipos policiales",
        "Instalación de 500 cámaras de seguridad",
        "Programas de prevención del delito",
        "Iluminación LED en zonas críticas"
      ],
      "icono": "shield",
      "order": 1,
      "is_active": true,
      "created_at": "2025-11-01T10:00:00.000000Z",
      "updated_at": "2025-11-01T10:00:00.000000Z"
    }
  ]
}
```

---

### 2. Crear Propuesta

**Endpoint:** `POST /api/v1/landingpage/admin/propuestas`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Body (JSON):**
```json
{
  "categoria": "Educación",
  "titulo": "Educación de Calidad",
  "descripcion": "Mejoraremos la infraestructura educativa y garantizaremos acceso universal a la educación.",
  "puntos_clave": [
    "Construcción de 10 nuevas escuelas",
    "Dotación de tablets para estudiantes",
    "Capacitación docente continua",
    "Internet gratuito en todas las escuelas"
  ],
  "icono": "book-open",
  "order": 2,
  "is_active": true
}
```

**Valores permitidos para `icono`:**
- `shield` (Seguridad)
- `leaf` (Medio ambiente)
- `book-open` (Educación)
- `heart` (Salud)
- `briefcase` (Empleo)
- `construction` (Infraestructura)

**Respuesta exitosa (201):**
```json
{
  "data": {
    "id": 2,
    "categoria": "Educación",
    "titulo": "Educación de Calidad",
    "descripcion": "Mejoraremos la infraestructura educativa y garantizaremos acceso universal a la educación.",
    "puntos_clave": [
      "Construcción de 10 nuevas escuelas",
      "Dotación de tablets para estudiantes",
      "Capacitación docente continua",
      "Internet gratuito en todas las escuelas"
    ],
    "icono": "book-open",
    "order": 2,
    "is_active": true,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  },
  "message": "Propuesta creada exitosamente"
}
```

**Respuesta de error (422):**
```json
{
  "errors": {
    "categoria": ["El campo categoría es obligatorio."],
    "titulo": ["El campo título es obligatorio."],
    "descripcion": ["El campo descripción es obligatorio."],
    "puntos_clave": ["El campo puntos clave es obligatorio."],
    "icono": ["El icono seleccionado no es válido."]
  }
}
```

---

### 3. Ver Propuesta Específica

**Endpoint:** `GET /api/v1/landingpage/admin/propuestas/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "categoria": "Seguridad",
    "titulo": "Seguridad para Todos",
    "descripcion": "Implementaremos un sistema integral de seguridad ciudadana...",
    "puntos_clave": [
      "Modernización de equipos policiales",
      "Instalación de 500 cámaras de seguridad",
      "Programas de prevención del delito",
      "Iluminación LED en zonas críticas"
    ],
    "icono": "shield",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  }
}
```

---

### 4. Actualizar Propuesta

**Endpoint:** `PUT /api/v1/landingpage/admin/propuestas/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Body (JSON):**
```json
{
  "categoria": "Seguridad Ciudadana",
  "titulo": "Seguridad Integral",
  "descripcion": "Nueva descripción actualizada...",
  "puntos_clave": [
    "Punto 1 actualizado",
    "Punto 2 actualizado"
  ],
  "icono": "shield",
  "order": 1,
  "is_active": true
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "categoria": "Seguridad Ciudadana",
    "titulo": "Seguridad Integral",
    "descripcion": "Nueva descripción actualizada...",
    "puntos_clave": [
      "Punto 1 actualizado",
      "Punto 2 actualizado"
    ],
    "icono": "shield",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-08T15:45:00.000000Z"
  },
  "message": "Propuesta actualizada exitosamente"
}
```

---

### 5. Eliminar Propuesta

**Endpoint:** `DELETE /api/v1/landingpage/admin/propuestas/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "message": "Propuesta eliminada exitosamente"
}
```

---

## Eventos

Gestión de eventos de campaña para la landing page.

### 1. Listar Eventos

**Endpoint:** `GET /api/v1/landingpage/admin/eventos`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": [
    {
      "id": 1,
      "titulo": "Gran Caminata por la Educación",
      "fecha": "2025-11-15",
      "hora": "09:00 AM",
      "lugar": "Plaza Central, Bogotá",
      "descripcion": "Únete a nuestra caminata para promover la educación de calidad en todos los sectores.",
      "imagen": "https://wasabi.url/tenant-slug/landing/eventos/evento1.jpg",
      "tipo": "Caminata",
      "is_active": true,
      "created_at": "2025-11-01T10:00:00.000000Z",
      "updated_at": "2025-11-01T10:00:00.000000Z"
    }
  ]
}
```

---

### 2. Crear Evento

**Endpoint:** `POST /api/v1/landingpage/admin/eventos`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
titulo: "Gran Caminata por la Educación" (requerido, string, max:255)
fecha: "2025-11-15" (requerido, date, formato: YYYY-MM-DD)
hora: "09:00 AM" (requerido, string, max:50)
lugar: "Plaza Central, Bogotá" (requerido, string, max:255)
descripcion: "Únete a nuestra caminata..." (opcional, string)
imagen: [archivo de imagen] (opcional, jpeg|png|jpg|webp, max:5MB)
tipo: "Caminata" (opcional, string, max:100)
is_active: true (opcional, boolean)
```

**Respuesta exitosa (201):**
```json
{
  "data": {
    "id": 1,
    "titulo": "Gran Caminata por la Educación",
    "fecha": "2025-11-15",
    "hora": "09:00 AM",
    "lugar": "Plaza Central, Bogotá",
    "descripcion": "Únete a nuestra caminata para promover la educación de calidad en todos los sectores.",
    "imagen": "https://wasabi.url/tenant-slug/landing/eventos/evento1.jpg",
    "tipo": "Caminata",
    "is_active": true,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  },
  "message": "Evento creado exitosamente"
}
```

**Respuesta de error (422):**
```json
{
  "errors": {
    "titulo": ["El campo título es obligatorio."],
    "fecha": ["El campo fecha es obligatorio."],
    "hora": ["El campo hora es obligatorio."],
    "lugar": ["El campo lugar es obligatorio."]
  }
}
```

---

### 3. Ver Evento Específico

**Endpoint:** `GET /api/v1/landingpage/admin/eventos/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "titulo": "Gran Caminata por la Educación",
    "fecha": "2025-11-15",
    "hora": "09:00 AM",
    "lugar": "Plaza Central, Bogotá",
    "descripcion": "Únete a nuestra caminata para promover la educación de calidad en todos los sectores.",
    "imagen": "https://wasabi.url/tenant-slug/landing/eventos/evento1.jpg",
    "tipo": "Caminata",
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  }
}
```

---

### 4. Actualizar Evento

**Endpoint:** `PUT /api/v1/landingpage/admin/eventos/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
titulo: "Nuevo título del evento" (opcional, string, max:255)
fecha: "2025-11-20" (opcional, date)
hora: "10:00 AM" (opcional, string, max:50)
lugar: "Nuevo lugar" (opcional, string, max:255)
descripcion: "Nueva descripción" (opcional, string)
imagen: [nuevo archivo de imagen] (opcional, jpeg|png|jpg|webp, max:5MB)
tipo: "Conferencia" (opcional, string, max:100)
is_active: false (opcional, boolean)
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "titulo": "Nuevo título del evento",
    "fecha": "2025-11-20",
    "hora": "10:00 AM",
    "lugar": "Nuevo lugar",
    "descripcion": "Nueva descripción",
    "imagen": "https://wasabi.url/tenant-slug/landing/eventos/evento1-updated.jpg",
    "tipo": "Conferencia",
    "is_active": false,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-08T16:00:00.000000Z"
  },
  "message": "Evento actualizado exitosamente"
}
```

---

### 5. Eliminar Evento

**Endpoint:** `DELETE /api/v1/landingpage/admin/eventos/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "message": "Evento eliminado exitosamente"
}
```

---

## Galería

Gestión de imágenes de la galería de la landing page.

### 1. Listar Galería

**Endpoint:** `GET /api/v1/landingpage/admin/galeria`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": [
    {
      "id": 1,
      "titulo": "Inauguración del Centro Comunitario",
      "descripcion": "Momento histórico de la apertura del nuevo centro comunitario del barrio El Progreso.",
      "imagen": "https://wasabi.url/tenant-slug/landing/galeria/foto1.jpg",
      "categoria": "Infraestructura",
      "order": 1,
      "is_active": true,
      "created_at": "2025-11-01T10:00:00.000000Z",
      "updated_at": "2025-11-01T10:00:00.000000Z"
    }
  ]
}
```

---

### 2. Agregar Foto a Galería

**Endpoint:** `POST /api/v1/landingpage/admin/galeria`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
titulo: "Inauguración del Centro Comunitario" (requerido, string, max:255)
descripcion: "Momento histórico de la apertura..." (opcional, string)
imagen: [archivo de imagen] (requerido, jpeg|png|jpg|webp, max:5MB)
categoria: "Infraestructura" (opcional, string, max:100)
order: 1 (opcional, integer)
is_active: true (opcional, boolean)
```

**Respuesta exitosa (201):**
```json
{
  "data": {
    "id": 1,
    "titulo": "Inauguración del Centro Comunitario",
    "descripcion": "Momento histórico de la apertura del nuevo centro comunitario del barrio El Progreso.",
    "imagen": "https://wasabi.url/tenant-slug/landing/galeria/foto1.jpg",
    "categoria": "Infraestructura",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  },
  "message": "Foto agregada a la galería exitosamente"
}
```

---

### 3. Ver Foto Específica

**Endpoint:** `GET /api/v1/landingpage/admin/galeria/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "titulo": "Inauguración del Centro Comunitario",
    "descripcion": "Momento histórico de la apertura del nuevo centro comunitario del barrio El Progreso.",
    "imagen": "https://wasabi.url/tenant-slug/landing/galeria/foto1.jpg",
    "categoria": "Infraestructura",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  }
}
```

---

### 4. Actualizar Foto

**Endpoint:** `PUT /api/v1/landingpage/admin/galeria/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
titulo: "Nuevo título" (opcional, string, max:255)
descripcion: "Nueva descripción" (opcional, string)
imagen: [nueva imagen] (opcional, jpeg|png|jpg|webp, max:5MB)
categoria: "Nueva categoría" (opcional, string, max:100)
order: 2 (opcional, integer)
is_active: false (opcional, boolean)
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "titulo": "Nuevo título",
    "descripcion": "Nueva descripción",
    "imagen": "https://wasabi.url/tenant-slug/landing/galeria/foto1-updated.jpg",
    "categoria": "Nueva categoría",
    "order": 2,
    "is_active": false,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-08T16:15:00.000000Z"
  },
  "message": "Foto actualizada exitosamente"
}
```

---

### 5. Eliminar Foto

**Endpoint:** `DELETE /api/v1/landingpage/admin/galeria/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "message": "Foto eliminada de la galería exitosamente"
}
```

---

## Testimonios

Gestión de testimonios de ciudadanos en la landing page.

### 1. Listar Testimonios

**Endpoint:** `GET /api/v1/landingpage/admin/testimonios`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": [
    {
      "id": 1,
      "nombre": "María González",
      "ocupacion": "Comerciante",
      "municipio": "Bogotá",
      "testimonio": "Gracias a las políticas de apoyo a pequeños comerciantes, pude expandir mi negocio y generar más empleos en mi comunidad.",
      "foto": "https://wasabi.url/tenant-slug/landing/testimonios/maria.jpg",
      "calificacion": 5,
      "is_active": true,
      "created_at": "2025-11-01T10:00:00.000000Z",
      "updated_at": "2025-11-01T10:00:00.000000Z"
    }
  ]
}
```

---

### 2. Crear Testimonio

**Endpoint:** `POST /api/v1/landingpage/admin/testimonios`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
nombre: "María González" (requerido, string, max:255)
ocupacion: "Comerciante" (opcional, string, max:255)
municipio: "Bogotá" (opcional, string, max:255)
testimonio: "Gracias a las políticas..." (requerido, string)
foto: [archivo de imagen] (opcional, jpeg|png|jpg|webp, max:2MB)
calificacion: 5 (opcional, integer, min:1, max:5)
is_active: true (opcional, boolean)
```

**Respuesta exitosa (201):**
```json
{
  "data": {
    "id": 1,
    "nombre": "María González",
    "ocupacion": "Comerciante",
    "municipio": "Bogotá",
    "testimonio": "Gracias a las políticas de apoyo a pequeños comerciantes, pude expandir mi negocio y generar más empleos en mi comunidad.",
    "foto": "https://wasabi.url/tenant-slug/landing/testimonios/maria.jpg",
    "calificacion": 5,
    "is_active": true,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  },
  "message": "Testimonio creado exitosamente"
}
```

---

### 3. Ver Testimonio Específico

**Endpoint:** `GET /api/v1/landingpage/admin/testimonios/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "nombre": "María González",
    "ocupacion": "Comerciante",
    "municipio": "Bogotá",
    "testimonio": "Gracias a las políticas de apoyo a pequeños comerciantes, pude expandir mi negocio y generar más empleos en mi comunidad.",
    "foto": "https://wasabi.url/tenant-slug/landing/testimonios/maria.jpg",
    "calificacion": 5,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  }
}
```

---

### 4. Actualizar Testimonio

**Endpoint:** `PUT /api/v1/landingpage/admin/testimonios/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
nombre: "María González Pérez" (opcional, string, max:255)
ocupacion: "Empresaria" (opcional, string, max:255)
municipio: "Medellín" (opcional, string, max:255)
testimonio: "Nuevo testimonio actualizado" (opcional, string)
foto: [nueva foto] (opcional, jpeg|png|jpg|webp, max:2MB)
calificacion: 4 (opcional, integer, min:1, max:5)
is_active: false (opcional, boolean)
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "nombre": "María González Pérez",
    "ocupacion": "Empresaria",
    "municipio": "Medellín",
    "testimonio": "Nuevo testimonio actualizado",
    "foto": "https://wasabi.url/tenant-slug/landing/testimonios/maria-updated.jpg",
    "calificacion": 4,
    "is_active": false,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-08T16:30:00.000000Z"
  },
  "message": "Testimonio actualizado exitosamente"
}
```

---

### 5. Eliminar Testimonio

**Endpoint:** `DELETE /api/v1/landingpage/admin/testimonios/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "message": "Testimonio eliminado exitosamente"
}
```

---

## Social Feed

Gestión de publicaciones de redes sociales para la landing page.

**⚠️ IMPORTANTE:** Este módulo puede sincronizarse automáticamente con tus redes sociales reales (Twitter, Facebook, Instagram). Ver documentación completa en `SOCIAL_FEED_INTEGRATION.md`.

### 1. Listar Posts

**Endpoint:** `GET /api/v1/landingpage/admin/social-feed`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": [
    {
      "id": 1,
      "plataforma": "twitter",
      "usuario": "@candidato2025",
      "contenido": "Hoy inauguramos el nuevo parque del barrio La Esperanza. #TrabajoConResultados #ComunidadUnida",
      "fecha": "2025-11-08",
      "likes": 1250,
      "compartidos": 340,
      "comentarios": 89,
      "imagen": "https://wasabi.url/tenant-slug/landing/social/post1.jpg",
      "is_active": true,
      "created_at": "2025-11-08T10:00:00.000000Z",
      "updated_at": "2025-11-08T10:00:00.000000Z"
    }
  ]
}
```

---

### 2. Crear Post Manualmente

**Endpoint:** `POST /api/v1/landingpage/admin/social-feed`

**Nota:** Este endpoint permite crear posts manualmente. Si deseas sincronizar automáticamente desde redes sociales reales, usa los endpoints de sincronización (ver sección 6).

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
plataforma: "twitter" (requerido, enum: twitter|facebook|instagram)
usuario: "@candidato2025" (requerido, string, max:255)
contenido: "Hoy inauguramos..." (requerido, string)
fecha: "2025-11-08" (requerido, date, formato: YYYY-MM-DD)
likes: 1250 (opcional, integer, min:0)
compartidos: 340 (opcional, integer, min:0)
comentarios: 89 (opcional, integer, min:0)
imagen: [archivo de imagen] (opcional, jpeg|png|jpg|webp, max:5MB)
is_active: true (opcional, boolean)
```

**Respuesta exitosa (201):**
```json
{
  "data": {
    "id": 1,
    "plataforma": "twitter",
    "usuario": "@candidato2025",
    "contenido": "Hoy inauguramos el nuevo parque del barrio La Esperanza. #TrabajoConResultados #ComunidadUnida",
    "fecha": "2025-11-08",
    "likes": 1250,
    "compartidos": 340,
    "comentarios": 89,
    "imagen": "https://wasabi.url/tenant-slug/landing/social/post1.jpg",
    "is_active": true,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  },
  "message": "Post creado exitosamente"
}
```

**Respuesta de error (422):**
```json
{
  "errors": {
    "plataforma": ["El campo plataforma es obligatorio.", "La plataforma debe ser twitter, facebook o instagram."],
    "usuario": ["El campo usuario es obligatorio."],
    "contenido": ["El campo contenido es obligatorio."],
    "fecha": ["El campo fecha es obligatorio."]
  }
}
```

---

### 3. Ver Post Específico

**Endpoint:** `GET /api/v1/landingpage/admin/social-feed/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "plataforma": "twitter",
    "usuario": "@candidato2025",
    "contenido": "Hoy inauguramos el nuevo parque del barrio La Esperanza. #TrabajoConResultados #ComunidadUnida",
    "fecha": "2025-11-08",
    "likes": 1250,
    "compartidos": 340,
    "comentarios": 89,
    "imagen": "https://wasabi.url/tenant-slug/landing/social/post1.jpg",
    "is_active": true,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  }
}
```

---

### 4. Actualizar Post

**Endpoint:** `PUT /api/v1/landingpage/admin/social-feed/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data):**
```
plataforma: "facebook" (opcional, enum: twitter|facebook|instagram)
usuario: "@nuevoUsuario" (opcional, string, max:255)
contenido: "Contenido actualizado" (opcional, string)
fecha: "2025-11-10" (opcional, date)
likes: 1500 (opcional, integer, min:0)
compartidos: 400 (opcional, integer, min:0)
comentarios: 100 (opcional, integer, min:0)
imagen: [nueva imagen] (opcional, jpeg|png|jpg|webp, max:5MB)
is_active: false (opcional, boolean)
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "id": 1,
    "plataforma": "facebook",
    "usuario": "@nuevoUsuario",
    "contenido": "Contenido actualizado",
    "fecha": "2025-11-10",
    "likes": 1500,
    "compartidos": 400,
    "comentarios": 100,
    "imagen": "https://wasabi.url/tenant-slug/landing/social/post1-updated.jpg",
    "is_active": false,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T16:45:00.000000Z"
  },
  "message": "Post actualizado exitosamente"
}
```

---

### 5. Eliminar Post

**Endpoint:** `DELETE /api/v1/landingpage/admin/social-feed/{id}`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "message": "Post eliminado exitosamente"
}
```

---

### 6. Sincronizar desde Redes Sociales (Nuevo)

**⚡ Funcionalidad Automática**: Sincroniza posts reales desde Twitter, Facebook e Instagram.

#### 6.1. Sincronizar Todas las Redes

**Endpoint:** `POST /api/v1/landingpage/admin/social-feed/sync`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Sincronización completada",
  "results": {
    "twitter": {
      "synced": 5,
      "errors": []
    },
    "facebook": {
      "synced": 3,
      "errors": []
    },
    "instagram": {
      "synced": 7,
      "errors": []
    }
  },
  "total_synced": 15
}
```

**Notas:**
- Sincroniza los últimos posts de cada red social configurada
- No crea duplicados (verifica por `external_id`)
- Actualiza métricas (likes, shares, comments) si el post ya existe

---

#### 6.2. Sincronizar Red Específica

**Endpoint:** `POST /api/v1/landingpage/admin/social-feed/sync/{platform}`

**Plataformas:** `twitter`, `facebook`, `instagram`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Ejemplo:** `POST /api/v1/landingpage/admin/social-feed/sync/twitter`

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Posts de twitter sincronizados",
  "platform": "twitter",
  "synced": 5,
  "errors": []
}
```

---

#### 6.3. Ver Configuración de Redes

**Endpoint:** `GET /api/v1/landingpage/admin/social-feed/config`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "twitter": {
    "enabled": true,
    "configured": true
  },
  "facebook": {
    "enabled": true,
    "configured": true
  },
  "instagram": {
    "enabled": false,
    "configured": false
  },
  "auto_sync_enabled": true,
  "sync_interval_minutes": 15
}
```

**Uso en Frontend:**
```javascript
// Botón de sincronización manual
const SyncButton = () => {
  const [loading, setLoading] = useState(false);

  const handleSync = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/landingpage/admin/social-feed/sync', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      });

      const data = await response.json();
      alert(`✅ Sincronizados ${data.total_synced} posts`);
    } catch (error) {
      alert('❌ Error en la sincronización');
    } finally {
      setLoading(false);
    }
  };

  return (
    <button onClick={handleSync} disabled={loading}>
      {loading ? '⏳ Sincronizando...' : '🔄 Sincronizar Redes Sociales'}
    </button>
  );
};
```

**Ventajas de la Sincronización Automática:**

✅ **Automático**: Se sincroniza cada 15 minutos (configurable)  
✅ **Métricas Reales**: Likes, shares y comentarios actualizados  
✅ **Imágenes**: Descarga automáticamente las imágenes de los posts  
✅ **No Duplicados**: Verifica si el post ya existe antes de crear  
✅ **Multicanal**: Soporta Twitter, Facebook e Instagram  
✅ **Seguro**: Los tokens de API permanecen en el servidor  

**📖 Documentación Completa:** Ver `SOCIAL_FEED_INTEGRATION.md` para:
- Cómo obtener credenciales de API
- Configuración de cada red social
- Automatización con Laravel Scheduler
- Comandos de sincronización manual
- Troubleshooting y mejores prácticas

---

## Biografía

Gestión de la biografía del candidato (campo JSON en la tabla tenants).

### 1. Ver Biografía

**Endpoint:** `GET /api/v1/landingpage/admin/biografia`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "nombre": "Juan Carlos Pérez",
    "cargo": "Candidato a Alcalde",
    "imagen": "https://wasabi.url/tenant-slug/landing/biografia/perfil.jpg",
    "quienEs": {
      "titulo": "¿Quién es Juan Carlos?",
      "descripcion": "Líder comunitario con más de 20 años de experiencia en servicio público...",
      "destacados": [
        "Ex presidente de la junta comunal",
        "Fundador de la Asociación de Comerciantes",
        "Magíster en Administración Pública"
      ]
    },
    "historia": {
      "titulo": "Su Historia",
      "parrafos": [
        "Nació en el barrio La Esperanza, donde creció viendo las necesidades de su comunidad...",
        "Desde joven se involucró en actividades sociales y comunitarias...",
        "Su compromiso con el servicio público lo llevó a..."
      ]
    },
    "valores": [
      {
        "icono": "heart",
        "titulo": "Compromiso Social",
        "descripcion": "Trabajando siempre por el bienestar de la comunidad"
      },
      {
        "icono": "shield",
        "titulo": "Transparencia",
        "descripcion": "Rendición de cuentas clara y honesta"
      }
    ]
  }
}
```

---

### 2. Actualizar Biografía

**Endpoint:** `PUT /api/v1/landingpage/admin/biografia`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "multipart/form-data"
}
```

**Body (Form Data o JSON):**

**Opción 1 - JSON (sin cambiar imagen):**
```json
{
  "nombre": "Juan Carlos Pérez Gómez",
  "cargo": "Candidato a Alcalde de Bogotá",
  "quienEs": {
    "titulo": "¿Quién es Juan Carlos?",
    "descripcion": "Líder comunitario con más de 20 años de experiencia...",
    "destacados": [
      "Ex presidente de la junta comunal",
      "Fundador de la Asociación de Comerciantes",
      "Magíster en Administración Pública"
    ]
  },
  "historia": {
    "titulo": "Su Historia",
    "parrafos": [
      "Primer párrafo de su historia...",
      "Segundo párrafo...",
      "Tercer párrafo..."
    ]
  },
  "valores": [
    {
      "icono": "heart",
      "titulo": "Compromiso Social",
      "descripcion": "Trabajando siempre por el bienestar de la comunidad"
    },
    {
      "icono": "shield",
      "titulo": "Transparencia",
      "descripcion": "Rendición de cuentas clara y honesta"
    }
  ]
}
```

**Opción 2 - Form Data (con imagen):**
```
nombre: "Juan Carlos Pérez Gómez" (opcional, string, max:255)
cargo: "Candidato a Alcalde de Bogotá" (opcional, string, max:255)
imagen: [archivo de imagen] (opcional, jpeg|png|jpg|webp, max:3MB)
quienEs[titulo]: "¿Quién es Juan Carlos?" (opcional, string)
quienEs[descripcion]: "Líder comunitario..." (opcional, string)
quienEs[destacados][0]: "Primer destacado" (opcional, array de strings)
quienEs[destacados][1]: "Segundo destacado"
historia[titulo]: "Su Historia" (opcional, string)
historia[parrafos][0]: "Primer párrafo" (opcional, array de strings)
historia[parrafos][1]: "Segundo párrafo"
valores[0][icono]: "heart" (opcional, array de objetos)
valores[0][titulo]: "Compromiso Social"
valores[0][descripcion]: "Trabajando siempre..."
valores[1][icono]: "shield"
valores[1][titulo]: "Transparencia"
valores[1][descripcion]: "Rendición de cuentas..."
```

**Respuesta exitosa (200):**
```json
{
  "data": {
    "nombre": "Juan Carlos Pérez Gómez",
    "cargo": "Candidato a Alcalde de Bogotá",
    "imagen": "https://wasabi.url/tenant-slug/landing/biografia/perfil-updated.jpg",
    "quienEs": {
      "titulo": "¿Quién es Juan Carlos?",
      "descripcion": "Líder comunitario con más de 20 años de experiencia en servicio público...",
      "destacados": [
        "Ex presidente de la junta comunal",
        "Fundador de la Asociación de Comerciantes",
        "Magíster en Administración Pública"
      ]
    },
    "historia": {
      "titulo": "Su Historia",
      "parrafos": [
        "Primer párrafo de su historia...",
        "Segundo párrafo...",
        "Tercer párrafo..."
      ]
    },
    "valores": [
      {
        "icono": "heart",
        "titulo": "Compromiso Social",
        "descripcion": "Trabajando siempre por el bienestar de la comunidad"
      },
      {
        "icono": "shield",
        "titulo": "Transparencia",
        "descripcion": "Rendición de cuentas clara y honesta"
      }
    ]
  },
  "message": "Biografía actualizada exitosamente"
}
```

**Respuesta de error (422):**
```json
{
  "errors": {
    "nombre": ["El campo nombre es obligatorio."],
    "cargo": ["El campo cargo es obligatorio."],
    "quienEs.titulo": ["El campo título en quién es es obligatorio."],
    "imagen": ["La imagen debe ser un archivo de tipo: jpeg, png, jpg, webp."]
  }
}
```

---

### 3. Eliminar Imagen de Biografía

**Endpoint:** `DELETE /api/v1/landingpage/admin/biografia/imagen`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "message": "Imagen de biografía eliminada exitosamente"
}
```

**Nota:** Este endpoint elimina únicamente la imagen de la biografía, manteniendo el resto de la información intacta.

---

## Notas Importantes

### Autenticación
- Todos los endpoints requieren un token JWT válido
- El token debe incluirse en el header `Authorization: Bearer {token}`
- Los usuarios deben pertenecer al tenant para gestionar su contenido

### Manejo de Imágenes
- Las imágenes se almacenan en Wasabi S3
- Al actualizar una imagen, la anterior se elimina automáticamente
- Al eliminar un registro, las imágenes asociadas se eliminan del storage
- Límites de tamaño: 2-5MB según el tipo de imagen

### Ordenamiento
- Los registros con campo `order` se ordenan ascendentemente
- Los eventos se ordenan por fecha descendente
- Los testimonios y social feed se ordenan por fecha de creación descendente

### Estados Activos/Inactivos
- El campo `is_active` permite ocultar contenido sin eliminarlo
- Solo los elementos activos (`is_active = true`) se muestran en la landing pública
- Los elementos inactivos siguen disponibles en el admin para reactivarlos

### Validaciones
- Todos los campos requeridos se validan antes de crear/actualizar
- Los formatos de imagen permitidos: jpeg, png, jpg, webp
- Las fechas deben estar en formato YYYY-MM-DD
- Los íconos de propuestas tienen valores predefinidos

---

**Fin de la Documentación de Administración de Landing Page**
