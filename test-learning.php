<?php
require_once 'app/Support/autoload.php';

use App\Support\Database;

// 1. Setup
$baseUrl = 'http://localhost:8000/V3/api';
echo "--- Starting Learning System Test ---\n";

function post($url, $data) {
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
            'ignore_errors' => true
        ]
    ];
    $context = stream_context_create($opts);
    return file_get_contents($url, false, $context);
}

function get($url) {
    return file_get_contents($url);
}

// 2. Create Manual Entry
echo "\n1. Creating Manual Entry...\n";
$entryData = [
    'guarantee_number' => 'TEST-' . uniqid(),
    'supplier' => 'Saudi Electric Company',
    'bank' => 'SNB',
    'amount' => 5000,
    'expiry_date' => '2025-12-31',
    'type' => 'Initial'
];
$resp1 = post($baseUrl . '/manual-entry.php', $entryData);
$json1 = json_decode($resp1, true);
echo "Result: " . ($json1['success'] ? "OK" : "FAIL") . "\n";
if (!$json1['success']) die(print_r($json1, true));
$guaranteeId = $json1['id'];

// 3. Check Suggestions (Should find it by fuzzy match)
echo "\n2. Checking Suggestions for 'Saudi Elec'...\n";
$resp2 = get($baseUrl . '/suggestions.php?raw=' . urlencode('Saudi Elec'));
echo "Raw Response: $resp2\n";
$json2 = json_decode($resp2, true);
echo "Suggestions Found: " . count($json2['suggestions']) . "\n";
print_r($json2['suggestions']);

// 4. Save Decision & Learn
echo "\n3. Saving Decision (Learning)...\n";
$decisionData = [
    'guarantee_id' => $guaranteeId,
    'supplier_id' => $json2['suggestions'][0]['id'] ?? 1, // Assume ID 1 if new
    'bank_id' => 1,
    'decision_source' => 'manual', // Pretend it was manual to trigger alias learning
    'supplier_name' => 'Saudi Electricity Company' // Official Name
];
$resp3 = post($baseUrl . '/save.php', $decisionData);
$json3 = json_decode($resp3, true);
echo "Result: " . ($json3['success'] ? "OK" : "FAIL") . "\n";

// 5. Check Suggestions again (Score should increase)
echo "\n4. Checking Suggestions again...\n";
$resp4 = get($baseUrl . '/suggestions.php?raw=' . urlencode('Saudi Elec'));
$json4 = json_decode($resp4, true);
print_r($json4['suggestions']);
