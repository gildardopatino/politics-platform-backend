# API Pública de Landing Page

Documentación completa de los endpoints públicos de la Landing Page del sistema de campañas políticas.

**Fecha:** 8 de Noviembre, 2025  
**Versión:** 1.0

---

## Índice

1. [Introducción](#introducción)
2. [Identificación de Tenant](#identificación-de-tenant)
3. [Endpoints de Consulta](#endpoints-de-consulta)
4. [Endpoints de Registro](#endpoints-de-registro)

---

## Introducción

Los endpoints públicos de la landing page **NO requieren autenticación** y están diseñados para ser consumidos por el sitio web público del candidato.

### Características
- ✅ No requieren autenticación
- ✅ Acceso libre para visitantes
- ✅ Identificación por slug del tenant
- ✅ Solo retornan contenido activo (`is_active = true`)

---

## Identificación de Tenant

Todos los endpoints públicos requieren identificar el tenant (candidato) mediante una de estas dos formas:

### Opción 1: Header HTTP (Recomendado)
```
X-Tenant-Slug: nombre-del-candidato
```

### Opción 2: Query Parameter
```
?tenant=nombre-del-candidato
```

**Ejemplo de uso:**
```bash
# Con header
curl -H "X-Tenant-Slug: juan-perez-2025" https://api.example.com/api/v1/landingpage/banners

# Con query parameter
curl https://api.example.com/api/v1/landingpage/banners?tenant=juan-perez-2025
```

**Respuesta si falta el tenant (400):**
```json
{
  "error": "Tenant slug is required"
}
```

**Respuesta si el tenant no existe (404):**
```json
{
  "error": "Tenant not found"
}
```

---

## Endpoints de Consulta

### 1. Obtener Banners

Retorna los banners activos del candidato para el carrusel principal.

**Endpoint:** `GET /api/v1/landingpage/banners`

**Headers:**
```
X-Tenant-Slug: juan-perez-2025
```

**Respuesta exitosa (200):**
```json
[
  {
    "id": 1,
    "title": "Juntos por un Mejor Futuro",
    "subtitle": "Transformando nuestra comunidad",
    "description": "Trabajando día a día por el progreso de nuestro municipio con transparencia y compromiso.",
    "image": "https://wasabi.url/juan-perez-2025/landing/banners/banner1.jpg",
    "cta_text": "Conoce nuestras propuestas",
    "cta_link": "/propuestas",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  },
  {
    "id": 2,
    "title": "Educación para Todos",
    "subtitle": "Invirtiendo en nuestro futuro",
    "description": "Modernización de escuelas y acceso universal a la educación de calidad.",
    "image": "https://wasabi.url/juan-perez-2025/landing/banners/banner2.jpg",
    "cta_text": "Ver más",
    "cta_link": "/educacion",
    "order": 2,
    "is_active": true,
    "created_at": "2025-11-02T10:00:00.000000Z",
    "updated_at": "2025-11-02T10:00:00.000000Z"
  }
]
```

**Uso en Frontend:**
```javascript
// React/Next.js ejemplo
const fetchBanners = async () => {
  const response = await fetch('https://api.example.com/api/v1/landingpage/banners', {
    headers: {
      'X-Tenant-Slug': 'juan-perez-2025'
    }
  });
  const banners = await response.json();
  return banners;
};
```

---

### 2. Obtener Biografía

Retorna la biografía completa del candidato.

**Endpoint:** `GET /api/v1/landingpage/biografia`

**Headers:**
```
X-Tenant-Slug: juan-perez-2025
```

**Respuesta exitosa (200):**
```json
{
  "nombre": "Juan Carlos Pérez Gómez",
  "cargo": "Candidato a Alcalde de Bogotá",
  "imagen": "https://wasabi.url/juan-perez-2025/landing/biografia/perfil.jpg",
  "quienEs": {
    "titulo": "¿Quién es Juan Carlos?",
    "descripcion": "Líder comunitario con más de 20 años de experiencia en servicio público, comprometido con el desarrollo social y económico de nuestra región.",
    "destacados": [
      "Ex presidente de la junta comunal del barrio La Esperanza",
      "Fundador de la Asociación de Comerciantes del Centro",
      "Magíster en Administración Pública - Universidad Nacional",
      "Especialista en Desarrollo Local y Regional"
    ]
  },
  "historia": {
    "titulo": "Su Historia",
    "parrafos": [
      "Nació en el barrio La Esperanza, donde creció viendo las necesidades de su comunidad y desarrolló su compromiso con el servicio público.",
      "Desde joven se involucró en actividades sociales y comunitarias, liderando proyectos de mejoramiento barrial y apoyo a familias vulnerables.",
      "Su trayectoria profesional incluye cargos en entidades públicas donde implementó programas innovadores de desarrollo social.",
      "Hoy, con la experiencia acumulada y el respaldo de su comunidad, se postula para llevar su visión de progreso a toda la ciudad."
    ]
  },
  "valores": [
    {
      "icono": "heart",
      "titulo": "Compromiso Social",
      "descripcion": "Trabajando siempre por el bienestar y progreso de toda la comunidad"
    },
    {
      "icono": "shield",
      "titulo": "Transparencia",
      "descripcion": "Rendición de cuentas clara, honesta y accesible para todos"
    },
    {
      "icono": "users",
      "titulo": "Participación Ciudadana",
      "descripcion": "Decisiones construidas junto con la comunidad"
    }
  ]
}
```

**Uso en Frontend:**
```javascript
// React/Next.js ejemplo
const fetchBiografia = async () => {
  const response = await fetch('https://api.example.com/api/v1/landingpage/biografia', {
    headers: {
      'X-Tenant-Slug': 'juan-perez-2025'
    }
  });
  const biografia = await response.json();
  return biografia;
};
```

---

### 3. Obtener Propuestas

Retorna las propuestas políticas activas del candidato.

**Endpoint:** `GET /api/v1/landingpage/propuestas`

**Headers:**
```
X-Tenant-Slug: juan-perez-2025
```

**Respuesta exitosa (200):**
```json
[
  {
    "id": 1,
    "categoria": "Seguridad",
    "titulo": "Seguridad para Todos",
    "descripcion": "Implementaremos un sistema integral de seguridad ciudadana que incluya modernización de la policía, instalación de cámaras de vigilancia en puntos estratégicos y programas de prevención del delito.",
    "puntos_clave": [
      "Modernización de equipos y vehículos policiales",
      "Instalación de 500 cámaras de seguridad inteligentes",
      "Programas de prevención del delito en colegios",
      "Iluminación LED en 100% de las zonas críticas"
    ],
    "icono": "shield",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  },
  {
    "id": 2,
    "categoria": "Educación",
    "titulo": "Educación de Calidad",
    "descripcion": "Mejoraremos la infraestructura educativa y garantizaremos acceso universal a una educación de calidad para todos los niños y jóvenes.",
    "puntos_clave": [
      "Construcción de 10 nuevas instituciones educativas",
      "Dotación de tablets para todos los estudiantes de secundaria",
      "Capacitación continua para 2,000 docentes",
      "Internet gratuito de alta velocidad en todas las escuelas"
    ],
    "icono": "book-open",
    "order": 2,
    "is_active": true,
    "created_at": "2025-11-01T11:00:00.000000Z",
    "updated_at": "2025-11-01T11:00:00.000000Z"
  },
  {
    "id": 3,
    "categoria": "Salud",
    "titulo": "Salud Accesible",
    "descripcion": "Fortaleceremos el sistema de salud local con nuevos centros de atención y programas preventivos.",
    "puntos_clave": [
      "Construcción de 5 centros de salud en zonas rurales",
      "Brigadas móviles de salud permanentes",
      "Programa de medicina preventiva gratuita",
      "Telemedicina en comunidades alejadas"
    ],
    "icono": "heart",
    "order": 3,
    "is_active": true,
    "created_at": "2025-11-01T12:00:00.000000Z",
    "updated_at": "2025-11-01T12:00:00.000000Z"
  }
]
```

**Iconos disponibles:**
- `shield` - Seguridad
- `book-open` - Educación
- `heart` - Salud
- `leaf` - Medio Ambiente
- `briefcase` - Empleo
- `construction` - Infraestructura

---

### 4. Obtener Eventos

Retorna los eventos activos del candidato, ordenados por fecha descendente.

**Endpoint:** `GET /api/v1/landingpage/eventos`

**Headers:**
```
X-Tenant-Slug: juan-perez-2025
```

**Respuesta exitosa (200):**
```json
[
  {
    "id": 1,
    "titulo": "Gran Caminata por la Educación",
    "fecha": "2025-11-15",
    "hora": "09:00 AM",
    "lugar": "Plaza Central, Bogotá",
    "descripcion": "Únete a nuestra caminata para promover la educación de calidad en todos los sectores. Habrá actividades culturales, música en vivo y stands informativos.",
    "imagen": "https://wasabi.url/juan-perez-2025/landing/eventos/caminata-educacion.jpg",
    "tipo": "Caminata",
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  },
  {
    "id": 2,
    "titulo": "Foro: Seguridad y Convivencia",
    "fecha": "2025-11-12",
    "hora": "06:00 PM",
    "lugar": "Auditorio Municipal, Calle 45 #23-15",
    "descripcion": "Espacio de diálogo abierto con la comunidad sobre políticas de seguridad. Participan expertos y líderes comunitarios.",
    "imagen": "https://wasabi.url/juan-perez-2025/landing/eventos/foro-seguridad.jpg",
    "tipo": "Foro",
    "is_active": true,
    "created_at": "2025-11-01T11:00:00.000000Z",
    "updated_at": "2025-11-01T11:00:00.000000Z"
  },
  {
    "id": 3,
    "titulo": "Inauguración Centro Comunitario",
    "fecha": "2025-11-10",
    "hora": "10:00 AM",
    "lugar": "Barrio La Esperanza, Carrera 12 #8-30",
    "descripcion": "Celebramos juntos la apertura del nuevo centro comunitario que beneficiará a más de 5,000 familias.",
    "imagen": "https://wasabi.url/juan-perez-2025/landing/eventos/inauguracion-centro.jpg",
    "tipo": "Inauguración",
    "is_active": true,
    "created_at": "2025-11-01T12:00:00.000000Z",
    "updated_at": "2025-11-01T12:00:00.000000Z"
  }
]
```

---

### 5. Obtener Galería

Retorna las fotos activas de la galería del candidato.

**Endpoint:** `GET /api/v1/landingpage/galeria`

**Headers:**
```
X-Tenant-Slug: juan-perez-2025
```

**Respuesta exitosa (200):**
```json
[
  {
    "id": 1,
    "titulo": "Inauguración del Centro Comunitario",
    "descripcion": "Momento histórico de la apertura del nuevo centro comunitario del barrio El Progreso, que beneficiará a miles de familias.",
    "imagen": "https://wasabi.url/juan-perez-2025/landing/galeria/centro-comunitario.jpg",
    "categoria": "Infraestructura",
    "order": 1,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  },
  {
    "id": 2,
    "titulo": "Jornada de Salud en La Esperanza",
    "descripcion": "Brigada médica gratuita que atendió a más de 300 personas en el barrio La Esperanza.",
    "imagen": "https://wasabi.url/juan-perez-2025/landing/galeria/brigada-salud.jpg",
    "categoria": "Salud",
    "order": 2,
    "is_active": true,
    "created_at": "2025-11-02T10:00:00.000000Z",
    "updated_at": "2025-11-02T10:00:00.000000Z"
  },
  {
    "id": 3,
    "titulo": "Caminata con la Comunidad",
    "descripcion": "Miles de ciudadanos se unieron en la caminata por un mejor futuro para nuestra ciudad.",
    "imagen": "https://wasabi.url/juan-perez-2025/landing/galeria/caminata.jpg",
    "categoria": "Eventos",
    "order": 3,
    "is_active": true,
    "created_at": "2025-11-03T10:00:00.000000Z",
    "updated_at": "2025-11-03T10:00:00.000000Z"
  }
]
```

**Uso en Frontend:**
```javascript
// React/Next.js ejemplo - Grid de galería
const GaleriaGrid = () => {
  const [fotos, setFotos] = useState([]);

  useEffect(() => {
    fetch('https://api.example.com/api/v1/landingpage/galeria', {
      headers: { 'X-Tenant-Slug': 'juan-perez-2025' }
    })
    .then(res => res.json())
    .then(data => setFotos(data));
  }, []);

  return (
    <div className="grid grid-cols-3 gap-4">
      {fotos.map(foto => (
        <div key={foto.id}>
          <img src={foto.imagen} alt={foto.titulo} />
          <h3>{foto.titulo}</h3>
          <p>{foto.descripcion}</p>
        </div>
      ))}
    </div>
  );
};
```

---

### 6. Obtener Testimonios

Retorna los testimonios activos de ciudadanos que apoyan al candidato.

**Endpoint:** `GET /api/v1/landingpage/testimonios`

**Headers:**
```
X-Tenant-Slug: juan-perez-2025
```

**Respuesta exitosa (200):**
```json
[
  {
    "id": 1,
    "nombre": "María González",
    "ocupacion": "Comerciante",
    "municipio": "Bogotá",
    "testimonio": "Gracias a las políticas de apoyo a pequeños comerciantes, pude expandir mi negocio y generar más empleos en mi comunidad. Juan Carlos realmente entiende nuestras necesidades.",
    "foto": "https://wasabi.url/juan-perez-2025/landing/testimonios/maria.jpg",
    "calificacion": 5,
    "is_active": true,
    "created_at": "2025-11-01T10:00:00.000000Z",
    "updated_at": "2025-11-01T10:00:00.000000Z"
  },
  {
    "id": 2,
    "nombre": "Carlos Rodríguez",
    "ocupacion": "Agricultor",
    "municipio": "Zipaquirá",
    "testimonio": "Los programas de capacitación y apoyo técnico nos han permitido mejorar nuestra producción y acceder a nuevos mercados. Por fin tenemos alguien que piensa en el campo.",
    "foto": "https://wasabi.url/juan-perez-2025/landing/testimonios/carlos.jpg",
    "calificacion": 5,
    "is_active": true,
    "created_at": "2025-11-02T10:00:00.000000Z",
    "updated_at": "2025-11-02T10:00:00.000000Z"
  },
  {
    "id": 3,
    "nombre": "Ana Martínez",
    "ocupacion": "Profesora",
    "municipio": "Chía",
    "testimonio": "Como educadora, valoro enormemente su compromiso con la educación de calidad. Las nuevas escuelas y la dotación de tecnología están transformando vidas.",
    "foto": "https://wasabi.url/juan-perez-2025/landing/testimonios/ana.jpg",
    "calificacion": 5,
    "is_active": true,
    "created_at": "2025-11-03T10:00:00.000000Z",
    "updated_at": "2025-11-03T10:00:00.000000Z"
  }
]
```

---

### 7. Obtener Social Feed

Retorna las publicaciones activas de redes sociales del candidato.

**Endpoint:** `GET /api/v1/landingpage/social-feed`

**Headers:**
```
X-Tenant-Slug: juan-perez-2025
```

**Respuesta exitosa (200):**
```json
[
  {
    "id": 1,
    "plataforma": "twitter",
    "usuario": "@juancarlos2025",
    "contenido": "Hoy inauguramos el nuevo parque del barrio La Esperanza. Un espacio seguro y moderno para nuestros niños. #TrabajoConResultados #ComunidadUnida 🏞️👨‍👩‍👧‍👦",
    "fecha": "2025-11-08",
    "likes": 1250,
    "compartidos": 340,
    "comentarios": 89,
    "imagen": "https://wasabi.url/juan-perez-2025/landing/social/parque.jpg",
    "is_active": true,
    "created_at": "2025-11-08T10:00:00.000000Z",
    "updated_at": "2025-11-08T10:00:00.000000Z"
  },
  {
    "id": 2,
    "plataforma": "facebook",
    "usuario": "Juan Carlos Pérez",
    "contenido": "Agradecido con las 500 familias que participaron en la jornada de salud gratuita. Seguimos trabajando por una salud accesible para todos. 💙🏥",
    "fecha": "2025-11-07",
    "likes": 2100,
    "compartidos": 567,
    "comentarios": 143,
    "imagen": "https://wasabi.url/juan-perez-2025/landing/social/salud.jpg",
    "is_active": true,
    "created_at": "2025-11-07T15:00:00.000000Z",
    "updated_at": "2025-11-07T15:00:00.000000Z"
  },
  {
    "id": 3,
    "plataforma": "instagram",
    "usuario": "@juancarlosperez",
    "contenido": "Recorriendo las calles de nuestro municipio, escuchando las necesidades de la gente. Juntos construimos un mejor futuro. 🚶‍♂️💪 #CercaníaYCompromiso",
    "fecha": "2025-11-06",
    "likes": 3400,
    "compartidos": 890,
    "comentarios": 234,
    "imagen": "https://wasabi.url/juan-perez-2025/landing/social/recorrido.jpg",
    "is_active": true,
    "created_at": "2025-11-06T18:00:00.000000Z",
    "updated_at": "2025-11-06T18:00:00.000000Z"
  }
]
```

**Uso en Frontend:**
```javascript
// React/Next.js ejemplo - Social Feed
const SocialFeed = () => {
  const [posts, setPosts] = useState([]);

  useEffect(() => {
    fetch('https://api.example.com/api/v1/landingpage/social-feed', {
      headers: { 'X-Tenant-Slug': 'juan-perez-2025' }
    })
    .then(res => res.json())
    .then(data => setPosts(data));
  }, []);

  const getPlatformIcon = (plataforma) => {
    const icons = {
      twitter: '🐦',
      facebook: '👥',
      instagram: '📷'
    };
    return icons[plataforma];
  };

  return (
    <div className="social-feed">
      {posts.map(post => (
        <div key={post.id} className="post-card">
          <div className="post-header">
            <span>{getPlatformIcon(post.plataforma)}</span>
            <strong>{post.usuario}</strong>
          </div>
          <p>{post.contenido}</p>
          {post.imagen && <img src={post.imagen} alt="Post" />}
          <div className="post-stats">
            <span>❤️ {post.likes}</span>
            <span>🔄 {post.compartidos}</span>
            <span>💬 {post.comentarios}</span>
          </div>
        </div>
      ))}
    </div>
  );
};
```

---

## Endpoints de Registro

### 8. Registrar Voluntario

Permite que un visitante se registre como voluntario de la campaña.

**Endpoint:** `POST /api/v1/landingpage/voluntarios`

**Headers:**
```json
{
  "X-Tenant-Slug": "juan-perez-2025",
  "Content-Type": "application/json"
}
```

**Body (JSON):**
```json
{
  "nombre": "Pedro Sánchez",
  "email": "pedro.sanchez@example.com",
  "telefono": "+57 300 123 4567",
  "ciudad": "Bogotá"
}
```

**Campos requeridos:**
- `nombre` (string, max:255)
- `email` (email válido, max:255)
- `telefono` (string, max:50)
- `ciudad` (string, max:255)

**Respuesta exitosa (201):**
```json
{
  "success": true,
  "message": "Voluntario registrado exitosamente",
  "id": 1
}
```

**Respuesta de error (422):**
```json
{
  "errors": {
    "nombre": ["El campo nombre es obligatorio."],
    "email": ["El campo email es obligatorio.", "El email debe ser una dirección válida."],
    "telefono": ["El campo teléfono es obligatorio."],
    "ciudad": ["El campo ciudad es obligatorio."]
  }
}
```

**Uso en Frontend:**
```javascript
// React/Next.js ejemplo - Formulario de Voluntarios
const FormularioVoluntario = () => {
  const [formData, setFormData] = useState({
    nombre: '',
    email: '',
    telefono: '',
    ciudad: ''
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    try {
      const response = await fetch('https://api.example.com/api/v1/landingpage/voluntarios', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Tenant-Slug': 'juan-perez-2025'
        },
        body: JSON.stringify(formData)
      });

      const data = await response.json();
      
      if (response.ok) {
        alert('¡Gracias por unirte como voluntario!');
        setFormData({ nombre: '', email: '', telefono: '', ciudad: '' });
      } else {
        console.error('Errores:', data.errors);
      }
    } catch (error) {
      console.error('Error:', error);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="text"
        placeholder="Nombre completo"
        value={formData.nombre}
        onChange={(e) => setFormData({...formData, nombre: e.target.value})}
        required
      />
      <input
        type="email"
        placeholder="Email"
        value={formData.email}
        onChange={(e) => setFormData({...formData, email: e.target.value})}
        required
      />
      <input
        type="tel"
        placeholder="Teléfono"
        value={formData.telefono}
        onChange={(e) => setFormData({...formData, telefono: e.target.value})}
        required
      />
      <input
        type="text"
        placeholder="Ciudad"
        value={formData.ciudad}
        onChange={(e) => setFormData({...formData, ciudad: e.target.value})}
        required
      />
      <button type="submit">Unirme como voluntario</button>
    </form>
  );
};
```

---

### 9. Enviar Mensaje de Contacto

Permite que un visitante envíe un mensaje al candidato a través del formulario de contacto.

**Endpoint:** `POST /api/v1/landingpage/contacto`

**Headers:**
```json
{
  "X-Tenant-Slug": "juan-perez-2025",
  "Content-Type": "application/json"
}
```

**Body (JSON):**
```json
{
  "nombre": "Laura Jiménez",
  "email": "laura.jimenez@example.com",
  "telefono": "+57 300 987 6543",
  "mensaje": "Me gustaría saber más sobre las propuestas de educación para mi vereda. Necesitamos mejorar la infraestructura de nuestra escuela rural."
}
```

**Campos:**
- `nombre` (requerido, string, max:255)
- `email` (requerido, email válido, max:255)
- `telefono` (opcional, string, max:50)
- `mensaje` (requerido, string)

**Respuesta exitosa (201):**
```json
{
  "success": true,
  "message": "Mensaje enviado exitosamente"
}
```

**Respuesta de error (422):**
```json
{
  "errors": {
    "nombre": ["El campo nombre es obligatorio."],
    "email": ["El campo email es obligatorio."],
    "mensaje": ["El campo mensaje es obligatorio."]
  }
}
```

**Uso en Frontend:**
```javascript
// React/Next.js ejemplo - Formulario de Contacto
const FormularioContacto = () => {
  const [formData, setFormData] = useState({
    nombre: '',
    email: '',
    telefono: '',
    mensaje: ''
  });
  const [enviando, setEnviando] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setEnviando(true);
    
    try {
      const response = await fetch('https://api.example.com/api/v1/landingpage/contacto', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Tenant-Slug': 'juan-perez-2025'
        },
        body: JSON.stringify(formData)
      });

      const data = await response.json();
      
      if (response.ok) {
        alert('¡Mensaje enviado! Te contactaremos pronto.');
        setFormData({ nombre: '', email: '', telefono: '', mensaje: '' });
      } else {
        alert('Error al enviar el mensaje. Por favor intenta de nuevo.');
        console.error('Errores:', data.errors);
      }
    } catch (error) {
      console.error('Error:', error);
      alert('Error de conexión. Por favor intenta de nuevo.');
    } finally {
      setEnviando(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="text"
        placeholder="Tu nombre"
        value={formData.nombre}
        onChange={(e) => setFormData({...formData, nombre: e.target.value})}
        required
      />
      <input
        type="email"
        placeholder="Tu email"
        value={formData.email}
        onChange={(e) => setFormData({...formData, email: e.target.value})}
        required
      />
      <input
        type="tel"
        placeholder="Teléfono (opcional)"
        value={formData.telefono}
        onChange={(e) => setFormData({...formData, telefono: e.target.value})}
      />
      <textarea
        placeholder="Tu mensaje"
        value={formData.mensaje}
        onChange={(e) => setFormData({...formData, mensaje: e.target.value})}
        rows="5"
        required
      />
      <button type="submit" disabled={enviando}>
        {enviando ? 'Enviando...' : 'Enviar mensaje'}
      </button>
    </form>
  );
};
```

---

## Manejo de Errores

### Errores Comunes

#### 400 - Bad Request
```json
{
  "error": "Tenant slug is required"
}
```
**Causa:** No se proporcionó el header `X-Tenant-Slug` ni el parámetro `tenant`.

#### 404 - Not Found
```json
{
  "error": "Tenant not found"
}
```
**Causa:** El slug del tenant proporcionado no existe en la base de datos.

#### 422 - Unprocessable Entity
```json
{
  "errors": {
    "campo1": ["Error de validación 1"],
    "campo2": ["Error de validación 2"]
  }
}
```
**Causa:** Los datos enviados no cumplen con las validaciones requeridas.

---

## Ejemplos Completos de Integración

### Ejemplo 1: Landing Page Completa en Next.js

```javascript
// pages/[tenant].js
import { useState, useEffect } from 'react';

export default function LandingPage({ tenant }) {
  const [banners, setBanners] = useState([]);
  const [biografia, setBiografia] = useState(null);
  const [propuestas, setPropuestas] = useState([]);
  const [eventos, setEventos] = useState([]);
  const [galeria, setGaleria] = useState([]);
  const [testimonios, setTestimonios] = useState([]);
  const [socialFeed, setSocialFeed] = useState([]);

  const API_BASE = 'https://api.example.com/api/v1/landingpage';

  useEffect(() => {
    const fetchData = async () => {
      const headers = { 'X-Tenant-Slug': tenant };

      // Cargar todos los datos en paralelo
      const [
        bannersRes,
        biografiaRes,
        propuestasRes,
        eventosRes,
        galeriaRes,
        testimoniosRes,
        socialRes
      ] = await Promise.all([
        fetch(`${API_BASE}/banners`, { headers }),
        fetch(`${API_BASE}/biografia`, { headers }),
        fetch(`${API_BASE}/propuestas`, { headers }),
        fetch(`${API_BASE}/eventos`, { headers }),
        fetch(`${API_BASE}/galeria`, { headers }),
        fetch(`${API_BASE}/testimonios`, { headers }),
        fetch(`${API_BASE}/social-feed`, { headers })
      ]);

      setBanners(await bannersRes.json());
      setBiografia(await biografiaRes.json());
      setPropuestas(await propuestasRes.json());
      setEventos(await eventosRes.json());
      setGaleria(await galeriaRes.json());
      setTestimonios(await testimoniosRes.json());
      setSocialFeed(await socialRes.json());
    };

    fetchData();
  }, [tenant]);

  return (
    <div>
      {/* Hero Section con Banners */}
      <section className="hero">
        {banners.map(banner => (
          <div key={banner.id}>
            <img src={banner.image} alt={banner.title} />
            <h1>{banner.title}</h1>
            <h2>{banner.subtitle}</h2>
            <p>{banner.description}</p>
            {banner.cta_link && (
              <a href={banner.cta_link}>{banner.cta_text}</a>
            )}
          </div>
        ))}
      </section>

      {/* Biografía */}
      {biografia && (
        <section className="biografia">
          <img src={biografia.imagen} alt={biografia.nombre} />
          <h2>{biografia.nombre}</h2>
          <h3>{biografia.cargo}</h3>
          <div>
            <h4>{biografia.quienEs.titulo}</h4>
            <p>{biografia.quienEs.descripcion}</p>
            <ul>
              {biografia.quienEs.destacados.map((item, idx) => (
                <li key={idx}>{item}</li>
              ))}
            </ul>
          </div>
        </section>
      )}

      {/* Propuestas */}
      <section className="propuestas">
        <h2>Nuestras Propuestas</h2>
        <div className="grid">
          {propuestas.map(propuesta => (
            <div key={propuesta.id} className="propuesta-card">
              <h3>{propuesta.titulo}</h3>
              <p>{propuesta.descripcion}</p>
              <ul>
                {propuesta.puntos_clave.map((punto, idx) => (
                  <li key={idx}>{punto}</li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </section>

      {/* Eventos */}
      <section className="eventos">
        <h2>Próximos Eventos</h2>
        {eventos.map(evento => (
          <div key={evento.id} className="evento-card">
            {evento.imagen && <img src={evento.imagen} alt={evento.titulo} />}
            <h3>{evento.titulo}</h3>
            <p>📅 {evento.fecha} - ⏰ {evento.hora}</p>
            <p>📍 {evento.lugar}</p>
            <p>{evento.descripcion}</p>
          </div>
        ))}
      </section>

      {/* Galería */}
      <section className="galeria">
        <h2>Galería</h2>
        <div className="grid">
          {galeria.map(foto => (
            <div key={foto.id}>
              <img src={foto.imagen} alt={foto.titulo} />
              <h4>{foto.titulo}</h4>
            </div>
          ))}
        </div>
      </section>

      {/* Testimonios */}
      <section className="testimonios">
        <h2>Lo Que Dicen Nuestros Ciudadanos</h2>
        {testimonios.map(testimonio => (
          <div key={testimonio.id} className="testimonio-card">
            {testimonio.foto && <img src={testimonio.foto} alt={testimonio.nombre} />}
            <p>"{testimonio.testimonio}"</p>
            <strong>{testimonio.nombre}</strong>
            <span>{testimonio.ocupacion} - {testimonio.municipio}</span>
          </div>
        ))}
      </section>

      {/* Social Feed */}
      <section className="social-feed">
        <h2>Síguenos en Redes</h2>
        {socialFeed.map(post => (
          <div key={post.id} className="post">
            <strong>{post.usuario}</strong>
            <p>{post.contenido}</p>
            {post.imagen && <img src={post.imagen} alt="Post" />}
            <div>
              ❤️ {post.likes} | 🔄 {post.compartidos} | 💬 {post.comentarios}
            </div>
          </div>
        ))}
      </section>
    </div>
  );
}

export async function getServerSideProps({ params }) {
  return {
    props: {
      tenant: params.tenant
    }
  };
}
```

---

## Notas Importantes

### Performance
- Considera usar caché en el lado del cliente para reducir llamadas
- Los endpoints públicos son rápidos y optimizados
- Implementa lazy loading para imágenes grandes

### SEO
- Las URLs de imágenes son permanentes y optimizadas para SEO
- Utiliza los textos alternativos de las imágenes
- Implementa meta tags con la información del candidato

### Seguridad
- No se requiere autenticación para estos endpoints
- Solo se retornan datos con `is_active = true`
- Los datos sensibles nunca se exponen públicamente

### Multi-tenant
- Cada candidato tiene su propio slug único
- Los datos están completamente aislados por tenant
- Un mismo frontend puede servir múltiples candidatos

---

**Fin de la Documentación Pública de Landing Page**
