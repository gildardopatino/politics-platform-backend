# Resumen: Integración de Social Feed con Redes Sociales

**Fecha:** 8 de Noviembre, 2025

---

## 📊 Respuesta Rápida

**¿Todo en backend o frontend?**  
✅ **BACKEND** (principalmente) + algo de frontend

---

## 🎯 Cómo Funciona

```
Redes Sociales (Twitter/Facebook/Instagram)
           ↓
    [Backend Laravel]
    - Consume APIs cada 15 min
    - Guarda posts en DB
    - Cachea respuestas
           ↓
      [Tu API REST]
           ↓
    [Frontend React/Next.js]
    - Muestra posts desde tu API
    - Botón "Sincronizar ahora"
```

---

## 🔧 Qué Necesitas Implementar

### BACKEND (Laravel)

#### 1. **Configuración** (`config/social.php` + `.env`)
```env
TWITTER_BEARER_TOKEN=xxx
FACEBOOK_ACCESS_TOKEN=xxx
INSTAGRAM_ACCESS_TOKEN=xxx
```

#### 2. **Servicio de Sincronización** (`SocialMediaSyncService.php`)
- Conecta con APIs de Twitter/Facebook/Instagram
- Descarga últimos 10 posts
- Guarda en tu tabla `landing_social_feed`
- Actualiza métricas (likes, shares, comentarios)

#### 3. **Endpoints Nuevos**
```php
POST /api/v1/landingpage/admin/social-feed/sync
POST /api/v1/landingpage/admin/social-feed/sync/twitter
GET  /api/v1/landingpage/admin/social-feed/config
```

#### 4. **Job Automático**
```php
// Sincroniza cada 15 minutos
Schedule::command('social:sync --all')->everyMinutes(15);
```

#### 5. **Migración** (agregar campos)
```php
$table->string('external_id')->nullable();
$table->string('external_url')->nullable();
$table->timestamp('last_synced_at')->nullable();
```

---

### FRONTEND (React/Next.js)

#### Solo necesitas:

**1. Botón de Sincronización Manual** (opcional)
```javascript
const sincronizar = async () => {
  await fetch('/api/v1/landingpage/admin/social-feed/sync', {
    method: 'POST',
    headers: { 'Authorization': `Bearer ${token}` }
  });
};
```

**2. Mostrar Status** (opcional)
```javascript
const config = await fetch('/api/v1/landingpage/admin/social-feed/config');
// Muestra qué redes están configuradas
```

**3. Lo demás sigue igual:**
```javascript
// El endpoint público NO CAMBIA
const posts = await fetch('/api/v1/landingpage/social-feed', {
  headers: { 'X-Tenant-Slug': 'candidato' }
});
// Los posts ahora vienen de redes sociales reales
```

---

## 🚀 Flujo de Implementación

### Fase 1: Setup Básico (1-2 horas)
1. ✅ Crear `config/social.php`
2. ✅ Agregar variables en `.env`
3. ✅ Ejecutar migración para agregar campos

### Fase 2: Servicio (2-3 horas)
4. ✅ Crear `SocialMediaSyncService.php`
5. ✅ Implementar `syncTwitter()`, `syncFacebook()`, `syncInstagram()`
6. ✅ Probar con Postman/curl

### Fase 3: Controlador y Rutas (1 hora)
7. ✅ Crear `SocialMediaSyncController.php`
8. ✅ Agregar rutas en `routes/api.php`

### Fase 4: Automatización (1 hora)
9. ✅ Crear `SyncSocialMediaJob.php`
10. ✅ Configurar scheduler en `Kernel.php`
11. ✅ Ejecutar `php artisan queue:work`

### Fase 5: Obtener Credenciales (30 min por red)
12. 🔑 Crear apps en Twitter/Facebook/Instagram
13. 🔑 Obtener tokens de API
14. 🔑 Configurar permisos

### Fase 6: Frontend (30 min)
15. 🎨 Agregar botón de sincronización
16. 🎨 Mostrar indicador de configuración

---

## 📝 Archivos a Crear/Modificar

### Nuevos Archivos:
```
config/social.php                                    ✨ Nuevo
app/Services/SocialMediaSyncService.php              ✨ Nuevo
app/Http/Controllers/Api/V1/Landing/
    SocialMediaSyncController.php                    ✨ Nuevo
app/Jobs/SyncSocialMediaJob.php                      ✨ Nuevo
app/Console/Commands/SyncSocialMediaCommand.php      ✨ Nuevo
database/migrations/xxx_add_external_fields.php      ✨ Nuevo
```

### Modificar:
```
routes/api.php                                       📝 Agregar rutas
.env                                                 📝 Agregar tokens
app/Console/Kernel.php                               📝 Agregar schedule
```

---

## 💡 Ventajas de Este Enfoque

### ✅ Seguridad
- Tokens permanecen en el servidor
- No expones credenciales en frontend
- Control total sobre qué se muestra

### ✅ Performance
- Posts cacheados en tu DB
- Frontend super rápido (tu propia API)
- No dependes de APIs externas en runtime

### ✅ Confiabilidad
- Si Twitter cae, tu sitio sigue funcionando
- Los posts ya están guardados
- Puedes moderar contenido antes de publicar

### ✅ Control
- Filtras qué posts mostrar
- Puedes editar/ocultar posts problemáticos
- Transformas datos a tu formato

### ✅ Límites de API
- Respetas límites de Twitter/Facebook/Instagram
- 1 llamada cada 15 minutos vs miles de usuarios
- Sin problemas de rate limiting

---

## 🎮 Cómo Usarlo

### Sincronización Automática (Recomendada)
```bash
# Configurar en .env
SOCIAL_AUTO_SYNC=true
SOCIAL_SYNC_INTERVAL=15

# Ejecutar worker
php artisan queue:work

# Ya está! Se sincroniza solo cada 15 minutos
```

### Sincronización Manual
```bash
# Desde terminal
php artisan social:sync --all

# Desde admin panel (botón)
[Sincronizar Ahora] ← Usuario hace clic
```

### Ver Posts Sincronizados
```bash
# API pública (frontend landing)
GET /api/v1/landingpage/social-feed?tenant=candidato

# Los posts vienen de redes sociales reales
```

---

## 🔐 Credenciales Necesarias

### Twitter (X)
- **Qué necesitas:** Bearer Token
- **Dónde obtenerlo:** https://developer.twitter.com/
- **Costo:** Gratis (Essential Access)
- **Límites:** 500,000 tweets/mes

### Facebook
- **Qué necesitas:** Access Token + Page ID
- **Dónde obtenerlo:** https://developers.facebook.com/
- **Costo:** Gratis
- **Límites:** 200 llamadas/hora
- **⚠️ Importante:** Token expira en 60 días

### Instagram
- **Qué necesitas:** Access Token + User ID
- **Requiere:** Cuenta Business/Creator
- **Dónde obtenerlo:** Facebook Graph API
- **Costo:** Gratis
- **Límites:** 200 llamadas/hora

---

## 🆚 Comparación: Manual vs Automático

### Opción Actual (Manual)
```
Admin crea post manualmente
    ↓
Guarda en DB
    ↓
Muestra en landing
```
❌ Trabajo manual  
❌ Puede tener errores  
❌ Métricas estáticas  

### Nueva Opción (Automática)
```
Post en Twitter
    ↓
Backend sincroniza automáticamente
    ↓
Guarda en DB
    ↓
Muestra en landing
```
✅ Automático  
✅ Métricas reales  
✅ Imágenes originales  
✅ Links a posts reales  

---

## 🎯 Lo Mejor de Ambos Mundos

**Puedes usar ambos:**

1. **Posts Automáticos** desde redes sociales
   - Se sincronizan solos
   - Métricas reales
   - Contenido auténtico

2. **Posts Manuales** cuando necesites
   - Contenido especial
   - Eventos futuros
   - Posts destacados

**El sistema detecta automáticamente:**
- Si tiene `external_id` → viene de red social
- Si NO tiene `external_id` → creado manualmente

---

## 📚 Documentación Completa

- **`SOCIAL_FEED_INTEGRATION.md`** → Guía técnica completa
- **`LANDING_ADMIN_API.md`** → Endpoints actualizados
- **`LANDING_PUBLIC_API.md`** → API pública (sin cambios)

---

## ⚡ Quick Start

```bash
# 1. Configurar
cp .env.example .env
# Agregar tokens de Twitter/Facebook/Instagram

# 2. Migrar
php artisan migrate

# 3. Sincronizar
php artisan social:sync --all

# 4. Ver resultado
php artisan tinker
>>> App\Models\LandingSocialFeed::count()

# 5. Iniciar worker
php artisan queue:work
```

---

## 🤔 Preguntas Frecuentes

**P: ¿Puedo seguir creando posts manualmente?**  
R: ¡Sí! Ambos métodos coexisten perfectamente.

**P: ¿Necesito cambiar el frontend?**  
R: No necesariamente. Solo si quieres agregar botón de sincronización.

**P: ¿Los posts se duplican?**  
R: No. El sistema verifica `external_id` antes de crear.

**P: ¿Actualiza las métricas (likes, shares)?**  
R: Sí, cada vez que sincroniza actualiza los números.

**P: ¿Qué pasa si una red social está caída?**  
R: Tu landing sigue funcionando con posts cacheados.

**P: ¿Cuánto tarda la sincronización?**  
R: 2-5 segundos por red social (depende de la cantidad de posts).

**P: ¿Es gratis?**  
R: Sí, las APIs de redes sociales son gratuitas (con límites).

**P: ¿Necesito servidor especial?**  
R: No, cualquier servidor PHP con Laravel funciona.

---

## 🎉 Resumen Final

**Implementación:**
- 80% Backend (Laravel)
- 20% Frontend (opcional, solo UI)

**Tiempo estimado:**
- 8-12 horas total
- 4-6 horas si solo implementas 1 red social

**Beneficios:**
- Posts 100% reales de tus redes sociales
- Métricas actualizadas automáticamente
- Cero trabajo manual
- Super rápido y confiable

**Recomendación:**
✅ Implementa en backend como se describe
✅ Empieza con Twitter (más fácil)
✅ Luego agrega Facebook e Instagram
✅ Configura sincronización automática

---

**¿Necesitas ayuda?** Revisa `SOCIAL_FEED_INTEGRATION.md` para todos los detalles técnicos.
