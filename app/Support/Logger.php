<?php
declare(strict_types=1);

namespace App\Support;

class Logger
{
    private static function getLogPath(): string
    {
        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        return $logDir . '/app.log';
    }

    public static function error(string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[$timestamp] ERROR: $message";
        if ($contextStr) {
            $logMessage .= " | Context: $contextStr";
        }
        $logMessage .= PHP_EOL;

        file_put_contents(self::getLogPath(), $logMessage, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[$timestamp] INFO: $message";
        if ($contextStr) {
            $logMessage .= " | Context: $contextStr";
        }
        $logMessage .= PHP_EOL;

        file_put_contents(self::getLogPath(), $logMessage, FILE_APPEND | LOCK_EX);
    }

    public static function warning(string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[$timestamp] WARNING: $message";
        if ($contextStr) {
            $logMessage .= " | Context: $contextStr";
        }
        $logMessage .= PHP_EOL;

        file_put_contents(self::getLogPath(), $logMessage, FILE_APPEND | LOCK_EX);
    }
}
