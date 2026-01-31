<?php

namespace App\Models;

class AuditLog
{
    /**
     * Record audit event to a lightweight file log.
     */
    public static function record(string $entity, $id, string $action, array $payload = []): void
    {
        try {
            $base = __DIR__ . '/../../storage/logs';
            if (!is_dir($base)) {
                mkdir($base, 0755, true);
            }
            $line = json_encode([
                'ts'      => date('c'),
                'entity'  => $entity,
                'id'      => $id,
                'action'  => $action,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE);
            file_put_contents($base . '/audit.log', $line . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            // لا نفشل التنفيذ بسبب التدقيق
        }
    }
}
