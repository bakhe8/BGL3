<?php
// Template: Critical PHPUnit test skeleton
// Placeholders: {{class_name}}, {{endpoint}}

use PHPUnit\Framework\TestCase;

class {{class_name}} extends TestCase
{
    public function test_endpoint_behaves()
    {
        $response = file_get_contents('{{endpoint}}'); // replace with real HTTP client
        $this->assertNotFalse($response, 'Endpoint unreachable');
    }
}
