<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Clear Cache Routes - SOLO PARA DESARROLLO/URGENCIAS
|--------------------------------------------------------------------------
| IMPORTANTE: Eliminar estas rutas en producción después de usarlas
| o protegerlas con autenticación de superadmin
*/

Route::get('/clear-all-cache', function () {
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
            'opcache_reset' => function_exists('opcache_reset') ? 'yes' : 'no',
            'timestamp' => now()->toISOString(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage()
        ], 500);
    }
})->name('clear.cache');

// Ruta para verificar qué archivo se está usando
Route::get('/check-request-file', function () {
    $reflection = new \ReflectionClass(\App\Http\Requests\Api\V1\Tenant\UpdateTenantSettingsRequest::class);
    
    return response()->json([
        'file_path' => $reflection->getFileName(),
        'file_modified' => date('Y-m-d H:i:s', filemtime($reflection->getFileName())),
        'rules_method_exists' => $reflection->hasMethod('rules'),
    ]);
});
