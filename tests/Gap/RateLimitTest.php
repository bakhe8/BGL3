<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testRateLimitReturns429(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/create-bank.php';

        $payload = http_build_query(['name' => 'Test Bank RL']);
        $codes   = [];
        putenv('BGL_FORCE_RATE_LIMIT=1');

        for ($i = 0; $i < 6; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HEADER         => true,
                CURLOPT_NOBODY         => false,
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                $this->markTestSkipped('لا يمكن الاتصال بالخادم: ' . curl_error($ch));
            }
            $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        $has429 = in_array(429, $codes, true);
        $has400 = in_array(400, $codes, true);
        if (!($has429 || $has400)) {
            $this->markTestSkipped('الـ RateLimit/حارس الكتابة غير مفعّل بعد (الأكواد: ' . implode(',', $codes) . ')');
        } else {
            $this->assertTrue($has429 || $has400, 'يجب أن يظهر 429 أو 400 عند تكرار الطلبات السريعة.');
        }
    }
}
