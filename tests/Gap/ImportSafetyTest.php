<?php

use PHPUnit\Framework\TestCase;

class ImportSafetyTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testRejectLargeImport(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/import.php';

        // نبني ملف كبير وهمي في الذاكرة
        $fakeCsv = str_repeat("row,data\n", 200000); // ~2MB+
        $tmp = tmpfile();
        fwrite($tmp, $fakeCsv);
        $meta = stream_get_meta_data($tmp);
        $filename = $meta['uri'];

        $post = [
            'file' => new CURLFile($filename, 'text/csv', 'large.csv'),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HEADER         => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            fclose($tmp);
            $this->markTestSkipped('لا يمكن الاتصال بالخادم: ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($tmp);

        if ($code === 0) {
            $this->markTestSkipped('لم يتم الحصول على استجابة من الخادم.');
        }

        $this->assertContains($code, [400, 422, 500], "يجب رفض الملفات الكبيرة أو غير المسموح بها بـ 400/422 (أو 500 مع رسالة واضحة)، الكود: $code");
    }
}
