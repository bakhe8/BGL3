<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Very small, file-backed rate limiter (per key, per window).
 * Designed for low-traffic CLI/server environments without external cache.
 */
class RateLimiter
{
    private const STORE = 'storage/ratelimit.json';

    /**
        * @return bool true if allowed, false if over the limit.
        */
    public static function allow(string $key, int $max, int $windowSeconds = 60): bool
    {
        $storePath = base_path(self::STORE);
        $now = time();
        $bucketStart = $now - ($now % $windowSeconds);

        $data = [];
        if (is_file($storePath)) {
            $json = @file_get_contents($storePath);
            $data = $json ? json_decode($json, true) ?: [] : [];
        }

        // cleanup old windows to keep file small
        foreach ($data as $k => $info) {
            if (!isset($info['window']) || $info['window'] < $bucketStart - $windowSeconds) {
                unset($data[$k]);
            }
        }

        $info = $data[$key] ?? ['count' => 0, 'window' => $bucketStart];
        if ($info['window'] !== $bucketStart) {
            $info = ['count' => 0, 'window' => $bucketStart];
        }

        if ($info['count'] >= $max) {
            $data[$key] = $info;
            self::persist($storePath, $data);
            return false;
        }

        $info['count'] += 1;
        $data[$key] = $info;
        self::persist($storePath, $data);
        return true;
    }

    private static function persist(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
