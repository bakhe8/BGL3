<?php

use PHPUnit\Framework\TestCase;

/**
 * @group fast
 */
class RenamePipelinePlaybookTest extends TestCase
{
    public function testPlaybookDocumentedAndConfigPresent(): void
    {
        $this->assertFileExists('.bgl_core/brain/playbooks/rename_class.md');
        $this->assertFileExists('.bgl_core/config.yml');
    }
}
