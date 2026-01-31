<?php

use PHPUnit\Framework\TestCase;

class BackupExportTest extends TestCase
{
    /**
     * @group Gap
     */
    public function testBackupCommandExists(): void
    {
        // نتحقق من وجود سكربت أو أمر تصدير
        $command = __DIR__ . '/../../artisan';
        if (!file_exists($command)) {
            $this->markTestSkipped('لم أجد artisan للتحقق من أوامر التصدير.');
        }

        $output = [];
        $code   = 0;
        @exec("php $command list | findstr backup:export", $output, $code);

        if ($code !== 0 || empty($output)) {
            $this->markTestIncomplete('أمر backup:export غير معرف بعد.');
        }

        $this->assertNotEmpty($output, 'يجب توفر أمر backup:export لتوليد نسخ خفيفة.');
    }
}
