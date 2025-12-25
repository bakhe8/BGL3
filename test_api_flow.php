<?php
// Integration Test for Timeline APIs
// Uses curl to hit the local server

$baseUrl = 'http://localhost:8000/api';
$guaranteeId = 380; // Test ID

function sendPost($endpoint, $data) {
    global $baseUrl;
    $url = $baseUrl . '/' . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => $response];
}

echo "--- STARTING API INTEGRATION TEST ---\n";

// 1. Extend
echo "\n[1] Testing API: EXTEND...\n";
$newDate = date('Y-m-d', strtotime('+1 year'));
$res = sendPost('extend.php', ['guarantee_id' => $guaranteeId, 'new_expiry_date' => $newDate]);
echo "Status: " . $res['code'] . "\n";
if ($res['code'] !== 200) echo "Response: " . substr($res['body'], 0, 100) . "...\n";

// 2. Reduce
echo "\n[2] Testing API: REDUCE...\n";
$newAmount = 123.45;
$res = sendPost('reduce.php', ['guarantee_id' => $guaranteeId, 'new_amount' => $newAmount]);
echo "Status: " . $res['code'] . "\n";
if ($res['code'] !== 200) echo "Response: " . substr($res['body'], 0, 100) . "...\n";

// 3. Save Decision (Save Event)
echo "\n[3] Testing API: SAVE DECISION...\n";
$data = [
    'guarantee_id' => $guaranteeId,
    'supplier_id' => 1, // Assume existing
    'bank_id' => 1, // Assume existing
    'supplier_name' => 'Should Be Ignored By Recorder',
    'bank_name' => 'Should Be Ignored By Recorder'
];
$res = sendPost('save-and-next.php', $data);
echo "Status: " . $res['code'] . "\n";

// 4. Release
echo "\n[4] Testing API: RELEASE...\n";
$res = sendPost('release.php', ['guarantee_id' => $guaranteeId, 'reason' => 'API Integration Test']);
echo "Status: " . $res['code'] . "\n";
if ($res['code'] !== 200) echo "Response: " . substr($res['body'], 0, 100) . "...\n";

echo "\n--- API TEST COMPLETE ---\n";
