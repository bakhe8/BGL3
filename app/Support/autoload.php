<?php
declare(strict_types=1);

// Set timezone from Settings (dynamic)
date_default_timezone_set('Asia/Riyadh'); // Will be overridden below after Settings loads

// ✅ PHASE 3: Shadow Run Support (Global)
// Detect header and set env var so Database.php sees it in ALL endpoints (UI + API)
if (isset($_SERVER['HTTP_X_SHADOW_MODE']) && $_SERVER['HTTP_X_SHADOW_MODE'] === 'true') {
    putenv('SHADOW_MODE=true');
}

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// After Settings class is loaded, update timezone dynamically
if (class_exists('App\\Support\\Settings')) {
    $settings = new App\Support\Settings();
    $timezone = $settings->get('TIMEZONE', 'Asia/Riyadh');
    date_default_timezone_set($timezone);
}

// Composer autoload (PhpSpreadsheet)
$composerAutoload = base_path('vendor/autoload.php');
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Lightweight RateLimit guard for all API endpoints (heuristic on script path)
try {
    if (isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], '/api/')) {
        require_once __DIR__ . '/RateLimiter.php';
        $perMinute = (int)($_ENV['RATE_LIMIT_PER_MIN'] ?? getenv('RATE_LIMIT_PER_MIN') ?: 120);
        $key = 'api:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if (!\App\Support\RateLimiter::allow($key, max(20, $perMinute), 60)) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'retry_after_sec' => 60,
            ]);
            exit;
        }
    }
} catch (\Throwable $e) {
    // Fail-open: do not break app if rate limiter storage is unavailable
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
