<?php
/**
 * V3 Standalone Server Router
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Serve static files directly
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    
    if ($ext === 'php') {
        require $file;
        exit;
    }
    
    return false; // Let PHP's built-in server handle it
}

// Default to index.php
require __DIR__ . '/index.php';
