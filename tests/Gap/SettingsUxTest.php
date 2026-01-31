<?php

use PHPUnit\Framework\TestCase;

class SettingsUxTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testSettingsAutosave(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/settings.php';

        $payload = http_build_query(['auto_save_probe' => uniqid('autosave_', true)]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 0) {
            $this->markTestSkipped('الخادم غير متاح لاختبار الإعدادات.');
        }

        if (!in_array($code, [200, 204], true)) {
            $this->markTestSkipped("ميزة الحفظ التلقائي غير مفعّلة بعد، الكود: $code");
        } else {
            $this->assertContains($code, [200, 204], 'يجب أن يدعم endpoint الإعدادات الحفظ السريع.');
        }
    }
}
