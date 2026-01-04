<?php
/**
 * Dashboard View
 * Assembles all partials into the complete dashboard interface
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BGL System v3.0</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Styles (inline for now, will extract to CSS later) -->
    <style>
        /* Import existing styles from original monolithic index.php */
        <?php
        // Get CSS from original monolithic backup
        $cssContent = file_get_contents(__DIR__ . '/../../index_monolithic_original.php');
        if (preg_match('/<style>(.*?)<\/style>/s', $cssContent, $matches)) {
            echo $matches[1];
        }
        ?>
        ?>
    </style>
</head>
<body>
    <!-- Hidden File Input -->
    <input type="file" id="hiddenFileInput" accept=".xlsx,.xls" style="display: none;">
    
    <!-- Header -->
    <?php require __DIR__ . '/../../partials/header.php'; ?>

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
                    
                    <span class="record-position" style="font-size: 14px; font-weight: 600; color: var(--text-secondary);">
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
                
                <!-- Timeline Panel -->
                <?php 
                $timeline = $mockTimeline;
                require __DIR__ . '/../../partials/timeline-section.php'; 
                ?>

                <!-- Main Content -->
                <main class="main-content">
                    <!-- Decision Card -->
                    <div class="decision-card">
                        <?php
                        // Prepare variables for record-form partial
                        $record = $mockRecord;
                        $guarantee = $currentRecord;
                        $supplierMatch = [
                            'suggestions' => $formattedSuppliers,
                            'score' => !empty($formattedSuppliers) ? $formattedSuppliers[0]['score'] : 0
                        ];
                        $banks = $allBanks;
                        $bankMatch = [];
                        $isHistorical = false;
                        
                        require __DIR__ . '/../../partials/record-form.php';
                        ?>
                    </div>
                </main>

            </div>
        </div>

        <!-- Sidebar -->
        <?php require __DIR__ . '/../../partials/sidebar.php'; ?>

    </div>

    <!-- Modals -->
    <?php require __DIR__ . '/../../partials/manual-entry-modal.php'; ?>
    <?php require __DIR__ . '/../../partials/paste-modal.php'; ?>

    <!-- Scripts (extracted from original index.php) -->
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
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => toast.remove(), 3000);
        }
        
        // Notes functionality
        function showNoteInput() {
            document.getElementById('noteInputBox').style.display = 'block';
            document.getElementById('addNoteBtn').style.display = 'none';
        }
        
        function cancelNote() {
            document.getElementById('noteInputBox').style.display = 'none';
            document.getElementById('addNoteBtn').style.display = 'block';
            document.getElementById('noteTextarea').value = '';
        }
        
        async function saveNote() {
            const content = document.getElementById('noteTextarea').value.trim();
            if (!content) return;
            showToast('حفظ الملاحظات غير مفعّل حالياً', 'info');
        }
        
        async function uploadFile(event) {
            showToast('رفع الملفات غير مفعّل حالياً', 'info');
        }
        
        // Modal handlers
        document.querySelectorAll('[data-action="showManualInput"]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('manualEntryModal').style.display = 'flex';
            });
        });
        
        document.querySelectorAll('[data-action="showPasteModal"]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('pasteModal').style.display = 'flex';
            });
        });
        
        function processPaste() {
            showToast('معالجة اللصق غير مفعّلة حالياً', 'info');
        }
    </script>

    <!-- Load external JS files if they exist -->
    <script src="/public/js/main.js?v=<?= time() ?>"></script>
    <script src="/public/js/records.controller.js?v=<?= time() ?>"></script>
</body>
</html>
