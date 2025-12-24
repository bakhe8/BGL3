<?php
/**
 * Experiment 01: AI-First Decision Flow
 * 
 * Goal: Test if putting AI recommendation as the hero
 *       reduces decision time by 50%+
 */

// Get data access
$dataAccess = new LabDataAccess();

// Get record - using a sample ID for demo
$recordId = $_GET['record_id'] ?? 14002;
$record = $dataAccess->getGuaranteeRecord($recordId);

if (!$record) {
    echo "Record not found";
    exit;
}

// Get AI recommendation
$aiRec = $dataAccess->getAIRecommendation($recordId);

// Get similar cases
$similarCases = $dataAccess->getSimilarCases($recordId);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Experiment 01: AI-First | DesignLab</title>
    <link rel="stylesheet" href="/design-lab/assets/css/tokens.css">
    <link rel="stylesheet" href="/design-lab/assets/css/base.css">
    <link rel="stylesheet" href="/design-lab/assets/css/ai-first.css">
</head>
<body class="lab-mode">
    
    <?php LabMode::renderModeBadge(); ?>
    
    <!-- Version Switcher -->
    <div class="version-switcher">
        <span>النسخة:</span>
        <a href="/?record_id=<?= $recordId ?>">الحالية</a>
        <span class="separator">|</span>
        <a href="/lab/experiments/ai-first?record_id=<?= $recordId ?>" class="active">🧪 المختبر</a>
    </div>
    
    <div class="lab-container">
        <!-- Experiment Header -->
        <header style="margin-bottom: 2rem; text-align: center;">
            <h1 style="font-size: 2rem; margin-bottom: 0.5rem;">
                🧪 Experiment 01: AI-First Decision Flow
            </h1>
            <p style="color: var(--color-text-secondary);">
                تركيز على توصية الذكاء الاصطناعي كبطل الصفحة
            </p>
        </header>
        
        <!-- Record Info -->
        <div class="record-info">
            <div class="info-item">
                <span class="info-label">المورد</span>
                <span class="info-value"><?= htmlspecialchars($record['supplier'] ?? 'غير محدد') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">البنك</span>
                <span class="info-value"><?= htmlspecialchars($record['bank'] ?? 'غير محدد') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">المبلغ</span>
                <span class="info-value"><?= number_format($record['amount'] ?? 0) ?> ريال</span>
            </div>
            <div class="info-item">
                <span class="info-label">تاريخ الانتهاء</span>
                <span class="info-value"><?= htmlspecialchars($record['expiry_date'] ?? 'غير محدد') ?></span>
            </div>
        </div>
        
        <!-- AI Hero Section -->
        <div class="ai-hero">
            <div class="ai-recommendation">
                <h2>يُنصح بالموافقة</h2>
                <div class="confidence-badge">
                    ثقة: <?= round($aiRec['confidence'] * 100) ?>%
                </div>
            </div>
            
            <div class="ai-reasoning">
                <h3>السبب:</h3>
                <ul>
                    <?php foreach ($aiRec['reasons'] as $reason): ?>
                    <li><?= htmlspecialchars($reason) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="ai-actions">
                <button id="quick-approve" class="btn-primary-large">
                    اتبع التوصية ✓
                </button>
                <button id="manual-mode" class="btn-secondary">
                    اختر يدوياً →
                </button>
            </div>
        </div>
        
        <!-- Decision Section (Collapsible) -->
        <div id="decision-section" class="decision-section">
            <h3 style="margin-bottom: 1.5rem; font-size: 1.5rem;">اختر القرار يدوياً:</h3>
            
            <div class="decision-cards">
                <div class="decision-card" data-decision="approve">
                    <div class="icon">✅</div>
                    <h3>موافقة</h3>
                    <div class="confidence">⭐⭐ 95%</div>
                </div>
                
                <div class="decision-card" data-decision="extend">
                    <div class="icon">🔄</div>
                    <h3>تمديد</h3>
                    <div class="confidence">⭐ 25%</div>
                </div>
                
                <div class="decision-card" data-decision="reject">
                    <div class="icon">❌</div>
                    <h3>رفض</h3>
                    <div class="confidence">--</div>
                </div>
                
                <div class="decision-card" data-decision="hold">
                    <div class="icon">⏸️</div>
                    <h3>تعليق</h3>
                    <div class="confidence">--</div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 2rem;">
                <button id="save-decision" class="btn-primary">
                    حفظ القرار
                </button>
            </div>
        </div>
        
        <!-- Context Section -->
        <div class="context-section">
            <h3 style="margin-bottom: 1rem;">السياق الإضافي (اختياري):</h3>
            
            <button class="context-toggle" data-target="timeline-drawer">
                <span>📋 Timeline (آخر الأحداث)</span>
                <span class="badge" style="background: var(--color-bg-tertiary); padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.875rem;">5</span>
            </button>
            
            <div id="timeline-drawer" class="context-drawer">
                <p style="color: var(--color-text-secondary);">
                    🚧 Timeline content will be loaded here<br>
                    (سيتم تحميل الأحداث الزمنية هنا)
                </p>
            </div>
            
            <button class="context-toggle" data-target="similar-drawer">
                <span>🔍 حالات مشابهة</span>
                <span class="badge" style="background: var(--color-bg-tertiary); padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.875rem;"><?= count($similarCases) ?></span>
            </button>
            
            <div id="similar-drawer" class="context-drawer">
                <?php if (count($similarCases) > 0): ?>
                <div style="display: grid; gap: 1rem;">
                    <?php foreach ($similarCases as $case): ?>
                    <div style="padding: 1rem; background: var(--color-bg-tertiary); border-radius: 0.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 600;"><?= htmlspecialchars($case['supplier']) ?></span>
                            <span style="color: var(--color-success);">✓ <?= htmlspecialchars($case['decision']) ?></span>
                        </div>
                        <div style="font-size: 0.875rem; color: var(--color-text-muted); margin-top: 0.5rem;">
                            منذ <?= $case['days_ago'] ?> يوم • Record #<?= $case['record_id'] ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="color: var(--color-text-secondary);">لا توجد حالات مشابهة</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Metrics Info -->
        <div style="margin-top: 3rem; padding: 1.5rem; background: rgba(99, 102, 241, 0.05); border-radius: 1rem; border: 1px solid rgba(99, 102, 241, 0.2);">
            <h4 style="margin-bottom: 1rem;">📊 ما يتم قياسه في هذه التجربة:</h4>
            <ul style="color: var(--color-text-secondary); line-height: 1.8;">
                <li>⏱️ الوقت من تحميل الصفحة حتى اتخاذ القرار</li>
                <li>🖱️ عدد النقرات المطلوبة</li>
                <li>🎯 هل استخدم المستخدم "الموافقة السريعة" أم الاختيار اليدوي؟</li>
                <li>📂 ما هي أقسام السياق التي تم فتحها؟</li>
            </ul>
            <p style="margin-top: 1rem; font-size: 0.875rem; color: var(--color-text-muted);">
                💾 البيانات تُحفظ في localStorage ويمكن مراجعتها في Console
            </p>
        </div>
    </div>
    
    <footer class="lab-footer">
        <a href="/lab" class="back-to-production">← العودة لقائمة التجارب</a>
    </footer>
    
    <!-- Simulation Notice -->
    <div id="simulation-notice" class="simulation-notice"></div>
    
    <script src="/design-lab/assets/js/ai-first.js"></script>
    
</body>
</html>
