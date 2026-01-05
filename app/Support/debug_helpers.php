<?php
declare(strict_types=1);

/**
 * Production Debug Helper
 * 
 * Provides debug logging that respects production mode
 */

// Check if in production mode
if (!defined('IS_PRODUCTION')) {
    // Check from Settings if available
    if (class_exists('App\\Support\\Settings')) {
        $settings = new App\Support\Settings();
        define('IS_PRODUCTION', $settings->get('PRODUCTION_MODE', false));
    } else {
        // Default to development
        define('IS_PRODUCTION', false);
    }
}

/**
 * Debug log - only logs in development mode
 * 
 * @param string $message Debug message
 * @param mixed $context Additional context (will be JSON encoded)
 */
function debug_log(string $message, mixed $context = null): void
{
    if (IS_PRODUCTION) {
        return; // Silent in production
    }
    
    $output = $message;
    if ($context !== null) {
        $output .= ' | Context: ' . json_encode($context);
    }
    
    error_log($output);
}

/**
 * Production-safe var_dump replacement
 * 
 * @param mixed $var Variable to dump
 * @param string $label Optional label
 */
function debug_dump(mixed $var, string $label = ''): void
{
    if (IS_PRODUCTION) {
        return; // Silent in production
    }
    
    $prefix = $label ? "[{$label}] "  : '';
    error_log($prefix . print_r($var, true));
}
