<?php

use PHPUnit\Framework\TestCase;
use App\Services\AuthManagerAgentService;

/**
 * @group fast
 */
class AuthManagerTest extends TestCase
{
    public function testDebugPingReturnsPong(): void
    {
        $svc = new AuthManagerAgentService();
        $this->assertSame('pong', $svc->debugPing());
    }
}
