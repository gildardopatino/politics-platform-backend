#!/bin/bash

# Script para reiniciar el servidor después de cambios en validación
# Ejecutar en el servidor de producción

echo "🔄 Limpiando cachés de Laravel..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "🔄 Reiniciando PHP-FPM..."
# Para PHP 8.3
sudo systemctl restart php8.3-fpm

# O si usas PHP 8.2
# sudo systemctl restart php8.2-fpm

# O si usas PHP 8.1
# sudo systemctl restart php8.1-fpm

# Para Nginx
echo "🔄 Reiniciando Nginx..."
sudo systemctl restart nginx

echo "✅ Servidor reiniciado exitosamente"

# Verificar estado
echo "📊 Estado de los servicios:"
sudo systemctl status php8.3-fpm --no-pager -l
sudo systemctl status nginx --no-pager -l
