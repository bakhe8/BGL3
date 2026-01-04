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
use App\Services\Learning\AuthorityFactory;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
// LearningService removed - deprecated in Phase 4
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

header('Content-Type: text/html; charset=utf-8');

// Initialize database connection
$db = Database::connect();

// Get filter parameter for status filtering (Defined EARLY)
$statusFilter = $_GET['filter'] ?? 'all'; // all, ready, pending

$guaranteeRepo = new GuaranteeRepository($db);
$decisionRepo = new GuaranteeDecisionRepository($db);

// ✅ PHASE 4: LearningService removed - using UnifiedLearningAuthority directly where needed
$supplierRepo = new SupplierRepository();

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

// If not found or no ID specified, get first record matching the filter
if (!$currentRecord) {
    // Build query based on status filter
    $defaultRecordQuery = '
        SELECT g.id FROM guarantees g
        LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
        WHERE 1=1
    ';
    
    // Apply filter conditions
    if ($statusFilter === 'released') {
        // Show only released
        $defaultRecordQuery .= ' AND d.is_locked = 1';
    } else {
        // Exclude released for other filters
        $defaultRecordQuery .= ' AND (d.is_locked IS NULL OR d.is_locked = 0)';
        
        // Apply specific status filter
        if ($statusFilter === 'ready') {
            $defaultRecordQuery .= ' AND d.id IS NOT NULL';
        } elseif ($statusFilter === 'pending') {
            $defaultRecordQuery .= ' AND d.id IS NULL';
        }
        // 'all' filter has no additional conditions
    }
    
    $defaultRecordQuery .= ' ORDER BY g.id ASC LIMIT 1';
    
    $stmt = $db->prepare($defaultRecordQuery);
    $stmt->execute();
    $firstId = $stmt->fetchColumn();
    if ($firstId) {
        $currentRecord = $guaranteeRepo->find($firstId);
    }
}

// Get navigation information using NavigationService
$navInfo = \App\Services\NavigationService::getNavigationInfo(
    $db,
    $currentRecord ? $currentRecord->id : null,
    $statusFilter
);

$totalRecords = $navInfo['totalRecords'];
$currentIndex = $navInfo['currentIndex'];
$prevId = $navInfo['prevId'];
$nextId = $navInfo['nextId'];

// Get import statistics (ready vs pending vs released)
// Note: Stats always show ALL counts regardless of filter
$importStats = \App\Services\StatsService::getImportStats($db);
// Update total to exclude released for display consistency with filters
$displayTotal = $importStats['ready'] + $importStats['pending'];


// If we have a record, prepare it
if ($currentRecord) {
    $raw = $currentRecord->rawData;
    
    $mockRecord = [
        'id' => $currentRecord->id,
        'session_id' => $raw['session_id'] ?? 0,
        'guarantee_number' => $currentRecord->guaranteeNumber ?? 'N/A',
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'amount' => is_numeric($raw['amount'] ?? 0) ? floatval($raw['amount'] ?? 0) : 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'ابتدائي',
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
        // Map decision status to display status
        // Decision status: 'ready' or 'rejected'
        // Display status: 'ready' (has decision) or 'pending' (no decision)
        $mockRecord['status'] = 'ready'; // Any decision = ready for action
        $mockRecord['supplier_id'] = $decision->supplierId;
        $mockRecord['bank_id'] = $decision->bankId;
        $mockRecord['decision_source'] = $decision->decisionSource;
        $mockRecord['confidence_score'] = $decision->confidenceScore;
        $mockRecord['decided_at'] = $decision->decidedAt;
        $mockRecord['decided_by'] = $decision->decidedBy;
        $mockRecord['is_locked'] = (bool)$decision->isLocked;
        $mockRecord['locked_reason'] = $decision->lockedReason;
        
        // Phase 4: Active Action State
        $mockRecord['active_action'] = $decision->activeAction;
        $mockRecord['active_action_set_at'] = $decision->activeActionSetAt;
        
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
        
        // If bank_id exists, load bank details using Repository
        if ($decision->bankId) {
            $bank = $bankRepo->getBankDetails($decision->bankId);
            if ($bank) {
                $mockRecord['bank_name'] = $bank['official_name'];
                $mockRecord['bank_center'] = $bank['department'];
                $mockRecord['bank_po_box'] = $bank['po_box'];
                $mockRecord['bank_email'] = $bank['email'];
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
    
    // Load timeline/history for this guarantee using TimelineDisplayService
    $mockTimeline = \App\Services\TimelineDisplayService::getEventsForDisplay(
        $db,
        $currentRecord->id,
        $currentRecord->importedAt,
        $currentRecord->importSource,
        $currentRecord->importedBy
    );
    
    
    // Load notes and attachments using GuaranteeDataService
    $relatedData = \App\Services\GuaranteeDataService::getRelatedData($db, $currentRecord->id);
    $mockNotes = $relatedData['notes'];
    $mockAttachments = $relatedData['attachments'];
    
    // ADR-007: Timeline is audit-only, not UI data source
    // active_action (from guarantee_decisions) is the display pointer
    $latestEventSubtype = null; // Removed Timeline read
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
    // ✅ PHASE 4: Using UnifiedLearningAuthority
    $authority = AuthorityFactory::create();
    $suggestionDTOs = $authority->getSuggestions($mockRecord['supplier_name']);
    
    // Convert DTOs to legacy format for compatibility
    $initialSupplierSuggestions = array_map(function($dto) {
        return [
            'id' => $dto->supplier_id,
            'official_name' => $dto->official_name,
            'score' => $dto->confidence,
            'usage_count' => $dto->usage_count
        ];
    }, $suggestionDTOs);
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
    
    <!-- ✅ COMPLIANCE: Server-Driven Partials (Hidden) -->
    <?php include __DIR__ . '/partials/confirm-modal.php'; ?>
    
    <div id="preview-no-action-template" style="display:none">
        <?php include __DIR__ . '/partials/preview-placeholder.php'; ?>
    </div>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Letter Preview Styles (Classic Theme) -->
    <link rel="stylesheet" href="assets/css/letter.css">
    
    <!-- Alpine.js removed - using vanilla JavaScript instead -->
    
    <!-- Main Application Styles -->
    <link rel="stylesheet" href="public/css/index-main.css">

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
                    <?php if ($currentRecord): ?>
                        <?php
                            // Display status badge based on actual status
                            $statusClass = ($mockRecord['status'] === 'ready') ? 'badge-approved' : 'badge-pending';
                            $statusText = ($mockRecord['status'] === 'ready') ? 'جاهز' : 'يحتاج قرار';
                        ?>
                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                    <?php endif; ?>
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
                    <!-- ✅ HISTORICAL BANNER: Server-driven partial (Hidden by default) -->
        <div id="historical-banner-container" style="display:none">
            <?php include __DIR__ . '/partials/historical-banner.php'; ?>
        </div>

        <!-- Decision Cards -->
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
                    <?php if ($mockRecord['status'] === 'ready'): ?>
                        <?php require __DIR__ . '/partials/preview-section.php'; ?>
                    <?php endif; ?>

                </main>

            </div>
        </div>

        <!-- Sidebar (Left) -->
        <aside class="sidebar">
            
            <!-- Input Actions (New Proposal) -->
            <div class="input-toolbar">
                <!-- Import Stats (Interactive Filter) -->
                <?php if (isset($importStats) && ($importStats['total'] > 0)): ?>
                <div style="font-size: 11px; margin-bottom: 10px; display: flex; gap: 16px; align-items: center;">
                    <a href="/?filter=all" 
                       style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'all' ? 'background: #e0e7ff; font-weight: 600;' : '' ?>"
                       onmouseover="if('<?= $statusFilter ?>' !== 'all') this.style.background='#f1f5f9'"
                       onmouseout="if('<?= $statusFilter ?>' !== 'all') this.style.background='transparent'">
                        <span style="color: #334155;">📊 <?= $displayTotal ?? $importStats['total'] ?></span>
                    </a>
                    <a href="/?filter=ready" 
                       style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'ready' ? 'background: #dcfce7; font-weight: 600;' : '' ?>"
                       onmouseover="if('<?= $statusFilter ?>' !== 'ready') this.style.background='#f1f5f9'"
                       onmouseout="if('<?= $statusFilter ?>' !== 'ready') this.style.background='transparent'">
                        <span style="color: #059669;">✅ <?= $importStats['ready'] ?? 0 ?></span>
                    </a>
                    <a href="/?filter=pending" 
                       style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'pending' ? 'background: #fef3c7; font-weight: 600;' : '' ?>"
                       onmouseover="if('<?= $statusFilter ?>' !== 'pending') this.style.background='#f1f5f9'"
                       onmouseout="if('<?= $statusFilter ?>' !== 'pending') this.style.background='transparent'">
                        <span style="color: #d97706;">⚠️ <?= $importStats['pending'] ?? 0 ?></span>
                    </a>
                    <a href="/?filter=released" 
                       style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'released' ? 'background: #fee2e2; font-weight: 600;' : '' ?>"
                       onmouseover="if('<?= $statusFilter ?>' !== 'released') this.style.background='#f1f5f9'"
                       onmouseout="if('<?= $statusFilter ?>' !== 'released') this.style.background='transparent'">
                        <span style="color: #dc2626;">🔓 <?= $importStats['released'] ?? 0 ?></span>
                    </a>
                </div>
                <?php else: ?>
                <div class="toolbar-label">إدخال جديد</div>
                <?php endif; ?>
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
        // Preview Formatting moved to preview-formatter.js
        // ========================
        
        // Apply formatting on load - Preview is always visible
        document.addEventListener('DOMContentLoaded', function() {
            const previewSection = document.getElementById('preview-section');
            if (previewSection) {
                // Permanently add letter-preview class for styling
                previewSection.classList.add('letter-preview');

                // Execute conversions using centralized PreviewFormatter
                const runConversions = () => {
                    if (window.PreviewFormatter) {
                        window.PreviewFormatter.applyFormatting();
                    }
                };
                
                runConversions();
                // Run again after a slight delay to catch any dynamic updates
                setTimeout(runConversions, 500);
            }
        });
            

    </script>

    
    <script src="/public/js/pilot-auto-load.js?v=<?= time() ?>"></script>

    
    <!-- ✅ UX UNIFICATION: Old Level B handler and modal removed -->
    <!-- Level B handler disabled by UX_UNIFICATION_ENABLED flag -->
    <!-- Modal no longer needed - Selection IS the confirmation -->
    
    <script src="/public/js/preview-formatter.js?v=<?= time() ?>"></script>
    <script src="/public/js/main.js?v=<?= time() ?>"></script>
    <script src="/public/js/input-modals.controller.js?v=<?= time() ?>"></script>
    <script src="/public/js/timeline.controller.js?v=<?= time() ?>"></script>
    <script src="/public/js/records.controller.js?v=<?= time() ?>"></script>
</body>
</html>
