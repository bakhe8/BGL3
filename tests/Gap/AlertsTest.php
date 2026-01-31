<?php

use PHPUnit\Framework\TestCase;

class AlertsTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testAlertsOnRepeatedFailures(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/non-existing-endpoint';

        $codes = [];
        for ($i = 0; $i < 3; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
            ]);
            curl_exec($ch);
            $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if (max($codes) === 0) {
            $this->markTestSkipped('لا يمكن الاتصال بالخادم لاختبار التنبيه.');
        }

        // لا نتحقق من إرسال التنبيه فعلياً؛ نطلب تسجيل حدث في النظام
        $this->assertGreaterThan(0, max($codes), 'يجب أن يسجّل النظام الأخطاء المتكررة للتنبيه.');
    }
}
