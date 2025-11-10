# Estándar de Manejo de Zonas Horarias

## ⚠️ IMPORTANTE - REGLA DE ORO

**TODO el sistema trabaja en hora de Colombia (America/Bogota)**

## Configuración

```env
APP_TIMEZONE=America/Bogota
```

## Cómo funciona el flujo completo

### 1. Frontend → Backend

El frontend debe enviar las fechas/horas **exactamente como el usuario las selecciona** en hora de Colombia.

**Formato correcto del frontend:**
```javascript
// Usuario selecciona: 9 de noviembre de 2025 a las 7:20 PM

// ✅ CORRECTO - Enviar con formato ISO pero la hora es LOCAL de Colombia
{
  "starts_at": "2025-11-09T19:20:00.000Z"  // La Z es solo formato, representa 7:20 PM Colombia
}

// La "Z" en el formato ISO es solo por convención del formato
// El backend la ignora y toma solo: "2025-11-09T19:20:00"
```

**❌ NO HACER:**
```javascript
// ❌ NO convertir a UTC antes de enviar
// ❌ NO restar 5 horas
// ❌ NO hacer ninguna conversión de timezone en el frontend
```

## Resumen para el Frontend

**El frontend debe:**
1. ✅ Enviar fechas con el formato: `"2025-11-09T19:20:00.000Z"`
2. ✅ La hora debe ser la que el usuario seleccionó (sin conversiones)
3. ✅ La "Z" es solo parte del formato ISO, el backend la ignora
4. ✅ Recibir fechas con formato: `"2025-11-09T19:20:00-05:00"`
5. ✅ Mostrar al usuario directamente sin conversiones

**El frontend NO debe:**
- ❌ Convertir a UTC antes de enviar
- ❌ Restar o sumar horas por timezone
- ❌ Hacer cálculos de offset manualmente
- ❌ Usar `new Date().toISOString()` con la hora local directamente

## Fecha de última actualización

**9 de noviembre de 2025** - Estandarizado todo el sistema a America/Bogota
