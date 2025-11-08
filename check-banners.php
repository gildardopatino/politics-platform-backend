<?php

/**
 * Script para verificar el estado de los banners en la BD
 * 
 * Uso: php check-banners.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "\n🔍 Verificando banners en la base de datos...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$banners = \App\Models\LandingBanner::with('tenant')->orderBy('id')->get();

if ($banners->isEmpty()) {
    echo "❌ No hay banners en la base de datos\n\n";
    exit;
}

foreach ($banners as $banner) {
    echo "Banner ID: {$banner->id}\n";
    echo "Tenant: {$banner->tenant->name} ({$banner->tenant->slug})\n";
    echo "Title: {$banner->title}\n";
    echo "Image Key: {$banner->image}\n";
    echo "Order: {$banner->order}\n";
    echo "Active: " . ($banner->is_active ? '✅ Sí' : '❌ No') . "\n";
    echo "Created: {$banner->created_at}\n";
    echo "Updated: {$banner->updated_at}\n";
    
    // Check if image exists in S3
    if ($banner->image) {
        $wasabi = app(\App\Services\WasabiStorageService::class);
        try {
            $url = $wasabi->getSignedUrl($banner->image, $banner->tenant);
            echo "✅ Imagen existe en S3\n";
            echo "URL: {$url}\n";
        } catch (\Exception $e) {
            echo "❌ Error al obtener URL: {$e->getMessage()}\n";
        }
    }
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

echo "Total de banners: {$banners->count()}\n\n";
