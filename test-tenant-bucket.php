<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Storage;
use App\Models\Tenant;

$tenantId = 1; // alcaldia-ibague

echo "Probando bucket del tenant...\n\n";

$tenant = Tenant::find($tenantId);

if (!$tenant) {
    echo "❌ Tenant no encontrado\n";
    exit;
}

echo "Tenant: {$tenant->name} ({$tenant->slug})\n";
echo "Bucket configurado: {$tenant->s3_bucket}\n\n";

try {
    // Obtener el client S3 directamente
    $s3Client = new \Aws\S3\S3Client([
        'version' => 'latest',
        'region' => config('filesystems.disks.s3.region'),
        'endpoint' => config('filesystems.disks.s3.endpoint'),
        'credentials' => [
            'key' => config('filesystems.disks.s3.key'),
            'secret' => config('filesystems.disks.s3.secret'),
        ],
        'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint'),
    ]);
    
    // Verificar si el bucket existe
    echo "Verificando si el bucket '{$tenant->s3_bucket}' existe...\n";
    
    $exists = $s3Client->doesBucketExist($tenant->s3_bucket);
    
    if ($exists) {
        echo "✅ El bucket '{$tenant->s3_bucket}' existe en Wasabi\n\n";
        
        // Intentar subir un archivo de prueba
        echo "Intentando subir archivo de prueba...\n";
        $testContent = "Test file for tenant {$tenant->slug} at " . date('Y-m-d H:i:s');
        $key = 'test-tenant-upload.txt';
        
        $s3Client->putObject([
            'Bucket' => $tenant->s3_bucket,
            'Key' => $key,
            'Body' => $testContent,
            'ACL' => 'private',
        ]);
        
        echo "✅ Archivo subido exitosamente\n\n";
        
        // Verificar existencia
        echo "Verificando existencia del archivo...\n";
        $objectExists = $s3Client->doesObjectExist($tenant->s3_bucket, $key);
        
        if ($objectExists) {
            echo "✅ El archivo existe\n\n";
            
            // Eliminar archivo de prueba
            echo "Eliminando archivo de prueba...\n";
            $s3Client->deleteObject([
                'Bucket' => $tenant->s3_bucket,
                'Key' => $key,
            ]);
            echo "✅ Archivo eliminado\n\n";
        }
        
        echo "🎉 ¡El bucket del tenant funciona correctamente!\n";
        
    } else {
        echo "❌ El bucket '{$tenant->s3_bucket}' NO existe en Wasabi\n";
        echo "\n⚠️ Debes crear este bucket en Wasabi o actualizar el tenant para usar otro bucket.\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
