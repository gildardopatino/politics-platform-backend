<?php
/**
 * EMERGENCY CACHE CLEAR
 * Accede a: https://api.suite-electoral.cloud/clear-cache.php
 * 
 * IMPORTANTE: Eliminar este archivo después de usarlo por seguridad
 */

// Solo permitir en modo desarrollo o con una clave secreta
$secret = $_GET['secret'] ?? '';
if ($secret !== 'clear-cache-2025') {
    die('Access denied. Use: ?secret=clear-cache-2025');
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>Cache Clear Emergency</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>🚨 Emergency Cache Clear</h1>
";

$results = [];

// 1. Clear OPcache
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        $results[] = ['success', '✅ OPcache cleared successfully'];
    } else {
        $results[] = ['error', '❌ Failed to clear OPcache'];
    }
} else {
    $results[] = ['info', 'ℹ️ OPcache not available'];
}

// 2. Check OPcache status
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    $results[] = ['info', '📊 OPcache enabled: ' . ($status['opcache_enabled'] ? 'Yes' : 'No')];
    $results[] = ['info', '📊 Cache full: ' . ($status['cache_full'] ? 'Yes' : 'No')];
    $results[] = ['info', '📊 Cached scripts: ' . $status['opcache_statistics']['num_cached_scripts']];
}

// 3. Clear Laravel caches via Artisan
$laravelPath = __DIR__;
if (file_exists($laravelPath . '/artisan')) {
    $commands = [
        'cache:clear',
        'config:clear',
        'route:clear',
        'view:clear',
    ];
    
    foreach ($commands as $command) {
        $output = [];
        $return_var = 0;
        exec("cd $laravelPath && php artisan $command 2>&1", $output, $return_var);
        
        if ($return_var === 0) {
            $results[] = ['success', "✅ php artisan $command"];
        } else {
            $results[] = ['error', "❌ php artisan $command - " . implode("\n", $output)];
        }
    }
}

// 4. Check file modification time
$requestFile = __DIR__ . '/../app/Http/Requests/Api/V1/Tenant/UpdateTenantSettingsRequest.php';
if (file_exists($requestFile)) {
    $modTime = filemtime($requestFile);
    $results[] = ['info', '📄 UpdateTenantSettingsRequest.php last modified: ' . date('Y-m-d H:i:s', $modTime)];
    
    // Read current validation rules
    $content = file_get_contents($requestFile);
    if (strpos($content, 'starts_with') !== false) {
        $results[] = ['error', '❌ OLD CODE DETECTED: File still contains "starts_with" validation!'];
    } else {
        $results[] = ['success', '✅ NEW CODE: No "starts_with" validation found'];
    }
    
    if (strpos($content, 'size:7') !== false) {
        $results[] = ['error', '❌ OLD CODE DETECTED: File still contains "size:7" validation!'];
    } else {
        $results[] = ['success', '✅ NEW CODE: No "size:7" validation found'];
    }
}

// Display results
foreach ($results as $result) {
    [$type, $message] = $result;
    echo "<p class='$type'>$message</p>";
}

echo "
    <hr>
    <h2>Next Steps:</h2>
    <ol>
        <li>If OPcache was cleared successfully, try your request again</li>
        <li>If OLD CODE is detected, you need to deploy the new code to production</li>
        <li>After fixing the issue, <strong>DELETE THIS FILE</strong> for security</li>
    </ol>
    
    <h3>Current Server Info:</h3>
    <pre>";
    
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Server Time: " . date('Y-m-d H:i:s') . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";

if (function_exists('opcache_get_configuration')) {
    echo "\nOPcache Configuration:\n";
    $config = opcache_get_configuration();
    echo "Enabled: " . ($config['directives']['opcache.enable'] ? 'Yes' : 'No') . "\n";
    echo "Revalidate Freq: " . $config['directives']['opcache.revalidate_freq'] . " seconds\n";
    echo "Validate Timestamps: " . ($config['directives']['opcache.validate_timestamps'] ? 'Yes' : 'No') . "\n";
}

echo "</pre>
</body>
</html>";
