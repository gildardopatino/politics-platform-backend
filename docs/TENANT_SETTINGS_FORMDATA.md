# Tenant Settings API - FormData Guide

## Endpoint
```
PUT /api/v1/tenant/settings
```

## Headers
```javascript
{
  'Authorization': 'Bearer <token>',
  // NO incluir 'Content-Type' - se establece automáticamente
}
```

---

## 📤 Ejemplo Completo - JavaScript/React

```javascript
const updateTenantSettings = async (logoFile, settings) => {
  const formData = new FormData();
  
  // 1. Logo (opcional) - File object
  if (logoFile) {
    formData.append('logo', logoFile);
  }
  
  // 2. Theme Colors (formato: #RRGGBB)
  if (settings.sidebar_bg_color) {
    formData.append('sidebar_bg_color', settings.sidebar_bg_color); // Ej: "#1a1a2e"
  }
  if (settings.sidebar_text_color) {
    formData.append('sidebar_text_color', settings.sidebar_text_color);
  }
  if (settings.header_bg_color) {
    formData.append('header_bg_color', settings.header_bg_color);
  }
  if (settings.header_text_color) {
    formData.append('header_text_color', settings.header_text_color);
  }
  if (settings.content_bg_color) {
    formData.append('content_bg_color', settings.content_bg_color);
  }
  if (settings.content_text_color) {
    formData.append('content_text_color', settings.content_text_color);
  }
  
  // 3. Hierarchy Settings
  if (settings.hierarchy_mode) {
    // Valores permitidos: 'disabled', 'simple_tree', 'multiple_supervisors', 'context_based'
    formData.append('hierarchy_mode', settings.hierarchy_mode);
  }
  
  if (settings.auto_assign_hierarchy !== undefined) {
    // Enviar como '1' o '0' (string)
    formData.append('auto_assign_hierarchy', settings.auto_assign_hierarchy ? '1' : '0');
  }
  
  if (settings.hierarchy_conflict_resolution) {
    // Valores permitidos: 'last_assignment', 'most_active', 'manual_review'
    formData.append('hierarchy_conflict_resolution', settings.hierarchy_conflict_resolution);
  }
  
  if (settings.require_hierarchy_config !== undefined) {
    // Enviar como '1' o '0' (string)
    formData.append('require_hierarchy_config', settings.require_hierarchy_config ? '1' : '0');
  }

  // 4. Enviar request
  const response = await fetch('https://api.suite-electoral.cloud/api/v1/tenant/settings', {
    method: 'PUT',
    headers: {
      'Authorization': `Bearer ${localStorage.getItem('token')}`,
    },
    body: formData
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Error al actualizar configuración');
  }

  return await response.json();
};
```

---

## 🎨 Componente React Completo

```jsx
import { useState, useEffect } from 'react';

function TenantSettingsForm({ token }) {
  const [loading, setLoading] = useState(false);
  const [currentLogo, setCurrentLogo] = useState(null);
  
  // Form state
  const [logoFile, setLogoFile] = useState(null);
  const [logoPreview, setLogoPreview] = useState(null);
  const [sidebarBgColor, setSidebarBgColor] = useState('#1a1a2e');
  const [sidebarTextColor, setSidebarTextColor] = useState('#ffffff');
  const [headerBgColor, setHeaderBgColor] = useState('#0f3460');
  const [headerTextColor, setHeaderTextColor] = useState('#ffffff');
  const [contentBgColor, setContentBgColor] = useState('#f5f5f5');
  const [contentTextColor, setContentTextColor] = useState('#333333');
  
  // Hierarchy settings
  const [hierarchyMode, setHierarchyMode] = useState('simple_tree');
  const [autoAssignHierarchy, setAutoAssignHierarchy] = useState(true);
  const [hierarchyConflictResolution, setHierarchyConflictResolution] = useState('last_assignment');
  const [requireHierarchyConfig, setRequireHierarchyConfig] = useState(false);

  // Load current settings
  useEffect(() => {
    loadCurrentSettings();
  }, []);

  const loadCurrentSettings = async () => {
    try {
      const response = await fetch('https://api.suite-electoral.cloud/api/v1/tenant/settings', {
        headers: {
          'Authorization': `Bearer ${token}`,
        }
      });
      
      const result = await response.json();
      const { data } = result;
      
      // Load theme colors
      if (data.theme) {
        setSidebarBgColor(data.theme.sidebar_bg_color || '#1a1a2e');
        setSidebarTextColor(data.theme.sidebar_text_color || '#ffffff');
        setHeaderBgColor(data.theme.header_bg_color || '#0f3460');
        setHeaderTextColor(data.theme.header_text_color || '#ffffff');
        setContentBgColor(data.theme.content_bg_color || '#f5f5f5');
        setContentTextColor(data.theme.content_text_color || '#333333');
      }
      
      // Load hierarchy settings
      if (data.hierarchy_settings) {
        setHierarchyMode(data.hierarchy_settings.hierarchy_mode || 'simple_tree');
        setAutoAssignHierarchy(data.hierarchy_settings.auto_assign_hierarchy || false);
        setHierarchyConflictResolution(data.hierarchy_settings.hierarchy_conflict_resolution || 'last_assignment');
        setRequireHierarchyConfig(data.hierarchy_settings.require_hierarchy_config || false);
      }
      
      // Load current logo
      if (data.logo) {
        setCurrentLogo(data.logo);
      }
    } catch (error) {
      console.error('Error loading settings:', error);
    }
  };

  const handleLogoChange = (e) => {
    const file = e.target.files[0];
    
    if (!file) return;
    
    // Validate file size (2MB max)
    if (file.size > 2 * 1024 * 1024) {
      alert('El archivo es muy grande. Máximo 2MB.');
      return;
    }
    
    // Validate file type
    const validTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/svg+xml'];
    if (!validTypes.includes(file.type)) {
      alert('Tipo de archivo no válido. Use JPEG, PNG, JPG, WEBP o SVG.');
      return;
    }
    
    setLogoFile(file);
    
    // Generate preview
    const reader = new FileReader();
    reader.onloadend = () => {
      setLogoPreview(reader.result);
    };
    reader.readAsDataURL(file);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);

    try {
      const settings = {
        sidebar_bg_color: sidebarBgColor,
        sidebar_text_color: sidebarTextColor,
        header_bg_color: headerBgColor,
        header_text_color: headerTextColor,
        content_bg_color: contentBgColor,
        content_text_color: contentTextColor,
        hierarchy_mode: hierarchyMode,
        auto_assign_hierarchy: autoAssignHierarchy,
        hierarchy_conflict_resolution: hierarchyConflictResolution,
        require_hierarchy_config: requireHierarchyConfig,
      };

      const result = await updateTenantSettings(logoFile, settings);
      
      alert('✅ Configuración actualizada exitosamente');
      
      // Update current logo with new URL
      if (result.data.logo) {
        setCurrentLogo(result.data.logo);
        setLogoPreview(null);
        setLogoFile(null);
      }
    } catch (error) {
      alert('❌ Error: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const handleDeleteLogo = async () => {
    if (!confirm('¿Estás seguro de eliminar el logo?')) return;

    try {
      const response = await fetch('https://api.suite-electoral.cloud/api/v1/tenant/settings/logo', {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`,
        }
      });

      if (response.ok) {
        setCurrentLogo(null);
        setLogoPreview(null);
        setLogoFile(null);
        alert('✅ Logo eliminado exitosamente');
      }
    } catch (error) {
      alert('❌ Error al eliminar logo: ' + error.message);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="tenant-settings-form">
      <h2>Configuración del Tenant</h2>
      
      {/* Logo Section */}
      <section className="logo-section">
        <h3>Logo</h3>
        
        <div className="logo-preview">
          {(logoPreview || currentLogo) && (
            <div>
              <img 
                src={logoPreview || currentLogo} 
                alt="Logo" 
                style={{ maxWidth: '200px', maxHeight: '100px' }}
              />
              {currentLogo && !logoPreview && (
                <button 
                  type="button" 
                  onClick={handleDeleteLogo}
                  className="btn-delete"
                >
                  🗑️ Eliminar Logo
                </button>
              )}
            </div>
          )}
        </div>
        
        <input 
          type="file" 
          accept="image/jpeg,image/png,image/jpg,image/webp,image/svg+xml"
          onChange={handleLogoChange}
        />
        {logoFile && <p>📎 {logoFile.name} ({(logoFile.size / 1024).toFixed(2)} KB)</p>}
        <small>Formatos permitidos: JPEG, PNG, JPG, WEBP, SVG. Máximo 2MB.</small>
      </section>

      {/* Theme Colors */}
      <section className="theme-section">
        <h3>Colores del Tema</h3>
        
        <div className="color-grid">
          <div className="color-input">
            <label>Fondo del Sidebar</label>
            <input 
              type="color" 
              value={sidebarBgColor}
              onChange={(e) => setSidebarBgColor(e.target.value)}
            />
            <span>{sidebarBgColor}</span>
          </div>

          <div className="color-input">
            <label>Texto del Sidebar</label>
            <input 
              type="color" 
              value={sidebarTextColor}
              onChange={(e) => setSidebarTextColor(e.target.value)}
            />
            <span>{sidebarTextColor}</span>
          </div>

          <div className="color-input">
            <label>Fondo del Header</label>
            <input 
              type="color" 
              value={headerBgColor}
              onChange={(e) => setHeaderBgColor(e.target.value)}
            />
            <span>{headerBgColor}</span>
          </div>

          <div className="color-input">
            <label>Texto del Header</label>
            <input 
              type="color" 
              value={headerTextColor}
              onChange={(e) => setHeaderTextColor(e.target.value)}
            />
            <span>{headerTextColor}</span>
          </div>

          <div className="color-input">
            <label>Fondo del Contenido</label>
            <input 
              type="color" 
              value={contentBgColor}
              onChange={(e) => setContentBgColor(e.target.value)}
            />
            <span>{contentBgColor}</span>
          </div>

          <div className="color-input">
            <label>Texto del Contenido</label>
            <input 
              type="color" 
              value={contentTextColor}
              onChange={(e) => setContentTextColor(e.target.value)}
            />
            <span>{contentTextColor}</span>
          </div>
        </div>
      </section>

      {/* Hierarchy Settings */}
      <section className="hierarchy-section">
        <h3>Configuración de Jerarquía</h3>
        
        <div className="form-group">
          <label>Modo de Jerarquía</label>
          <select 
            value={hierarchyMode}
            onChange={(e) => setHierarchyMode(e.target.value)}
          >
            <option value="disabled">Deshabilitado</option>
            <option value="simple_tree">Árbol Simple</option>
            <option value="multiple_supervisors">Múltiples Supervisores</option>
            <option value="context_based">Basado en Contexto</option>
          </select>
        </div>

        <div className="form-group">
          <label>
            <input 
              type="checkbox"
              checked={autoAssignHierarchy}
              onChange={(e) => setAutoAssignHierarchy(e.target.checked)}
            />
            Asignación Automática de Jerarquía
          </label>
        </div>

        <div className="form-group">
          <label>Resolución de Conflictos</label>
          <select 
            value={hierarchyConflictResolution}
            onChange={(e) => setHierarchyConflictResolution(e.target.value)}
          >
            <option value="last_assignment">Última Asignación</option>
            <option value="most_active">Más Activo</option>
            <option value="manual_review">Revisión Manual</option>
          </select>
        </div>

        <div className="form-group">
          <label>
            <input 
              type="checkbox"
              checked={requireHierarchyConfig}
              onChange={(e) => setRequireHierarchyConfig(e.target.checked)}
            />
            Requerir Configuración de Jerarquía
          </label>
        </div>
      </section>

      {/* Submit Button */}
      <button 
        type="submit" 
        disabled={loading}
        className="btn-primary"
      >
        {loading ? '⏳ Guardando...' : '💾 Guardar Configuración'}
      </button>
    </form>
  );
}

export default TenantSettingsForm;
```

---

## ✅ Validaciones del Backend

| Campo | Validación |
|-------|-----------|
| `logo` | Archivo de imagen (JPEG, PNG, JPG, WEBP, SVG), máximo 2MB |
| `*_color` | String de 7 caracteres que empiece con `#` (ej: `#ffffff`) |
| `hierarchy_mode` | String: `disabled`, `simple_tree`, `multiple_supervisors`, `context_based` |
| `auto_assign_hierarchy` | Boolean (enviar como `'1'` o `'0'` en FormData) |
| `hierarchy_conflict_resolution` | String: `last_assignment`, `most_active`, `manual_review` |
| `require_hierarchy_config` | Boolean (enviar como `'1'` o `'0'` en FormData) |

---

## 📝 Respuesta Exitosa

```json
{
  "data": {
    "id": 1,
    "slug": "alcaldia-ibague",
    "nombre": "Alcaldía de Ibagué",
    "tipo_cargo": "alcalde",
    "identificacion": "123456789",
    "logo": "https://guillermoalvira.s3.us-east-1.wasabisys.com/tenants/logos/1762648392_690fdf689f2d8.jpg?X-Amz-...",
    "logo_key": "tenants/logos/1762648392_690fdf689f2d8.jpg",
    "theme": {
      "sidebar_bg_color": "#1a1a2e",
      "sidebar_text_color": "#ffffff",
      "header_bg_color": "#0f3460",
      "header_text_color": "#ffffff",
      "content_bg_color": "#f5f5f5",
      "content_text_color": "#333333"
    },
    "hierarchy_settings": {
      "hierarchy_mode": "simple_tree",
      "auto_assign_hierarchy": true,
      "hierarchy_conflict_resolution": "last_assignment",
      "require_hierarchy_config": false
    }
  },
  "message": "Tenant settings updated successfully"
}
```

---

## ⚠️ Errores Comunes

### 422 - Validation Error
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "logo": ["El archivo debe ser una imagen válida (JPEG, PNG, JPG, WEBP o SVG)."],
    "sidebar_bg_color": ["El color debe comenzar con # (ejemplo: #ffffff)."]
  }
}
```

### 500 - Upload Error
```json
{
  "message": "Error al subir el logo: Connection timeout"
}
```
