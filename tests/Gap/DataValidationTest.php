<?php

use PHPUnit\Framework\TestCase;

class DataValidationTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testEmailAndPhoneValidation(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/create-bank.php';

        $payload = http_build_query([
            'name' => 'Invalid Contact Bank',
            'email' => 'not-an-email',
            'phone' => 'bad-phone',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HEADER         => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $this->markTestSkipped('لا يمكن الاتصال بالخادم: ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 0) {
            $this->markTestSkipped('لم يتم الحصول على استجابة من الخادم.');
        }

        $this->assertContains($code, [400, 422], "يجب أن يعيد 400/422 عند Email/Phone غير صالحين، الكود: $code");
    }
}
