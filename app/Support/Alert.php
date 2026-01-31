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
}
