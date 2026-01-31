<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ImportFlowSmokeTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testImportSuppliersRejectsBadFile(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/import_suppliers.php';

        $boundary = '----BGLTEST';
        $payload =
            "--{$boundary}\r\n".
            "Content-Disposition: form-data; name=\"file\"; filename=\"bad.txt\"\r\n".
            "Content-Type: text/plain\r\n\r\n".
            "not-a-csv\r\n".
            "--{$boundary}--\r\n";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Content-Type: multipart/form-data; boundary={$boundary}"],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $this->markTestSkipped('تعذر الاتصال بالواجهة: ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (in_array($code, [404, 405], true)) {
            $this->markTestSkipped('مسار الاستيراد غير مفعل في هذه البيئة.');
        }

        // على الأقل يجب أن يرفض بوضوح (400 أو 415 أو 422)
        $this->assertTrue(in_array($code, [400, 415, 422], true), 'توقعنا رفض ملف غير صالح في الاستيراد.');
    }
}
