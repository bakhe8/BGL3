<?php

namespace App\Support;

class Alert
{
    /**
     * Log failure events and (optionally) trigger downstream notifier.
     * For now: يسجل فقط في alerts.log عندما تتكرر 3 مرات خلال 5 دقائق لنفس المسار.
     */
    public static function logFailure(string $route, int $status, string $message = ''): void
    {
        try {
            $base = __DIR__ . '/../../storage/logs';
            if (!is_dir($base)) {
                mkdir($base, 0755, true);
            }
            $file = $base . '/alerts.log';
            $now = time();
            $line = json_encode([
                'ts' => $now,
                'route' => $route,
                'status' => $status,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE);
            file_put_contents($file, $line . PHP_EOL, FILE_APPEND);

            // تحقق سريع من التكرار (5 دقائق)
            $recent = [];
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $window = $now - 300;
            foreach (array_reverse($lines) as $l) {
                $entry = json_decode($l, true);
                if (!$entry || $entry['ts'] < $window) {
                    break;
                }
                if ($entry['route'] === $route) {
                    $recent[] = $entry;
                }
                if (count($recent) >= 3) {
                    // نقطة دمج مستقبلية: إرسال Slack/Email
                    break;
                }
            }
        } catch (\Throwable $e) {
            // لا تعطل المسار بسبب التنبيه
        }
    }

    /**
     * Return recent active alerts for dashboard widgets.
     *
     * @param int $windowSec Lookback window in seconds (default 15 minutes).
     * @param int $limit Maximum number of alerts to return.
     * @return array<int, array{level:string,message:string,timestamp:string}>
     */
    public static function getActiveAlerts(int $windowSec = 900, int $limit = 10): array
    {
        $alerts = [];
        try {
            $file = __DIR__ . '/../../storage/logs/alerts.log';
            if (!file_exists($file)) {
                return [];
            }
            $now = time();
            $window = $now - max(60, $windowSec);
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $line) {
                $entry = json_decode($line, true);
                if (!$entry || !isset($entry['ts'])) {
                    continue;
                }
                if ((int)$entry['ts'] < $window) {
                    break;
                }
                $status = (int)($entry['status'] ?? 0);
                $level = 'warning';
                if ($status >= 500) {
                    $level = 'error';
                } elseif ($status >= 400) {
                    $level = 'warning';
                } else {
                    $level = 'success';
                }
                $route = (string)($entry['route'] ?? '');
                $msg = trim((string)($entry['message'] ?? ''));
                if ($msg === '') {
                    $msg = $route !== '' ? "{$route} ({$status})" : "Alert {$status}";
                }
                $alerts[] = [
                    'level' => $level,
                    'message' => $msg,
                    'timestamp' => date('H:i', (int)$entry['ts']),
                ];
                if (count($alerts) >= $limit) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $alerts;
    }
}
