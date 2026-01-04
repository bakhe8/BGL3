<?php
/**
 * Smoke Tests - Basic Happy Path Testing
 * 
 * Purpose: Catch regressions during refactoring
 * Coverage: Critical user flows only
 * 
 * Run: php tests/SmokeTests.php
 */

require_once __DIR__ . '/../app/Support/autoload.php';

class SmokeTests
{
    private $baseUrl = 'http://localhost:8000';
    private $passed = 0;
    private $failed = 0;

    public function run()
    {
        echo "🧪 Running Smoke Tests...\n\n";

        // Test 1: index.php loads without errors
        $this->testIndexPageLoads();

        // Test 2: Can fetch a record
        $this->testGetRecordWorks();

        // Test 3: Statistics page loads
        $this->testStatisticsPageLoads();

        // Test 4: Settings page loads
        $this->testSettingsPageLoads();

        // Test 5: API endpoints respond
        $this->testApiEndpointsRespond();

        // Summary
        $this->printSummary();
    }

    private function testIndexPageLoads()
    {
        echo "Test 1: index.php loads without errors... ";

        $response = $this->httpGet('/index.php');

        if (
            $response['status'] === 200 &&
            strpos($response['body'], 'BGL System') !== false &&
            strpos($response['body'], 'Fatal error') === false
        ) {
            $this->pass();
        } else {
            $this->fail("Status: {$response['status']}");
        }
    }

    private function testGetRecordWorks()
    {
        echo "Test 2: get-record.php returns data... ";

        // First, get any guarantee ID from database
        $db = \App\Support\Database::connect();
        $stmt = $db->query('SELECT id FROM guarantees LIMIT 1');
        $guaranteeId = $stmt->fetchColumn();

        if (!$guaranteeId) {
            echo "⚠️  SKIP (no data)\n";
            return;
        }

        $response = $this->httpGet("/api/get-record.php?id=$guaranteeId");

        if (
            $response['status'] === 200 &&
            strlen($response['body']) > 100
        ) {
            $this->pass();
        } else {
            $this->fail("Status: {$response['status']}");
        }
    }

    private function testStatisticsPageLoads()
    {
        echo "Test 3: statistics.php loads... ";

        $response = $this->httpGet('/views/statistics.php');

        if (
            $response['status'] === 200 &&
            strpos($response['body'], 'إحصائيات') !== false
        ) {
            $this->pass();
        } else {
            $this->fail("Status: {$response['status']}");
        }
    }

    private function testSettingsPageLoads()
    {
        echo "Test 4: settings.php loads... ";

        $response = $this->httpGet('/views/settings.php');

        if (
            $response['status'] === 200 &&
            (strpos($response['body'], 'إعدادات') !== false ||
                strpos($response['body'], 'الموردين') !== false)
        ) {
            $this->pass();
        } else {
            $this->fail("Status: {$response['status']}");
        }
    }

    private function testApiEndpointsRespond()
    {
        echo "Test 5: Critical APIs respond... ";

        $endpoints = [
            '/api/get_suppliers.php',
            '/api/get_banks.php',
        ];

        $allPassed = true;
        foreach ($endpoints as $endpoint) {
            $response = $this->httpGet($endpoint);
            if ($response['status'] !== 200) {
                $allPassed = false;
                break;
            }
        }

        if ($allPassed) {
            $this->pass();
        } else {
            $this->fail("One or more APIs failed");
        }
    }

    private function httpGet($path)
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $body
        ];
    }

    private function pass()
    {
        echo "✅ PASS\n";
        $this->passed++;
    }

    private function fail($reason = '')
    {
        echo "❌ FAIL";
        if ($reason) {
            echo " - $reason";
        }
        echo "\n";
        $this->failed++;
    }

    private function printSummary()
    {
        echo "\n";
        echo "═══════════════════════════\n";
        echo "Summary:\n";
        echo "  Passed: {$this->passed}\n";
        echo "  Failed: {$this->failed}\n";

        if ($this->failed === 0) {
            echo "\n✅ All smoke tests passed!\n";
            exit(0);
        } else {
            echo "\n❌ Some tests failed. Fix before refactoring.\n";
            exit(1);
        }
    }
}

// Run tests
$tests = new SmokeTests();
$tests->run();
