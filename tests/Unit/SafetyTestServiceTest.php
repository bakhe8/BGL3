<?php

use PHPUnit\Framework\TestCase;
use App\Services\SafetyTestService;

/**
 * @group fast
 */
class SafetyTestServiceTest extends TestCase
{
    public function testServiceExists(): void
    {
        $this->assertTrue(class_exists(SafetyTestService::class));
    }
}
