<?php
/**
 * Debug Extend API
 */
require_once __DIR__ . '/app/Support/autoload.php';
use App\Services\ActionService;
use App\Repositories\GuaranteeActionRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;
use App\Support\Database;

try {
    $guaranteeId = 351; // Hardcoded from user screenshot
    echo "Testing Extend for ID: $guaranteeId\n";
    
    $db = Database::connect();
    $actionRepo = new GuaranteeActionRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    $service = new ActionService($actionRepo, $decisionRepo, $guaranteeRepo);
    
    $result = $service->createExtension($guaranteeId);
    echo "Extension Created: Action ID " . $result['action_id'] . "\n";
    
    $service->issueExtension($result['action_id']);
    echo "Extension Issued\n";
    
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
