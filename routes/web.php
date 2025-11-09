<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return view('welcome');
});

// TEMPORAL - Ruta de emergencia para limpiar caché
// TODO: Eliminar después de resolver el problema
Route::get('/clear-all-cache-emergency', function () {
    try {
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        
        // Si usas OPcache
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        return response()->json([
            'message' => '✅ Todas las cachés limpiadas exitosamente',
            'opcache_reset' => function_exists('opcache_reset') ? 'Sí - OPcache reiniciado' : 'No disponible',
            'timestamp' => now()->toISOString(),
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Verificar archivo de validación actual
Route::get('/check-validation-file', function () {
    try {
        $reflection = new \ReflectionClass(\App\Http\Requests\Api\V1\Tenant\UpdateTenantSettingsRequest::class);
        $filePath = $reflection->getFileName();
        
        return response()->json([
            'file_path' => $filePath,
            'file_modified' => date('Y-m-d H:i:s', filemtime($filePath)),
            'file_size' => filesize($filePath),
            'rules_method_exists' => $reflection->hasMethod('rules'),
            'current_content_hash' => md5_file($filePath),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage()
        ], 500);
    }
});
