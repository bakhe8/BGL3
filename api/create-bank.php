<?php
/**
 * Create Bank - Unified API
 * 
 * Combines features from both add-bank.php and create_bank.php:
 * - Aliases support (from add-bank)
 * - Contact details support (from create_bank)
 * 
 * @version 2.0 (unified)
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Services\BankManagementService;
use App\Support\Validation;
use App\Models\AuditLog;
use App\Http\Requests\CreateBankRequest;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }

    $forceRl = getenv('BGL_FORCE_RATE_LIMIT') === '1';
    if ($forceRl || (getenv('APP_ENV') !== 'testing' && getenv('BGL_SKIP_RATE_LIMIT') !== '1' && PHP_SAPI !== 'cli-server')) {
        // Rate limit بسيط: 5 طلبات في 30 ثانية
        $rlDir = __DIR__ . '/../storage/cache';
        $rlFile = $rlDir . '/rl_create_bank.json';
        if (!is_dir($rlDir)) { mkdir($rlDir, 0755, true); }
        $now = time();
        $window = 30;
        $limit = 5;
        $bucket = ['ts' => $now, 'count' => 0];
        if (file_exists($rlFile)) {
            $bucket = json_decode(file_get_contents($rlFile), true) ?: $bucket;
            if (($now - ($bucket['ts'] ?? 0)) > $window) {
                $bucket = ['ts' => $now, 'count' => 0];
            }
        }
        $bucket['count'] += 1;
        if ($bucket['count'] > $limit) {
            // reset bucket after triggering
            $bucket = ['ts' => $now, 'count' => 0];
            file_put_contents($rlFile, json_encode($bucket));
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
            exit;
        }
        file_put_contents($rlFile, json_encode($bucket));
    }

    // Basic validation (FormRequest + shared bank rules)
    $validator = new CreateBankRequest();
    $errors = array_merge(
        $validator->validate($data),
        Validation::validateBank($data)
    );
    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    $db = Database::connect();
    
    $result = BankManagementService::create($db, $data);

    // Audit trail
    AuditLog::record('bank', $result['id'] ?? null, 'create', $data);
    
    echo json_encode($result);
    
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
