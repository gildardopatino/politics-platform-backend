<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Storage;

try {
    echo "Probando conexión con Wasabi...\n\n";
    
    // Información de configuración
    echo "Configuración:\n";
    echo "- Endpoint: " . config('filesystems.disks.s3.endpoint') . "\n";
    echo "- Bucket: " . config('filesystems.disks.s3.bucket') . "\n";
    echo "- Region: " . config('filesystems.disks.s3.region') . "\n";
    echo "- Access Key: " . substr(config('filesystems.disks.s3.key'), 0, 5) . "...\n\n";
    
    // Intentar crear un archivo de prueba
    echo "Creando archivo de prueba...\n";
    $testContent = "Test file created at " . date('Y-m-d H:i:s');
    Storage::disk('s3')->put('test-connection.txt', $testContent);
    echo "✅ Archivo creado exitosamente\n\n";
    
    // Verificar si existe
    echo "Verificando si el archivo existe...\n";
    if (Storage::disk('s3')->exists('test-connection.txt')) {
        echo "✅ El archivo existe\n\n";
        
        // Leer contenido
        echo "Leyendo contenido del archivo...\n";
        $content = Storage::disk('s3')->get('test-connection.txt');
        echo "✅ Contenido: " . $content . "\n\n";
        
        // Eliminar archivo de prueba
        echo "Eliminando archivo de prueba...\n";
        Storage::disk('s3')->delete('test-connection.txt');
        echo "✅ Archivo eliminado\n\n";
    } else {
        echo "❌ El archivo no existe\n\n";
    }
    
    echo "🎉 ¡Conexión con Wasabi exitosa!\n";
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
