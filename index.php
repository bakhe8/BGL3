<?php
// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

/**
 * BGL System v3.0 - Clean Rebuild
 * =====================================
 * 
 * Timeline-First approach with clean, maintainable code
 * Built from scratch following design system principles
 * 
 * @version 3.0.0
 * @date 2025-12-23
 * @author BGL Team
 */

// Load dependencies
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Services\LearningService;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

header('Content-Type: text/html; charset=utf-8');

// Connect to database
$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$decisionRepo = new GuaranteeDecisionRepository($db);

$learningRepo = new SupplierLearningRepository($db);
$supplierRepo = new SupplierRepository();
$learningService = new LearningService($learningRepo, $supplierRepo);

// Load Bank Repository
$bankRepo = new BankRepository();
$allBanks = $bankRepo->allNormalized(); // Get all banks for dropdown

// Get real data from database
$requestedId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$currentRecord = null;

if ($requestedId) {
    // Find the guarantee by ID directly
    $currentRecord = $guaranteeRepo->find($requestedId);
}

// If not found or no ID specified, get first NON-RELEASED record
if (!$currentRecord) {
    // Exclude released (is_locked=1) from default navigation
    $stmt = $db->prepare('
        SELECT g.id FROM guarantees g
        LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
        WHERE d.is_locked IS NULL OR d.is_locked = 0
        ORDER BY g.id ASC LIMIT 1
    ');
    $stmt->execute();
    $firstId = $stmt->fetchColumn();
    if ($firstId) {
        $currentRecord = $guaranteeRepo->find($firstId);
    }
}

// Get total count for progress (excluding released)
$stmtCount = $db->prepare('
    SELECT COUNT(*) FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    WHERE d.is_locked IS NULL OR d.is_locked = 0
');
$stmtCount->execute();
$totalRecords = (int)$stmtCount->fetchColumn();

// Calculate current index and find Previous/Next IDs
$currentIndex = 1;
$prevId = null;
$nextId = null;

if ($currentRecord) {
    // Find position of this guarantee in sorted list
    try {
        $stmt = $db->prepare('
            SELECT COUNT(*) as position
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            WHERE g.id < ? AND (d.is_locked IS NULL OR d.is_locked = 0)
        ');
        $stmt->execute([$currentRecord->id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentIndex = ($result['position'] ?? 0) + 1;
    } catch (\Exception $e) {
        // Keep currentIndex = 1 if error
    }
    
    // Get previous guarantee ID (excluding released)
    try {
        $stmt = $db->prepare('
            SELECT g.id FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            WHERE g.id < ? AND (d.is_locked IS NULL OR d.is_locked = 0)
            ORDER BY g.id DESC LIMIT 1
        ');
        $stmt->execute([$currentRecord->id]);
        $prev = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prev) $prevId = $prev['id'];
    } catch (\Exception $e) {
        // No previous
    }
    
    // Get next guarantee ID (excluding released)
    try {
        $stmt = $db->prepare('
            SELECT g.id FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            WHERE g.id > ? AND (d.is_locked IS NULL OR d.is_locked = 0)
            ORDER BY g.id ASC LIMIT 1
        ');
        $stmt->execute([$currentRecord->id]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($next) $nextId = $next['id'];
    } catch (\Exception $e) {
        // No next
    }
}

// If we have a record, prepare it
if ($currentRecord) {
    $raw = $currentRecord->rawData;
    
    $mockRecord = [
        'id' => $currentRecord->id,
        'session_id' => $raw['session_id'] ?? 0,
        'guarantee_number' => $currentRecord->guaranteeNumber ?? 'N/A',
        'supplier_name' => htmlspecialchars($raw['supplier'] ?? '', ENT_QUOTES),
        'bank_name' => htmlspecialchars($raw['bank'] ?? '', ENT_QUOTES),
        'amount' => is_numeric($raw['amount'] ?? 0) ? floatval($raw['amount'] ?? 0) : 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => htmlspecialchars($raw['contract_number'] ?? '', ENT_QUOTES),
        'type' => htmlspecialchars($raw['type'] ?? 'ابتدائي', ENT_QUOTES),
        'status' => 'pending',
        
        // Excel Raw Data (for hints display)
        'excel_supplier' => htmlspecialchars($raw['supplier'] ?? '', ENT_QUOTES),
        'excel_bank' => htmlspecialchars($raw['bank'] ?? '', ENT_QUOTES),
        
        // Decision fields (will be populated if exists)
        'supplier_id' => null,
        'bank_id' => null,
        'decision_source' => null,
        'confidence_score' => null,
        'decided_at' => null,
        'decided_by' => null,
        'is_locked' => false,
        'locked_reason' => null
    ];
    
    // Get decision if exists - Load ALL decision data
    $decision = $decisionRepo->findByGuarantee($currentRecord->id);
    if ($decision) {
        $mockRecord['status'] = $decision->status;
        $mockRecord['supplier_id'] = $decision->supplierId;
        $mockRecord['bank_id'] = $decision->bankId;
        $mockRecord['decision_source'] = $decision->decisionSource;
        $mockRecord['confidence_score'] = $decision->confidenceScore;
        $mockRecord['decided_at'] = $decision->decidedAt;
        $mockRecord['decided_by'] = $decision->decidedBy;
        $mockRecord['is_locked'] = (bool)$decision->isLocked;
        $mockRecord['locked_reason'] = $decision->lockedReason;
        
        // If supplier_id exists, get the official supplier name
        if ($decision->supplierId) {
            try {
                $supplier = $supplierRepo->find($decision->supplierId);
                if ($supplier) {
                    $mockRecord['supplier_name'] = $supplier->officialName;
                }
            } catch (\Exception $e) {
                // Keep Excel name if supplier not found
            }
        }
        
        // If bank_id exists, load bank name
        if ($decision->bankId) {
            try {
                $stmt = $db->prepare('SELECT arabic_name as official_name FROM banks WHERE id = ?');
                $stmt->execute([$decision->bankId]);
                $bank = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($bank) {
                    $mockRecord['bank_name'] = $bank['official_name'];
                }
            } catch (\Exception $e) {
                // Keep Excel name if bank not found
            }
        }
    }
    
    // === UI LOGIC PROJECTION: Status Reasons (Phase 1) ===
    // Get WHY status is what it is for user transparency
    $statusReasons = \App\Services\StatusEvaluator::getReasons(
        $mockRecord['supplier_id'] ?? null,
        $mockRecord['bank_id'] ?? null,
        [] // Conflicts will be added later in Phase 3
    );
    $mockRecord['status_reasons'] = $statusReasons;
    
    // Load timeline/history for this guarantee
    $mockTimeline = [];
    if ($currentRecord) {
        // Icon mapping for events
        $iconMap = [
            'import' => '📥',
            'decision' => '✅',
            'extension' => '🔄',
            'release' => '🔓',
            'reduction' => '📉',
            'manual_edit' => '✏️',
            'approve' => '✔️',
            'approved' => '✔️',
            'auto_matched' => '🤖',
            'modified' => '📝',
            'status_change' => '🔄',
            'update' => '📝'
        ];
        
        try {
            // 🆕 Load from guarantee_history table ONLY (unified timeline)
            $stmt = $db->prepare('
                SELECT * FROM guarantee_history 
                WHERE guarantee_id = ? 
                ORDER BY created_at DESC, id DESC
            ');
            $stmt->execute([$currentRecord->id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($history as $event) {
                $mockTimeline[] = [
                    'id' => 'history_' . $event['id'],
                    'event_id' => $event['id'],
                    'event_type' => $event['event_type'] ?? 'unknown',
                    'event_subtype' => $event['event_subtype'] ?? null,  // 🆕
                    'type' => $event['event_type'] ?? 'unknown',
                    'icon' => $iconMap[$event['event_type'] ?? 'unknown'] ?? '📋',
                    'action' => $event['event_type'] ?? 'unknown',
                    'date' => $event['created_at'],
                    'created_at' => $event['created_at'],
                    'event_details' => $event['event_details'] ?? null,
                    'change_reason' => '',
                    'description' => json_encode(json_decode($event['event_details'] ?? '{}', true)),
                    'user' => $event['created_by'] ?? 'النظام',
                    'snapshot' => json_decode($event['snapshot_data'] ?? '{}', true),
                    'snapshot_data' => $event['snapshot_data'] ?? '{}',
                    'source_badge' => in_array($event['created_by'] ?? 'system', ['system', 'System', 'System AI', 'النظام', 'بواسطة النظام']) ? '🤖 نظام' : '👤 مستخدم'
                ];
            }
        } catch (\Exception $e) {
            // If error, keep empty array
        }
        
        // Sort timeline by date (most recent first)
        usort($mockTimeline, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Sort all timeline events by date descending
        usort($mockTimeline, function($a, $b) {
            $dateA = $a['date'] ?? $a['created_at'] ?? '1970-01-01';
            $dateB = $b['date'] ?? $b['created_at'] ?? '1970-01-01';
            return strtotime($dateB) - strtotime($dateA);
        });
        
        // Add import event if no events found
        if (empty($mockTimeline)) {
            $mockTimeline[] = [
                'id' => 'import_1',
                'type' => 'import',
                'event_type' => 'import',
                'icon' => '📥',
                'action' => 'import',
                'date' => $currentRecord->importedAt,
                'created_at' => $currentRecord->importedAt,
                'change_reason' => 'استيراد من ' . $currentRecord->importSource,
                'description' => 'استيراد من ' . $currentRecord->importSource,
                'user' => htmlspecialchars($currentRecord->importedBy ?? 'النظام', ENT_QUOTES),
                'source_badge' => '🤖 نظام',
                'changes' => []
            ];
        }
    }
    
    // Load notes and attachments for this guarantee
    $mockNotes = [];
    $mockAttachments = [];
    
    if ($currentRecord) {
        try {
            // Load notes
            $stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ? ORDER BY created_at DESC');
            $stmt->execute([$currentRecord->id]);
            $mockNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Load attachments
            $stmt = $db->prepare('SELECT * FROM guarantee_attachments WHERE guarantee_id = ? ORDER BY created_at DESC');
            $stmt->execute([$currentRecord->id]);
            $mockAttachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // If error, keep empty arrays
        }
    }
} else {
    // No data in database - use empty state with no confusing values
    $mockRecord = [
        'id' => 0,
        'session_id' => 0,
        'guarantee_number' => 'لا توجد بيانات',
        'supplier_name' => '—',
        'bank_name' => '—',
        'amount' => 0,
        'expiry_date' => '—',
        'issue_date' => '—',
        'contract_number' => '—',
        'type' => '—',
        'status' => 'pending'
    ];
    
    $mockTimeline = [];
    $statusReasons = []; // Initialize empty array for loop
    $mockRecord['status_reasons'] = [];
}

// Get initial suggestions for the current record
$initialSupplierSuggestions = [];
if ($mockRecord['supplier_name']) {
    $initialSupplierSuggestions = $learningService->getSuggestions($mockRecord['supplier_name']);
}

// Map suggestions to frontend format
$formattedSuppliers = array_map(function($s) {
    return [
        'id' => $s['id'],
        'name' => $s['official_name'],
        'score' => $s['score'],
        'usage_count' => $s['usage_count'] ?? 0 
    ];
}, $initialSupplierSuggestions);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BGL System v3.0</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Letter Preview Styles (Classic Theme) -->
    <link rel="stylesheet" href="assets/css/letter.css">
    
    <!-- Alpine.js removed - using vanilla JavaScript instead -->
    
    <style>
        /* ═══════════════════════════════════════════════════════════════
           DESIGN SYSTEM - CSS VARIABLES
           ═══════════════════════════════════════════════════════════════ */
        :root {
            /* Colors */
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-neutral: #fafbfc;
            --bg-hover: #f8fafc;
            
            --border-primary: #e2e8f0;
            --border-light: #f1f5f9;
            --border-neutral: #cbd5e1;
            --border-focus: #3b82f6;
            
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            
            --accent-primary: #3b82f6;
            --accent-primary-hover: #2563eb;
            --accent-success: #16a34a;
            --accent-warning: #d97706;
            --accent-danger: #dc2626;
            
            /* Spacing */
            --space-xs: 4px;
            --space-sm: 8px;
            --space-md: 16px;
            --space-lg: 24px;
            --space-xl: 32px;
            
            --gap-card: 20px;
            --gap-section: 16px;
            --gap-small: 6px;
            
            /* Typography */
            --font-family: 'Tajawal', sans-serif;
            --font-size-xs: 10px;
            --font-size-sm: 11px;
            --font-size-base: 13px;
            --font-size-lg: 15px;
            --font-size-xl: 18px;
            
            --font-weight-normal: 400;
            --font-weight-medium: 500;
            --font-weight-semibold: 600;
            --font-weight-bold: 700;
            --font-weight-black: 800;
            
            /* Dimensions */
            --width-sidebar: 290px;
            --width-timeline: 360px;
            --height-top-bar: 56px;
            --height-record-header: 48px;
            --height-action-bar: 72px;
            
            /* Border Radius */
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-full: 50px;
            
            /* Shadows */
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 4px 20px rgba(0, 0, 0, 0.1);
            --shadow-focus: 0 0 0 3px rgba(59, 130, 246, 0.1);
            
            /* Transitions */
            --transition-fast: 0.15s ease;
            --transition-base: 0.2s ease;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE
           ═══════════════════════════════════════════════════════════════ */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html, body {
            font-family: var(--font-family);
            height: 100%;
            -webkit-font-smoothing: antialiased;
        }
        
        
        body {
            background: var(--bg-body);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           CUSTOM SCROLLBAR - Modern & Clean
           ═══════════════════════════════════════════════════════════════ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        
        /* Firefox Scrollbar */
        * {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           TOP BAR (Global)
           ═══════════════════════════════════════════════════════════════ */
        .top-bar {
            height: var(--height-top-bar);
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-lg);
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }
        
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: var(--font-weight-black);
            font-size: var(--font-size-xl);
            color: var(--text-primary);
        }
        
        .brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--accent-primary), #8b5cf6);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .global-actions {
            display: flex;
            gap: var(--space-sm);
        }
        
        .btn-global {
            padding: 8px 16px;
            background: transparent;
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-semibold);
            color: var(--text-muted);
            cursor: pointer;
            transition: all var(--transition-base);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .btn-global:hover {
            background: var(--bg-hover);
            border-color: var(--border-neutral);
            color: var(--text-primary);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           MAIN CONTAINER
           ═══════════════════════════════════════════════════════════════ */
        .app-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR (Left)
           ═══════════════════════════════════════════════════════════════ */
        .sidebar {
            width: var(--width-sidebar);
            background: var(--bg-card);
            border-right: 1px solid var(--border-primary);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .progress-container {
            height: 48px;
            background: var(--bg-card);
            padding: 0 var(--space-md);
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: var(--gap-small);
            border-bottom: 1px solid var(--border-primary);
        }
        
        .progress-bar {
            height: 6px;
            background: var(--border-primary);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-primary), #8b5cf6);
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: var(--font-size-sm);
            color: var(--text-muted);
        }
        
        .progress-percent {
            font-weight: var(--font-weight-bold);
            color: var(--accent-primary);
        }
        
        .sidebar-body {
            flex: 1;
            overflow-y: auto;
            padding: var(--space-md);
            background: var(--bg-secondary);
        }
        
        .sidebar-section {
            margin-bottom: var(--space-md);
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            padding: var(--space-md);
        }
        
        /* Input Toolbar */
        .input-toolbar {
            padding: 16px;
            border-bottom: 1px solid var(--border-primary);
            background: #ffffff;
        }
        .toolbar-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }
        .toolbar-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .btn-input {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid var(--border-primary);
            border-radius: 8px;
            padding: 10px 4px;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-secondary);
        }
        .btn-input:hover {
            background: #eff6ff;
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            transform: translateY(-1px);
        }
        .btn-input span:first-child {
            font-size: 18px;
        }
        .btn-input span:last-child {
            font-size: 11px;
            font-weight: 500;
        }
        
        .sidebar-section:last-child {
            margin-bottom: 0;
        }
        
        .sidebar-section-title {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-bold);
            color: var(--text-muted);
            margin-bottom: var(--space-md);
            padding-bottom: var(--space-sm);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: var(--gap-small);
        }
        
        /* Attachments */
        .attachment-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm);
            background: var(--bg-neutral);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
            margin-bottom: var(--gap-small);
        }
        
        .attachment-item:last-child {
            margin-bottom: 0;
        }
        
        .attachment-item:hover {
            background: var(--bg-hover);
            border-color: var(--border-neutral);
        }
        
        .attachment-icon {
            width: 32px;
            height: 32px;
            background: var(--bg-secondary);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .attachment-info {
            flex: 1;
            min-width: 0;
        }
        
        .attachment-name {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            color: var(--text-primary);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .attachment-meta {
            font-size: var(--font-size-xs);
            color: var(--text-light);
        }
        
        /* Notes */
        .note-item {
            padding: 10px;
            background: var(--bg-neutral);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-sm);
            border-right: 2px solid var(--border-neutral);
            margin-bottom: var(--gap-small);
        }
        
        .note-item:last-child {
            margin-bottom: 0;
        }
        
        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--gap-small);
        }
        
        .note-author {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            color: var(--text-secondary);
        }
        
        .note-time {
            font-size: var(--font-size-xs);
            color: var(--text-light);
        }
        
        .note-content {
            font-size: var(--font-size-sm);
            color: var(--text-muted);
            line-height: 1.5;
        }
        
        .add-note-btn {
            width: 100%;
            padding: var(--space-sm);
            background: var(--bg-card);
            border: 1px dashed var(--border-neutral);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            font-family: inherit;
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            cursor: pointer;
            transition: all var(--transition-fast);
            margin-top: var(--gap-small);
        }
        
        .add-note-btn:hover {
            border-color: var(--text-light);
            color: var(--text-secondary);
            background: var(--bg-neutral);
        }
        
        /* Note Input Box */
        .note-input-box {
            margin-top: var(--gap-small);
            background: white;
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            padding: 12px;
            box-shadow: var(--shadow-md);
        }

        /* Dropdown Menu */
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content {
            display: none;
            position: absolute;
            left: 0;
            min-width: 160px;
            z-index: 100;
            background: white;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            border-radius: 4px;
            top: 100%;
            border: 1px solid var(--border-neutral);
        }
        .dropdown-content a {
            color: var(--text-primary);
            padding: 10px 16px;
            text-decoration: none;
            display: block;
            font-size: 14px;
            text-align: right;
        }
        .dropdown-content a:hover { background-color: var(--bg-neutral); }
        .show { display: block; }
        
        .note-input-box textarea {
            width: 100%;
            min-height: 80px;
            padding: 8px;
            font-family: inherit;
            font-size: var(--font-size-sm);
            color: var(--text-primary);
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-sm);
            resize: vertical;
            transition: all var(--transition-base);
        }
        
        .note-input-box textarea:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: var(--shadow-focus);
        }
        
        .note-input-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            justify-content: flex-end;
        }
        
        .note-input-actions button {
            padding: 6px 12px;
            font-family: inherit;
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        
        .note-save-btn {
            background: var(--accent-primary);
            color: white;
            border: none;
        }
        
        .note-save-btn:hover {
            background: var(--accent-primary-hover);
        }
        
        .note-cancel-btn {
            background: var(--bg-secondary);
            color: var(--text-muted);
            border: 1px solid var(--border-primary);
        }
        
        .note-cancel-btn:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           CENTER SECTION
           ═══════════════════════════════════════════════════════════════ */
        .center-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* Record Header */
        .record-header {
            position: relative; /* Added for absolute positioning of nav controls */
            height: var(--height-record-header);
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-lg);
            flex-shrink: 0;
        }
        
        .record-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .record-title h1 {
            font-size: var(--font-size-lg);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
        }
        
        /* History Banner */
        .history-banner {
            background: #fffbeb;
            border-bottom: 1px solid #fcd34d;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: #92400e;
            animation: slideDown 0.3s ease-out;
        }
        .history-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .history-icon {
            font-size: 16px;
        }
        .history-label {
            font-weight: 700;
        }
        .history-date {
            font-family: monospace;
            font-weight: 600;
            margin: 0 4px;
            background: rgba(0,0,0,0.05);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .btn-return {
            background: #ffffff;
            border: 1px solid #f59e0b;
            color: #d97706;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-return:hover {
            background: #fef3c7;
            transform: translateY(-1px);
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: 3px 10px;
            font-size: var(--font-size-xs);
            font-weight: var(--font-weight-bold);
            border-radius: var(--radius-full);
            border: 1px solid;
        }
        
        .badge-pending {
            background: #fef3c7;
            color: #d97706;
            border-color: #fde68a;
        }
        
        .record-actions {
            display: flex;
            gap: var(--space-sm);
        }
        
        /* Content Wrapper */
        .content-wrapper {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           TIMELINE PANEL
           ═══════════════════════════════════════════════════════════════ */
        .timeline-panel {
            width: var(--width-timeline);
            background: var(--bg-card);
            border-right: 1px solid var(--border-primary);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .timeline-header {
            height: var(--height-record-header);
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-primary);
            border-left: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-md);
        }
        
        .timeline-title {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-bold);
            color: var(--text-secondary);
        }
        
        .timeline-count {
            font-size: var(--font-size-xs);
            color: var(--text-light);
        }
        
        .timeline-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        .timeline-list {
            position: relative;
            padding-right: 20px;
        }
        
        .timeline-line {
            position: absolute;
            right: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-dot {
            position: absolute;
            right: -17px;
            top: 6px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 3px solid #f8fafc;
            background: #94a3b8;
            box-shadow: 0 0 0 1px #e2e8f0;
            transition: all var(--transition-fast);
        }
        
        .timeline-dot.active {
            background: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6, 0 0 0 4px rgba(59, 130, 246, 0.2);
            transform: scale(1.1);
        }
        
        /* Event Card Styling */
        .event-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 12px;
            border: 1px solid var(--border-light);
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
        }

        .event-card:hover {
            border-color: var(--accent-primary);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
            transform: translateY(-1px);
        }
        
        .event-card.current {
            background: #f0f9ff;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 1px var(--accent-primary);
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .event-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.4;
        }

        .event-desc {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            line-height: 1.5;
        }

        /* Badge Styling */
        .event-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            background: var(--accent-primary);
            color: white;
            margin-bottom: 8px;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
        }

        /* Meta Data (Date & User) */
        .event-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 8px;
            border-top: 1px solid rgba(0,0,0,0.04);
            font-size: 11px;
            color: var(--text-light);
        }
        
        /* Diff View Styling */
        .diff-view {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            background: #f8fafc;
            padding: 6px 8px;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px dashed var(--border-neutral);
        }
        
        .diff-old {
            color: var(--text-muted);
            text-decoration: line-through;
            font-size: 11px;
        }
        
        .diff-arrow {
            color: var(--text-light);
            font-size: 10px;
        }
        
        .diff-new {
            color: var(--accent-success);
            font-weight: 600;
            font-size: 11px;
            background: rgba(22, 163, 74, 0.1);
            padding: 1px 4px;
            border-radius: 4px;
        }

        
        /* ═══════════════════════════════════════════════════════════════
           MAIN CONTENT
           ═══════════════════════════════════════════════════════════════ */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
            padding: var(--space-lg);
            background: var(--bg-body);
            min-height: 0;
        }
        
        .main-content > * {
            flex-shrink: 0;
        }
        
        /* Decision Card */
        .decision-card {
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }
        
        .card-header {
            height: 44px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-md);
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-bold);
            color: var(--text-secondary);
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .card-body {
            padding: var(--space-lg);
            display: flex;
            flex-direction: column;
            gap: var(--gap-card);
        }
        
        /* Field Group */
        
        /* Side-by-Side Grid for Supplier and Bank Fields */
        .fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        /* Visual separator between fields */
        .fields-grid > .field-group:first-child {
            border-left: 1px solid #e5e7eb;
            padding-left: 20px;
        }
        
        /* Responsive: Stack on smaller screens */
        @media (max-width: 1024px) {
            .fields-grid {
                grid-template-columns: 1fr;
            }
            
            /* Remove border on mobile */
            .fields-grid > .field-group:first-child {
                border-left: none;
                padding-left: 0;
            }
        }
        
        .field-group {
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }
        
        .field-row {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: var(--space-md);
        }
        
        .field-label {
            font-size: var(--font-size-base);
            font-weight: 600;
            color: var(--text-primary);
            min-width: 80px;
            flex-shrink: 0;
        }
        
        .field-input {
            flex: 1;
            padding: 10px 12px;
            font-family: inherit;
            font-size: var(--font-size-base);
            color: var(--text-primary);
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            transition: all var(--transition-base);
        }
        
        .field-input:hover {
            border-color: #93c5fd;
        }
        
        .field-input:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: var(--shadow-focus);
        }
        
        /* Chips */
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: var(--gap-small);
            margin-top: var(--space-xs);
            margin-right: calc(80px + var(--space-md));
        }
        
        .chip {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: 5px 10px;
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            border-radius: var(--radius-full);
            border: 1px solid;
            cursor: pointer;
            transition: all var(--transition-base);
            background: transparent;
            font-family: inherit;
        }
        
        .chip-selected {
            background: #dcfce7;
            color: var(--accent-success);
            border-color: #86efac;
        }
        
        .chip-candidate {
            background: var(--bg-secondary);
            color: var(--text-muted);
            border-color: var(--border-primary);
        }
        
        .chip-candidate:hover {
            background: #eff6ff;
            border-color: #93c5fd;
            color: var(--accent-primary);
        }
        
        .chip-source {
            font-size: var(--font-size-xs);
            opacity: 0.8;
        }
        
        .field-hint {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            margin-top: var(--gap-small);
            margin-right: calc(80px + var(--space-md));
            font-size: var(--font-size-xs);
            color: var(--text-light);
        }
        
        .hint-group {
            display: flex;
            align-items: center;
            gap: var(--gap-small);
        }
        
        .hint-label {
            color: var(--text-muted);
        }
        
        .hint-value {
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .hint-score {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--accent-success); /* تم التصحيح لاستخدام المتغير الصحيح */
            font-weight: 600;
        }

        .hint-divider {
            color: var(--border-neutral);
        }

        .hint-dot {
            width: 6px;
            height: 6px;
            background: var(--accent-success);
            border-radius: 50%;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: var(--space-md);
            padding: var(--space-md);
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: var(--space-xs);
        }
        
        .info-label {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .info-value {
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-semibold);
            color: var(--text-primary);
        }
        
        .info-value.highlight {
            color: var(--accent-success);
            font-size: var(--font-size-lg);
        }
        
        .card-footer {
            background: var(--bg-secondary);
            border-top: 1px solid var(--border-primary);
            padding: 14px var(--space-lg);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-sm);
            padding: 10px 18px;
            font-family: inherit;
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-bold);
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            transition: all var(--transition-base);
            white-space: nowrap;
        }
        
        .btn-sm {
            padding: 6px 14px;
            font-size: var(--font-size-sm);
        }
        
        .btn-primary {
            background: var(--accent-primary);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary:hover {
            background: var(--accent-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            background: var(--bg-card);
            color: var(--text-muted);
            border: 1px solid var(--border-primary);
        }
        
        .btn-secondary:hover {
            background: var(--bg-hover);
            border-color: var(--border-neutral);
            color: var(--text-primary);
        }
        
        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-primary);
        }
        
        .btn-ghost:hover {
            background: var(--bg-hover);
            border-color: var(--border-neutral);
        }
        
        /* Preview Section */
        /* Preview Section - Card Removed as per user request */
        .preview-section {
            margin-top: var(--space-lg);
            background: transparent;
            border: none;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
        }
        
        .preview-section:hover {
            box-shadow: none;
        }
        
        .preview-header-bar {
            height: auto;
            min-height: 48px;
            background: transparent;
            border-bottom: none;
            display: flex;
            align-items: center;
            justify-content: flex-end; /* Align print button to the end */
            padding: 0 20px;
            position: relative;
            margin-bottom: 20px;
        }
        
        .preview-title {
            display: none; /* Hidden as per request */
        }
        
        /* Print Icon Button */
        .print-icon-btn {
            position: absolute;
            top: 0;
            left: 0; /* Exact Top-Left corner */
            background: rgba(255, 255, 255, 0.5); /* Subtle overlay background */
            border: none;
            font-size: 20px; /* Slightly smaller for corner fit */
            cursor: pointer;
            color: #9ca3af;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom-right-radius: 8px; /* Rounded inner corner */
            transition: all 0.2s ease;
            z-index: 100;
        }
        
        .print-icon-btn:hover {
            color: #3b82f6; /* Blue on hover */
            background: #f3f4f6;
            transform: scale(1.1);
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }
        
        /* Preview Header Actions Container */
        .preview-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Style Toggle Button */
        .style-toggle-wrapper {
            position: relative;
        }
        
        .style-toggle-btn {
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            color: #6b7280;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 6px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        
        .style-toggle-btn:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
            color: #374151;
        }
        
        /* Style Toggle Menu */
        .style-toggle-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            min-width: 180px;
            z-index: 1000;
            display: none;
            overflow: hidden;
        }
        
        .style-toggle-menu.show {
            display: block;
        }
        
        .style-option {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-family: inherit;
            color: #374151;
            text-align: right;
            transition: background 0.15s;
        }
        
        .style-option:hover {
            background: #f3f4f6;
        }
        
        .style-option.active {
            background: #eff6ff;
            color: #1d4ed8;
        }
        
        .style-option .option-icon {
            font-size: 16px;
        }
        
        .style-option .option-text {
            flex: 1;
        }
        
        .style-option .option-check {
            color: #3b82f6;
            font-weight: bold;
            opacity: 0;
        }
        
        .style-option.active .option-check {
            opacity: 1;
        }
        
        .preview-body {
            padding: 0; /* Remove padding around the letter */
            background: transparent;
            display: block; /* Allow normal flow */
            min-height: auto;
            text-align: center; /* Center the letter */
        }
        
        /* Letter paper styles moved to assets/css/print-preview.css */
        
        .letter-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border-primary);
        }
        
        .letter-to {
            font-weight: var(--font-weight-bold);
            font-size: var(--font-size-lg);
            color: var(--text-primary);
            margin-bottom: var(--space-sm);
        }
        
        .letter-greeting {
            color: var(--text-muted);
            font-size: var(--font-size-base);
        }
        
        .letter-body {
            margin: var(--space-lg) 0;
        }
        
        .letter-body p {
            margin-bottom: var(--space-md);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           ACTION BAR (Bottom)
           ═══════════════════════════════════════════════════════════════ */
        .action-bar {
            height: var(--height-action-bar);
            background: var(--bg-card);
            border-top: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-lg);
            box-shadow: 0 -1px 3px rgba(0, 0, 0, 0.05);
            flex-shrink: 0;
        }
        
        .primary-actions {
            display: flex;
            gap: 12px;
        }
        
        
        /* ═══════════════════════════════════════════════════════════════
           PREVIEW BLOCKED STATE
           ═══════════════════════════════════════════════════════════════ */
        .preview-blocked-section {
            background: #f3f4f6;
            padding: 20px;
            direction: rtl;
        }
        
        .preview-blocked-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            max-width: 600px;
            margin: 0 auto;
            overflow: hidden;
        }
        
        .blocked-header {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            padding: 24px;
            text-align: center;
            border-bottom: 2px solid #f59e0b;
        }
        
        .blocked-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        
        .blocked-header h3 {
            margin: 0;
            color: #92400e;
            font-size: 20px;
            font-weight: 600;
        }
        
        .blocked-body {
            padding: 24px;
        }
        
        .blocked-message {
            text-align: center;
            color: #6b7280;
            font-size: 15px;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        
        .requirements-section {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        
        .requirements-section h4 {
            margin: 0 0 12px 0;
            color: #92400e;
            font-size: 14px;
            font-weight: 600;
        }
        
        .requirements-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .requirement-icon {
            font-size: 16px;
        }
        
        .requirement-text {
            color: #374151;
            font-weight: 500;
        }
        
        .blocked-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px;
            background: #eff6ff;
            border: 1px solid #3b82f6;
            border-radius: 6px;
            font-size: 13px;
            color: #1e40af;
        }
        
        .hint-icon {
            font-size: 18px;
        }
        
        /* Loading state */
        .loading {
            display: none !important;
        }
    </style>
</head>
<body>
    
    <!-- Hidden File Input for Excel Import -->
    <input type="file" id="hiddenFileInput" accept=".xlsx,.xls" style="display: none;">
    
    <!-- Top Bar (Global) -->
    <header class="top-bar">
        <div class="brand">
            <div class="brand-icon">&#x1F4CB;</div>
            <span>نظام إدارة الضمانات</span>
        </div>
        <nav class="global-actions">

            <a href="views/statistics.php" class="btn-global">&#x1F4CA; إحصائيات</a>
            <a href="views/settings.php" class="btn-global">&#x2699; إعدادات</a>
        </nav>
    </header>

    <!-- Main Container -->
    <div class="app-container">
        
        <!-- Center Section -->
        <div class="center-section">
            
            <!-- Record Header -->
            <header class="record-header">
                <div class="record-title">
                    <h1>ضمان رقم <span id="guarantee-number-display"><?= htmlspecialchars($mockRecord['guarantee_number']) ?></span></h1>
                    <?php
                        // Display status badge based on actual status
                        $statusClass = ($mockRecord['status'] === 'approved') ? 'badge-approved' : 'badge-pending';
                        $statusText = ($mockRecord['status'] === 'approved') ? 'جاهز' : 'يحتاج قرار';
                    ?>
                    <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                </div>
                
                <!-- Navigation Controls -->
                <div class="navigation-controls" style="display: flex; align-items: center; gap: 16px;">
                    <button class="btn btn-ghost btn-sm" 
                            data-action="previousRecord" 
                            data-id="<?= $prevId ?? '' ?>"
                            <?= !$prevId ? 'disabled style="opacity:0.3;cursor:not-allowed;"' : '' ?>>
                        ← السابق
                    </button>
                    
                    <span class="record-position" style="font-size: 14px; font-weight: 600; color: var(--text-secondary); white-space: nowrap;">
                        <?= $currentIndex ?> / <?= $totalRecords ?>
                    </span>
                    
                    <button class="btn btn-ghost btn-sm" 
                            data-action="nextRecord"
                            data-id="<?= $nextId ?? '' ?>"
                            <?= !$nextId ? 'disabled style="opacity:0.3;cursor:not-allowed;"' : '' ?>>
                        التالي →
                    </button>
                </div>
                


                    






            </header>

            <!-- Content Wrapper -->
            <div class="content-wrapper">
                
                <!-- Timeline Panel - Using Partial -->
                <?php 
                $timeline = $mockTimeline;
                require __DIR__ . '/partials/timeline-section.php'; 
                ?>

                <!-- Main Content -->
                <main class="main-content">
                    <!-- Decision Card -->
                    <div class="decision-card">
                        
                        <?php
                        // Prepare data for record-form partial
                        $record = $mockRecord;
                        $guarantee = $currentRecord; // For rawData access
                        $supplierMatch = [
                            'suggestions' => $formattedSuppliers,
                            'score' => !empty($formattedSuppliers) ? $formattedSuppliers[0]['score'] : 0
                        ];
                        
                        // Load banks - now using real data!
                        $banks = $allBanks;
                        
                        // Try to find matching bank using intelligent detection
                        $bankMatch = [];
                        if (!empty($mockRecord['bank_id'])) {
                            // If decision has bank_id, use it
                            foreach ($allBanks as $bank) {
                                if ($bank['id'] == $mockRecord['bank_id']) {
                                    $bankMatch = [
                                        'id' => $bank['id'],
                                        'name' => $bank['official_name'],
                                        'score' => 100
                                    ];
                                    break;
                                }
                            }
                        } else {
                            // Use direct bank matching with BankNormalizer
                            $excelBank = trim($mockRecord['excel_bank'] ?? '');
                            if ($excelBank) {
                                try {
                                    $normalized = \App\Support\BankNormalizer::normalize($excelBank);
                                    $stmt = $db->prepare("
                                        SELECT b.id, b.arabic_name as name
                                        FROM banks b
                                        JOIN bank_alternative_names a ON b.id = a.bank_id
                                        WHERE a.normalized_name = ?
                                        LIMIT 1
                                    ");
                                    $stmt->execute([$normalized]);
                                    $bank = $stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    if ($bank) {
                                        $bankMatch = [
                                            'id' => $bank['id'],
                                            'name' => $bank['name'],
                                            'score' => 100 // Perfect match - hide other banks
                                        ];
                                    }
                                } catch (\Exception $e) {
                                    // Fallback if matching fails
                                    error_log("Bank matching error: " . $e->getMessage());
                                }
                            }
                        }
                        
                        $isHistorical = false;
                        
                        // Include the Alpine-free record form partial
                        require __DIR__ . '/partials/record-form.php';
                        ?>
                    </div>

                    <!-- Preview Section - Lifecycle Gate -->
                    <?php if ($mockRecord['status'] === 'approved'): ?>
                        <!-- ✅ Status: READY - Preview Allowed -->
                        <?php require __DIR__ . '/partials/preview-section.php'; ?>
                    <?php else: ?>
                        <!-- 🔒 Status: PENDING - Preview Blocked -->
                        <div class="preview-blocked-section" id="preview-section">
                            <div class="preview-blocked-card">
                                <div class="blocked-header">
                                    <div class="blocked-icon">🔒</div>
                                    <h3>المعاينة غير متاحة حالياً</h3>
                                </div>
                                
                                <div class="blocked-body">
                                    <p class="blocked-message">
                                        لا يمكن معاينة الخطاب قبل اكتمال جميع متطلبات الضمان
                                    </p>
                                    
                                    <div class="requirements-section">
                                        <h4>المتطلبات الناقصة:</h4>
                                        <div class="requirements-list">
                                            <?php foreach ($statusReasons as $reason): ?>
                                                <?php if ($reason['severity'] === 'error'): ?>
                                                    <div class="requirement-item">
                                                        <span class="requirement-icon">❌</span>
                                                        <span class="requirement-text"><?= $reason['message_ar'] ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="blocked-hint">
                                        <span class="hint-icon">💡</span>
                                        <span>بعد اكتمال البيانات، ستظهر المعاينة تلقائياً</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </main>

            </div>
        </div>

        <!-- Sidebar (Left) -->
        <aside class="sidebar">
            
            <!-- Input Actions (New Proposal) -->
            <div class="input-toolbar">
                <div class="toolbar-label">إدخال جديد</div>
                <div class="toolbar-actions">
                    <button class="btn-input" title="إدخال يدوي" data-action="showManualInput">
                        <span>&#x270D;</span>
                        <span>يدوي</span>
                    </button>
                    <button class="btn-input" title="رفع ملف Excel" data-action="showImportModal">
                        <span>&#x1F4CA;</span>
                        <span>ملف</span>
                    </button>
                    <button class="btn-input" title="لصق بيانات" data-action="showPasteModal">
                        <span>&#x1F4CB;</span>
                        <span>لصق</span>
                    </button>
                </div>
                <!-- Hidden Input for Import -->
                <input type="file" id="hiddenFileInput" style="display: none;" accept=".xlsx,.xls,.csv" />
            </div>

            <!-- Progress -->
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" :style="`width: ${progress}%`"></div>
                </div>
                <div class="progress-text">
                    <span>سجل <span x-text="currentIndex"></span> من <span x-text="totalRecords"></span></span>
                    <span class="progress-percent" x-text="`${progress}%`"></span>
                </div>
            </div>
            
            <!-- Sidebar Body -->
            <div class="sidebar-body">
                <!-- Notes Section -->
                <div class="sidebar-section" id="notesSection">
                    <div class="sidebar-section-title">
                        📝 الملاحظات
                    </div>
                    
                    <!-- Notes List -->
                    <div id="notesList">
                        <?php if (empty($mockNotes)): ?>
                            <div id="emptyNotesMessage" style="text-align: center; color: var(--text-light); font-size: var(--font-size-sm); padding: 16px 0;">
                                لا توجد ملاحظات
                            </div>
                        <?php else: ?>
                            <?php foreach ($mockNotes as $note): ?>
                                <div class="note-item">
                                    <div class="note-header">
                                        <span class="note-author"><?= htmlspecialchars($note['created_by'] ?? 'مستخدم') ?></span>
                                        <span class="note-time"><?= substr($note['created_at'] ?? '', 0, 16) ?></span>
                                    </div>
                                    <div class="note-content"><?= htmlspecialchars($note['content'] ?? '') ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Note Input Box -->
                    <div id="noteInputBox" class="note-input-box" style="display: none;">
                        <textarea id="noteTextarea" placeholder="أضف ملاحظة..."></textarea>
                        <div class="note-input-actions">
                            <button onclick="cancelNote()" class="note-cancel-btn">
                                إلغاء
                            </button>
                            <button onclick="saveNote()" class="note-save-btn">
                                حفظ
                            </button>
                        </div>
                    </div>
                    
                    <!-- Add Note Button -->
                    <button id="addNoteBtn" onclick="showNoteInput()" class="add-note-btn">
                        + إضافة ملاحظة
                    </button>
                </div>
                
                <!-- Attachments Section -->
                <div class="sidebar-section" style="margin-top: 24px;">
                    <div class="sidebar-section-title">
                        📎 المرفقات
                    </div>
                    
                    <!-- Upload Button -->
                    <label class="add-note-btn" style="cursor: pointer; display: inline-block; width: 100%; text-align: center;">
                        <input type="file" id="fileInput" style="display: none;" onchange="uploadFile(event)">
                        + رفع ملف
                    </label>
                    
                    <!-- Attachments List -->
                    <div id="attachmentsList">
                        <?php if (empty($mockAttachments)): ?>
                            <div id="emptyAttachmentsMessage" style="text-align: center; color: var(--text-light); font-size: var(--font-size-sm); padding: 16px 0;">
                                لا توجد مرفقات
                            </div>
                        <?php else: ?>
                            <?php foreach ($mockAttachments as $file): ?>
                                <div class="note-item" style="display: flex; align-items: center; gap: 12px;">
                                    <div style="font-size: 24px;">📄</div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div class="note-content" style="margin: 0; font-weight: 500;"><?= htmlspecialchars($file['file_name'] ?? 'ملف') ?></div>
                                        <div class="note-time"><?= substr($file['created_at'] ?? '', 0, 10) ?></div>
                                    </div>
                                    <a href="/V3/storage/<?= htmlspecialchars($file['file_path'] ?? '') ?>" 
                                       target="_blank" 
                                       style="color: var(--text-light); text-decoration: none; font-size: 18px; padding: 4px;"
                                       title="تحميل">
                                        ⬇️
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </aside>

    </div>

    <!-- Modals - Using existing partials -->
    <?php require __DIR__ . '/partials/manual-entry-modal.php'; ?>
    <?php require __DIR__ . '/partials/paste-modal.php'; ?>

    <!-- JavaScript - Vanilla Controller (No Alpine.js) -->
    <script src="public/js/main.js?v=<?= time() ?>"></script>
    <script src="public/js/input-modals.controller.js?v=<?= time() ?>"></script>
    <script src="public/js/timeline.controller.js?v=<?= time() ?>"></script>
    <script src="public/js/records.controller.js?v=<?= time() ?>"></script>
    
    <?php if (!empty($mockRecord['is_locked'])): ?>
    <!-- Released Guarantee: Read-Only Mode -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show released banner
            const banner = document.createElement('div');
            banner.id = 'released-banner';
            banner.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between; 
                            background: #fee2e2; border: 2px solid #ef4444; border-radius: 8px; 
                            padding: 12px 16px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 20px;">🔒</span>
                        <div>
                            <div style="font-weight: 600; color: #991b1b;">ضمان مُفرج عنه</div>
                            <div style="font-size: 12px; color: #7f1d1d;">هذا الضمان خارج التدفق التشغيلي - للعرض فقط</div>
                        </div>
                    </div>
                </div>
            `;
            
            const recordForm = document.querySelector('.decision-card, .card');
            if (recordForm && recordForm.parentNode) {
                recordForm.parentNode.insertBefore(banner, recordForm);
            }
            
            // Disable all inputs
            const inputs = document.querySelectorAll('#supplierInput, #bankNameInput, #bankSelect');
            inputs.forEach(input => {
                input.disabled = true;
                input.style.opacity = '0.7';
                input.style.cursor = 'not-allowed';
            });
            
            // Disable action buttons
            const buttons = document.querySelectorAll('[data-action="extend"], [data-action="reduce"], [data-action="release"], [data-action="save-next"], [data-action="saveAndNext"]');
            buttons.forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            });
            
            // Hide suggestions
            const suggestions = document.getElementById('supplier-suggestions');
            if (suggestions) suggestions.style.display = 'none';
            
            console.log('🔒 Released guarantee - Read-only mode enabled');
        });
    </script>
    <?php endif; ?>

    <script>
        // Toast notification system
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'error' ? '#dc2626' : type === 'success' ? '#16a34a' : '#3b82f6'};
                color: white;
                padding: 16px 24px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                font-family: 'Tajawal', sans-serif;
                font-size: 14px;
                max-width: 400px;
                animation: slideIn 0.3s ease;
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Notes functionality - Vanilla JS
        function showNoteInput() {
            document.getElementById('noteInputBox').style.display = 'block';
            document.getElementById('addNoteBtn').style.display = 'none';
            document.getElementById('noteTextarea').focus();
        }
        
        function cancelNote() {
            document.getElementById('noteInputBox').style.display = 'none';
            document.getElementById('addNoteBtn').style.display = 'block';
            document.getElementById('noteTextarea').value = '';
        }
        
        async function saveNote() {
            const content = document.getElementById('noteTextarea').value.trim();
            if (!content) return;
            
            try {
                const res = await fetch('api/save-note.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        guarantee_id: <?= $mockRecord['id'] ?? 0 ?>,
                        content: content
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('تم حفظ الملاحظة بنجاح', 'success');
                    // Reload page to show new note
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast('فشل حفظ الملاحظة: ' + (data.error || 'خطأ غير معروف'), 'error');
                }
            } catch(e) { 
                console.error('Error saving note:', e);
                showToast('حدث خطأ أثناء حفظ الملاحظة', 'error');
            }
        }
        
        // Attachments functionality
        async function uploadFile(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('file', file);
            formData.append('guarantee_id', <?= $mockRecord['id'] ?? 0 ?>);
            
            try {
                const res = await fetch('api/upload-attachment.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    showToast('تم رفع الملف بنجاح', 'success');
                    // Reload page to show new attachment
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast('فشل رفع الملف: ' + (data.error || 'خطأ غير معروف'), 'error');
                }
            } catch(err) {
                console.error('Error uploading file:', err);
                showToast('حدث خطأ أثناء رفع الملف', 'error');
            }
            event.target.value = ''; // Reset input
        }
        
        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(400px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(400px); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        // ========================
        // تحويل الأرقام إلى هندية
        // ========================
        const EASTERN_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        
        function toEasternDigits(text) {
            if (!text) return text;
            return String(text).replace(/\d/g, digit => EASTERN_DIGITS[Number(digit)]);
        }
        
        function convertDigitsInNode(root) {
            if (!root) return;
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const elementsToConvert = [];
            const elementsToKeepEnglish = new Set();
            
            while (walker.nextNode()) {
                const current = walker.currentNode;
                const parent = current.parentElement;
                // Skip script, style, and footer elements
                if (parent && (parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE')) {
                    continue;
                }
                if (parent && parent.closest('.sheet-footer')) {
                    continue;
                }
                
                // Check if element has lang="en" and contains letters (mixed content)
                if (parent && parent.getAttribute('lang') === 'en') {
                    const fullText = parent.textContent.trim();
                    // If contains any letters (A-Z), keep as English - don't convert digits
                    if (/[A-Za-z]/.test(fullText)) {
                        elementsToKeepEnglish.add(parent);
                        continue;
                    }
                }
                
                if (/\d/.test(current.nodeValue)) {
                    elementsToConvert.push({node: current, parent: parent});
                }
            }
            
            // Convert digits for elements that should be Arabic
            elementsToConvert.forEach(function(item) {
                // Skip if parent is marked to keep English
                if (item.parent && elementsToKeepEnglish.has(item.parent)) {
                    return;
                }
                item.node.nodeValue = toEasternDigits(item.node.nodeValue);
                
                // Remove lang=en from parent if it was pure numbers
                if (item.parent && item.parent.getAttribute('lang') === 'en') {
                    const text = item.parent.textContent.trim();
                    // If text is only Arabic numerals now, remove lang=en
                    if (/^[٠-٩\s\.\-\/\(\)]+$/.test(text)) {
                        item.parent.removeAttribute('lang');
                    }
                }
            });
        }
        
        function applyEasternDigitsToPreview() {
            const target = document.getElementById('primaryLetter');
            if (target) {
                convertDigitsInNode(target);
            }
        }
        
        // Function to detect and mark English text elements
        function markEnglishText(root) {
            if (!root) return;
            
            // Regex for detecting primarily English text (Latin characters)
            const englishPattern = /^[A-Za-z0-9\s\.\,\-\_\@\#\$\%\&\*\(\)\[\]\{\}\:\;\"\'\<\>\/\\\+\=\!\?\|\~\`]+$/;
            
            // Get all text-containing elements
            const elements = root.querySelectorAll('span, div, p');
            elements.forEach(function(el) {
                // Skip if already has lang attribute or is a container with mixed content
                if (el.hasAttribute('lang')) return;
                if (el.children.length > 0) return;
                
                const text = el.textContent.trim();
                if (text && englishPattern.test(text)) {
                    el.setAttribute('lang', 'en');
                }
            });
            
            // Also wrap punctuation symbols in mixed text
            wrapPunctuationAsEnglish(root);
        }
        
        // Function to wrap punctuation ( ) - . / in spans with lang=en
        function wrapPunctuationAsEnglish(root) {
            if (!root) return;
            
            const punctuationPattern = /([().\-\/:@,])/g;
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const nodesToProcess = [];
            
            while (walker.nextNode()) {
                const node = walker.currentNode;
                const parent = node.parentElement;
                
                // Skip if parent already has lang=en or is script/style
                if (parent && parent.getAttribute('lang') === 'en') continue;
                if (parent && (parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE')) continue;
                
                // Reset lastIndex to fix alternating behavior with global regex
                punctuationPattern.lastIndex = 0;
                if (punctuationPattern.test(node.nodeValue)) {
                    nodesToProcess.push(node);
                }
            }
            
            nodesToProcess.forEach(function(node) {
                const text = node.nodeValue;
                const fragment = document.createDocumentFragment();
                let lastIndex = 0;
                
                text.replace(punctuationPattern, function(match, p1, offset) {
                    // Add text before punctuation
                    if (offset > lastIndex) {
                        fragment.appendChild(document.createTextNode(text.substring(lastIndex, offset)));
                    }
                    // Add punctuation in span with lang=en
                    const span = document.createElement('span');
                    span.setAttribute('lang', 'en');
                    span.textContent = match;
                    fragment.appendChild(span);
                    lastIndex = offset + match.length;
                    return match;
                });
                
                // Add remaining text
                if (lastIndex < text.length) {
                    fragment.appendChild(document.createTextNode(text.substring(lastIndex)));
                }
                
                node.parentNode.replaceChild(fragment, node);
            });
        }
        
        // Apply when preview is shown
        // Apply features when preview is shown - Enforcing Classic Theme
        // Apply features on load - Preview is always visible
        document.addEventListener('DOMContentLoaded', function() {
            const previewSection = document.getElementById('preview-section');
            if (previewSection) {
                // Permanently add letter-preview class for styling
                previewSection.classList.add('letter-preview');

                // Execute conversions immediately and after short delay (for any async content)
                const runConversions = () => {
                    const letterPaper = document.querySelector('.letter-paper');
                    if (letterPaper) {
                        convertDigitsInNode(letterPaper);
                        markEnglishText(letterPaper);
                    }
                };
                
                runConversions();
                // Run again after a slight delay to catch any dynamic updates
                setTimeout(runConversions, 500);
            }
        });
            


    </script>

    <script src="/public/js/main.js?v=<?= time() ?>"></script>
    <script src="/public/js/input-modals.controller.js?v=<?= time() ?>"></script>
    <script src="/public/js/timeline.controller.js?v=<?= time() ?>"></script>
    <script src="/public/js/records.controller.js?v=<?= time() ?>"></script>
</body>
</html>
