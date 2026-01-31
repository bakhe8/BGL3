<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class JsSmokeTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testHomePageLoadsAndScriptsPresent(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $this->markTestSkipped('تعذر الاتصال بالواجهة: ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            $this->markTestSkipped('لم يتمكن الاختبار من الحصول على الصفحة (HTTP ' . $code . ')');
        }

        $this->assertStringContainsString('<script', $body, 'يجب أن تحتوي الصفحة على ملفات JS محملة.');
    }
}
