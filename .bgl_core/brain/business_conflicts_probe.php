<?php
/**
 * Business Conflicts Probe (Real Data)
 *
 * Fetches recent guarantees from app DB and runs ConflictDetector using
 * real supplier suggestions (Authority) + normalized bank match.
 * Returns JSON for the Python Guardian.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Support\Database;
use App\Support\Normalizer;
use App\Repositories\BankRepository;
use App\Services\ConflictDetector;
use App\Services\Learning\AuthorityFactory;

header('Content-Type: application/json; charset=utf-8');

function safe_output(array $payload, int $code = 0): void {
    if ($code !== 0) {
        http_response_code(500);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit($code);
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        safe_output([
            'status' => 'ERROR',
            'message' => $err['message'] ?? 'fatal',
            'fatal' => true,
        ], 1);
    }
});

$inputJson = file_get_contents('php://stdin');
$payload = [];
if ($inputJson) {
    try {
        $payload = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        $payload = [];
    }
}

$limit = (int)($payload['limit'] ?? 8);
$offset = (int)($payload['offset'] ?? 0);
if ($limit <= 0 || $limit > 25) {
    $limit = 8;
}

try {
    $pdo = Database::connect();
    $stmt = $pdo->prepare("SELECT id, raw_data, imported_at FROM guarantees ORDER BY imported_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    safe_output([
        'status' => 'ERROR',
        'message' => 'DB error: ' . $e->getMessage(),
    ], 1);
}

if (!$rows) {
    safe_output([
        'status' => 'SUCCESS',
        'conflicts' => [],
        'total_scanned' => 0,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

$authority = AuthorityFactory::create();
$detector = new ConflictDetector();
$normalizer = new Normalizer();
$bankRepo = new BankRepository();

$results = [];
$errors = [];
$scanned = 0;

foreach ($rows as $row) {
    $rawData = $row['raw_data'] ?? '';
    $raw = is_string($rawData) ? json_decode($rawData, true) : (is_array($rawData) ? $rawData : []);
    if (!is_array($raw)) {
        continue;
    }
    $supplierName = (string)($raw['supplier'] ?? $raw['supplier_name'] ?? $raw['raw_supplier_name'] ?? '');
    $bankName = (string)($raw['bank'] ?? $raw['bank_name'] ?? $raw['raw_bank_name'] ?? '');
    $supplierName = trim($supplierName);
    $bankName = trim($bankName);
    if ($supplierName === '' && $bankName === '') {
        continue;
    }
    $scanned++;

    $suggestionDTOs = [];
    if ($supplierName !== '') {
        try {
            $suggestionDTOs = $authority->getSuggestions($supplierName);
        } catch (\Throwable $e) {
            $suggestionDTOs = [];
        }
    }

    $supplierSuggestions = array_map(function($dto) {
        return [
            'id' => $dto->supplier_id,
            'official_name' => $dto->official_name,
            'english_name' => $dto->english_name,
            'score' => $dto->confidence,
            'level' => $dto->level,
            'reason_ar' => $dto->reason_ar,
            'source' => $dto->primary_source ?? 'authority',
            'confirmation_count' => $dto->confirmation_count,
            'rejection_count' => $dto->rejection_count
        ];
    }, $suggestionDTOs);

    $bankCandidates = [];
    $bankNorm = $bankName !== '' ? $normalizer->normalizeBankName($bankName) : '';
    if ($bankNorm !== '') {
        try {
            $bank = $bankRepo->findByNormalizedName($bankNorm);
            if ($bank && $bank->id) {
                $bankCandidates[] = [
                    'id' => $bank->id,
                    'score' => 100,
                    'source' => 'normalized'
                ];
            }
        } catch (\Throwable $e) {
            // ignore bank match errors
        }
    }

    $candidates = [
        'supplier' => [
            'candidates' => $supplierSuggestions,
            'normalized' => $supplierName !== '' ? $normalizer->normalizeSupplierName($supplierName) : ''
        ],
        'bank' => [
            'candidates' => $bankCandidates,
            'normalized' => $bankNorm
        ]
    ];

    $recordContext = [
        'raw_supplier_name' => $supplierName,
        'raw_bank_name' => $bankName
    ];

    try {
        $conflicts = $detector->detect($candidates, $recordContext);
    } catch (\Throwable $e) {
        $conflicts = [];
        $errors[] = [
            'guarantee_id' => $row['id'] ?? null,
            'message' => $e->getMessage()
        ];
    }

    if (!empty($conflicts)) {
        $results[] = [
            'guarantee_id' => $row['id'] ?? null,
            'supplier' => $supplierName,
            'bank' => $bankName,
            'conflicts' => $conflicts
        ];
    }
}

safe_output([
    'status' => 'SUCCESS',
    'conflicts' => $results,
    'errors' => $errors,
    'total_scanned' => $scanned,
    'limit' => $limit,
    'offset' => $offset,
]);
