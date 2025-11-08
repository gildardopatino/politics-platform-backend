<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Tenant;

echo "Listando todos los tenants y sus buckets:\n\n";

$tenants = Tenant::all();

if ($tenants->isEmpty()) {
    echo "❌ No hay tenants registrados\n";
} else {
    foreach ($tenants as $tenant) {
        echo "ID: {$tenant->id}\n";
        echo "Nombre: {$tenant->name}\n";
        echo "Slug: {$tenant->slug}\n";
        echo "Bucket S3: " . ($tenant->s3_bucket ?: '❌ NO CONFIGURADO (usará bucket por defecto)') . "\n";
        echo "Estado: " . ($tenant->is_active ? '✅ Activo' : '❌ Inactivo') . "\n";
        echo str_repeat('-', 50) . "\n\n";
    }
}

echo "\n📝 Bucket por defecto del sistema: " . config('filesystems.disks.s3.bucket') . "\n";
