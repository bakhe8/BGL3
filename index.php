<?php
/**
 * BGL System v3.0 - MVC Entry Point
 * 
 * Slim router that delegates to controllers
 */

declare(strict_types=1);

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Content-Type: text/html; charset=utf-8');

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Simple error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    // Simple routing
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    // Main dashboard route
    if ($uri === '/' || $uri === '/index.php' || strpos($uri, '/?') === 0) {
        $controller = new \App\Controllers\DashboardController();
        $controller->index();
    } 
    // API routes (handled by their own files - no change needed)
    elseif (strpos($uri, '/api/') === 0) {
        // API files handle themselves
        return false;
    }
    // 404 for unknown routes
    else {
        http_response_code(404);
        echo '<!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>404 - الصفحة غير موجودة</title>
            <style>
                body { font-family: Tajawal, sans-serif; text-align: center; padding: 50px; }
                h1 { color: #dc2626; }
            </style>
        </head>
        <body>
            <h1>404</h1>
            <p>الصفحة المطلوبة غير موجودة</p>
            <a href="/">العودة للصفحة الرئيسية</a>
        </body>
        </html>';
    }
    
} catch (\Throwable $e) {
    // Error handling
    http_response_code(500);
    
    echo '<!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>خطأ في النظام</title>
        <style>
            body { font-family: Tajawal, sans-serif; padding: 50px; direction: rtl; }
            .error-box { background: #fee2e2; border: 2px solid #dc2626; padding: 20px; border-radius: 8px; max-width: 800px; margin: 0 auto; }
            h1 { color: #dc2626; margin-top: 0; }
            pre { background: white; padding: 15px; border-radius: 4px; overflow-x: auto; direction: ltr; text-align: left; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>⚠️ حدث خطأ غير متوقع</h1>
            <p>نعتذر عن هذا الخطأ. التفاصيل:</p>
            <pre>' . htmlspecialchars($e->getMessage()) . '

File: ' . htmlspecialchars($e->getFile()) . '
Line: ' . $e->getLine() . '</pre>
            <p><a href="/">العودة للصفحة الرئيسية</a></p>
        </div>
    </body>
    </html>';
}
