<?php
namespace App\Support;

class QuickTrace
{
    protected static $file = __DIR__ . '/../../storage/logs/traces.jsonl';
    protected static $requestId = null;

    public static function log($query, $durationMs, $route = null)
    {
        if (!self::$requestId) {
            self::$requestId = uniqid('req_', true);
        }

        $data = [
            'timestamp' => microtime(true),
            'request_id' => self::$requestId,
            'route' => $route ?? ($_SERVER['REQUEST_URI'] ?? 'cli'),
            'type' => 'query',
            'statement' => self::normalizeQuery($query),
            'duration_ms' => round($durationMs, 2),
            'memory' => memory_get_usage(),
        ];

        file_put_contents(self::$file, json_encode($data) . "\n", FILE_APPEND | LOCK_EX);
    }

    protected static function normalizeQuery($sql)
    {
        // Simple normalization to group similar queries
        $sql = preg_replace('/\d+/', '?', $sql);
        $sql = preg_replace('/\'[^\']*\'/', '?', $sql);
        return trim(preg_replace('/\s+/', ' ', $sql));
    }
}
