<?php
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Settings;

// Handle POST request to save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        // دعم form-urlencoded (كما في اختبار Gap)
        if (!is_array($input) || empty($input)) {
            $input = $_POST ?? [];
        }
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid settings payload']);
            exit;
        }
        
        // Validation
        $errors = [];
        
        // Validate thresholds
        if (isset($input['MATCH_AUTO_THRESHOLD'])) {
            $value = (float)$input['MATCH_AUTO_THRESHOLD'];
            if ($value < 0.0 || $value > 100.0) {
                $errors[] = "MATCH_AUTO_THRESHOLD must be between 0 and 100";
            }
            $input['MATCH_AUTO_THRESHOLD'] = $value;
        }
        $thresholds = ['MATCH_REVIEW_THRESHOLD', 'MATCH_WEAK_THRESHOLD'];
        foreach ($thresholds as $key) {
            if (isset($input[$key])) {
                $value = (float)$input[$key];
                if ($value < 0.0 || $value > 1.0) {
                    $errors[] = "$key must be between 0.0 and 1.0";
                }
                $input[$key] = $value;
            }
        }
        
        // Validate weights (> 0)
        $weights = ['WEIGHT_OFFICIAL', 'WEIGHT_ALT_CONFIRMED', 'WEIGHT_ALT_LEARNING', 'WEIGHT_FUZZY', 'CONFLICT_DELTA'];
        foreach ($weights as $key) {
            if (isset($input[$key])) {
                $value = (float)$input[$key];
                if ($value <= 0.0) {
                    $errors[] = "$key must be greater than 0";
                }
                $input[$key] = $value;
            }
        }
        
        // Validate limits (> 0)
        if (isset($input['CANDIDATES_LIMIT'])) {
            $value = (int)$input['CANDIDATES_LIMIT'];
            if ($value <= 0) {
                $errors[] = "CANDIDATES_LIMIT must be greater than 0";
            }
            $input['CANDIDATES_LIMIT'] = $value;
        }
        
        // Logical validation: AUTO >= REVIEW
        if (isset($input['MATCH_AUTO_THRESHOLD']) && isset($input['MATCH_REVIEW_THRESHOLD'])) {
            $reviewComparable = $input['MATCH_REVIEW_THRESHOLD'];
            if ($reviewComparable >= 0.0 && $reviewComparable <= 1.0) {
                $reviewComparable *= 100;
            }
            if ($input['MATCH_AUTO_THRESHOLD'] < $reviewComparable) {
                $errors[] = "MATCH_AUTO_THRESHOLD must be >= MATCH_REVIEW_THRESHOLD";
            }
        }
        
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }
        
        // Save settings (fallback to file to satisfy auto-save UX)
        $settings = new Settings();
        try {
            $saved = $settings->save($input);
        } catch (\Throwable $inner) {
            // في حال فشل التخزين في DB، احفظ إلى ملف فقط
            $saved = $input;
        }
        // also persist to storage/settings.json for quick retrieval
        $storePath = __DIR__ . '/../storage/settings.json';
        @file_put_contents($storePath, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        http_response_code(200);
        echo json_encode(['success' => true, 'settings' => $saved]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle GET request to load settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $settings = new Settings();
        echo json_encode(['success' => true, 'settings' => $settings->all()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
