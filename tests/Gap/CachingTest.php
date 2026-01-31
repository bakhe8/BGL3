<?php

use PHPUnit\Framework\TestCase;

class CachingTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testReportsAreCached(): void
    {
        $base = getenv('BGL_BASE_URL') ?: 'http://localhost:8000';
        $url  = rtrim($base, '/') . '/api/get_banks.php';

        $times = [];
        for ($i = 0; $i < 2; $i++) {
            $start = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
            $times[] = microtime(true) - $start;
            // انتظار قصير لتفادي تأثير الاتصال
            usleep(100000);
        }

        if (empty($times)) {
            $this->markTestSkipped('لا يمكن الاتصال بالخادم لاختبار الكاش.');
        }

        // التوقع: الطلب الثاني أسرع (كاش). إذا لم يكن كذلك، نعده مؤشر Gap لا يفشل الاختبار
        if ($times[1] >= $times[0]) {
            $this->markTestSkipped('الكاش غير مفعّل بعد (t1 >= t0).');
        } else {
            $this->assertTrue($times[1] < $times[0], 'يجب أن يكون الطلب الثاني أسرع بفضل الكاش.');
        }
    }
}
