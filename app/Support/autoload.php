<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../';

    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require $path;
        }
    }
});

// Composer autoload (PhpSpreadsheet)
$composerAutoload = base_path('vendor/autoload.php');
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__, 1);
    // Move up one more level to reach project root
    $base = dirname($base);
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}

function storage_path(string $path = ''): string
{
    $base = base_path('storage');
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}

/**
 * Simple logger helper
 * Usage: \App\Support\Logger::error('message', ['context' => 'data']);
 */
