<?php
// Test get-record.php
$baseUrl = 'http://localhost:8000/api';

function sendGet($endpoint) {
    global $baseUrl;
    $url = $baseUrl . '/' . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close($ch); // Deprecated/Unnecessary with CurlHandle
    return ['code' => $httpCode, 'body' => $response];
}

echo "Testing get-record.php?index=1...\n";
$res = sendGet('get-record.php?index=1');
echo "Status: " . $res['code'] . "\n";
if ($res['code'] !== 200) {
    echo "Body: " . substr($res['body'], 0, 500) . "\n";
} else {
    echo "Success. Body length: " . strlen($res['body']) . "\n";
}
