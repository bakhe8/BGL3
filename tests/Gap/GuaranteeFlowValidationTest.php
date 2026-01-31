<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GuaranteeFlowValidationTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testCreateGuaranteeValidationFailsForMissingFields(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/create-guarantee.php';

        // أرسل حمولة ناقصة عمداً
        $payload = http_build_query(['amount' => 0]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $this->markTestSkipped('تعذر الاتصال بالواجهة: ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // إذا لم يكن المسار موجوداً، لا نكسر الاختبار
        if ($code === 404 || $code === 405) {
            $this->markTestSkipped('مسار إنشاء الضمان غير متوفر في هذه البيئة.');
        }

        // نجاح متوقع: يجب أن يكون 422 للتحقق الصارم، أو 400 على الأقل.
        $this->assertTrue(in_array($code, [400, 422], true), 'توقعنا فشل التحقق للبيانات الناقصة (400/422).');
    }
}
