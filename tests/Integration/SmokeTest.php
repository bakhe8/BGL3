<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_environment_is_configured(): void
    {
        $this->assertSame('testing', getenv('APP_ENV'));
    }
}
