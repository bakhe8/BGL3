<?php
// Debug script to check if bootstrap.php calculates KPIs correctly

// Mock $_SERVER to avoid undefined index errors if bootstrap uses them
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "1. Loading bootstrap.php...\n";
require_once __DIR__ . '/agentfrontend/bootstrap.php';

echo "\n2. Inspecting \$kpiCurrent array:\n";
var_dump($kpiCurrent);

echo "\n3. Inspecting \$domainMap['operational_kpis']:\n";
if (isset($domainMap['operational_kpis'])) {
    echo "Found " . count($domainMap['operational_kpis']) . " KPI definitions.\n";
} else {
    echo "❌ operational_kpis NOT found in domainMap.\n";
}

echo "\n4. Direct DB Check from PHP:\n";
$db = __DIR__ . '/.bgl_core/brain/knowledge.db';
if (file_exists($db)) {
    try {
        $pdo = new PDO("sqlite:$db");
        $count = $pdo->query("SELECT COUNT(*) FROM runtime_events")->fetchColumn();
        echo "DB Connection OK. Total events: $count\n";
    } catch (Exception $e) {
        echo "❌ DB Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ DB file not found at: $db\n";
}
