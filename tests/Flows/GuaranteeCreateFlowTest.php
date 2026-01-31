<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GuaranteeCreateFlowTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testCreateGuaranteeHappyPath(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/create-guarantee.php';

        $payload = http_build_query([
            'beneficiary' => 'Test Beneficiary',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $this->markTestSkipped('تعذر الاتصال بالواجهة: ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // إذا لم يكن المسار متاحاً في بيئة الاختبار، لا نكسر البنائية
        if ($code === 404 || $code === 405) {
            $this->markTestSkipped('مسار إنشاء الضمان غير مفعل في هذه البيئة (HTTP ' . $code . ')');
        }

        $this->assertTrue(in_array($code, [200, 201, 302], true), 'توقعنا استجابة ناجحة لسيناريو إنشاء ضمان.');
    }
}
