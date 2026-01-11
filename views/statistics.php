<?php
// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

header('Content-Type: text/html; charset=utf-8');

// Helper functions (Added span for currency symbol styling)
function formatMoney($amount) { return number_format((float)$amount, 2) . ' <span class="text-xs text-muted">ر.س</span>'; }
function formatNumber($num) { return number_format((float)$num); }

$db = Database::connect();

try {
    // ============================================
    // SECTION 1: GLOBAL METRICS (ASSET vs OCCURRENCE)
    // ============================================
    $overview = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM guarantees) as total_assets,
            (SELECT COUNT(*) FROM guarantee_occurrences) as total_occurrences,
            (SELECT COUNT(*) FROM guarantees WHERE json_extract(raw_data, '$.expiry_date') >= date('now')) as active_assets,
            (SELECT COUNT(*) FROM batch_metadata WHERE status='active') as active_batches,
            (SELECT SUM(CAST(json_extract(raw_data, '$.amount') AS REAL)) FROM guarantees) as total_amount,
            (SELECT AVG(CAST(json_extract(raw_data, '$.amount') AS REAL)) FROM guarantees) as avg_amount,
            (SELECT MAX(CAST(json_extract(raw_data, '$.amount') AS REAL)) FROM guarantees) as max_amount,
            (SELECT MIN(CAST(json_extract(raw_data, '$.amount') AS REAL)) FROM guarantees) as min_amount
    ")->fetch(PDO::FETCH_ASSOC);

    $efficiencyRatio = $overview['total_assets'] > 0 
        ? round($overview['total_occurrences'] / $overview['total_assets'], 2) 
        : 1;

    // ============================================
    // SECTION 2: BATCH OPERATIONS ANALYSIS
    // ============================================
    // Analyze recent batches: Content vs Context
    $batchStats = $db->query("
        SELECT 
            o.batch_identifier,
            COALESCE(MAX(m.batch_name), 'دفعة ' || SUBSTR(o.batch_identifier, 1, 15)) as batch_name,
            MAX(o.occurred_at) as import_date,
            COUNT(o.id) as total_rows,
            SUM(CASE WHEN g.import_source = o.batch_identifier THEN 1 ELSE 0 END) as new_items,
            SUM(CASE WHEN g.import_source != o.batch_identifier THEN 1 ELSE 0 END) as recurring_items
        FROM guarantee_occurrences o
        JOIN guarantees g ON o.guarantee_id = g.id
        LEFT JOIN batch_metadata m ON o.batch_identifier = m.import_source
        GROUP BY o.batch_identifier
        ORDER BY import_date DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Identify Most Frequent Assets (Top Recurring)
    $topRecurring = $db->query("
        SELECT 
            g.guarantee_number,
            s.official_name as supplier,
            b.arabic_name as bank,
            COUNT(o.id) as occurrence_count,
            MIN(o.occurred_at) as first_seen,
            MAX(o.occurred_at) as last_seen
        FROM guarantee_occurrences o
        JOIN guarantees g ON o.guarantee_id = g.id
        LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
        LEFT JOIN suppliers s ON d.supplier_id = s.id
        LEFT JOIN banks b ON d.bank_id = b.id
        GROUP BY g.id
        HAVING occurrence_count > 1
        ORDER BY occurrence_count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // SECTION 3: BANKS & SUPPLIERS (Existing)
    // ============================================
    $topSuppliers = $db->query("
        SELECT s.official_name, COUNT(*) as count
        FROM guarantee_decisions d
        JOIN suppliers s ON d.supplier_id = s.id
        GROUP BY s.id
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topBanks = $db->query("
        SELECT 
            b.arabic_name as bank_name,
            COUNT(*) as count,
            SUM(CAST(json_extract(g.raw_data, '$.amount') AS REAL)) as total_amount,
            (SELECT COUNT(*) FROM guarantee_history h 
             JOIN guarantee_decisions d2 ON h.guarantee_id = d2.guarantee_id
             WHERE d2.bank_id = b.id AND h.event_subtype = 'extension') as extensions
        FROM guarantee_decisions d
        JOIN banks b ON d.bank_id = b.id
        JOIN guarantees g ON d.guarantee_id = g.id
        GROUP BY b.id
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stableSuppliers = $db->query("
        SELECT s.official_name, COUNT(DISTINCT d.guarantee_id) as count
        FROM suppliers s
        JOIN guarantee_decisions d ON s.id = d.supplier_id
        LEFT JOIN guarantee_history h ON d.guarantee_id = h.guarantee_id AND h.event_type = 'modified'
        WHERE h.id IS NULL
        GROUP BY s.id
        HAVING count >= 2
        ORDER BY count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $riskySuppliers = $db->query("
        SELECT 
            s.official_name,
            COUNT(DISTINCT d.guarantee_id) as total,
            COUNT(DISTINCT CASE WHEN h.event_subtype = 'extension' THEN h.guarantee_id END) as extensions,
            COUNT(DISTINCT CASE WHEN h.event_subtype = 'reduction' THEN h.guarantee_id END) as reductions,
            ROUND((CAST(COUNT(DISTINCT CASE WHEN h.event_subtype = 'extension' THEN h.guarantee_id END) AS REAL) * 0.6 + 
                   CAST(COUNT(DISTINCT CASE WHEN h.event_subtype = 'reduction' THEN h.guarantee_id END) AS REAL) * 0.4) / 
                   CAST(COUNT(DISTINCT d.guarantee_id) AS REAL) * 100, 1) as risk_score
        FROM suppliers s
        JOIN guarantee_decisions d ON s.id = d.supplier_id
        LEFT JOIN guarantee_history h ON d.guarantee_id = h.guarantee_id
        GROUP BY s.id
        HAVING total >= 2
        ORDER BY risk_score DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $challengingSuppliers = $db->query("
        SELECT s.official_name, COUNT(*) as manual_count
        FROM guarantee_decisions d
        JOIN suppliers s ON d.supplier_id = s.id
        WHERE d.decision_source = 'manual' OR d.decision_source IS NULL
        GROUP BY s.id
        ORDER BY manual_count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $uniqueCounts = $db->query("
        SELECT 
            COUNT(DISTINCT supplier_id) as suppliers,
            COUNT(DISTINCT bank_id) as banks
        FROM guarantee_decisions
    ")->fetch(PDO::FETCH_ASSOC);
    
    $bankSupplierPairs = $db->query("
        SELECT 
            b.arabic_name as bank,
            s.official_name as supplier,
            COUNT(*) as count
        FROM guarantee_decisions d
        JOIN banks b ON d.bank_id = b.id
        JOIN suppliers s ON d.supplier_id = s.id
        GROUP BY b.id, s.id
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $exclusiveSuppliers = $db->query("
        SELECT COUNT(*) FROM (
            SELECT supplier_id
            FROM guarantee_decisions
            GROUP BY supplier_id
            HAVING COUNT(DISTINCT bank_id) = 1
        )
    ")->fetchColumn();


    // ============================================
    // SECTION 3: TIME & PERFORMANCE
    // ============================================
    $timing = $db->query("
        SELECT 
            AVG(CAST((julianday(d.decided_at) - julianday(g.imported_at)) * 24 AS REAL)) as avg_hours,
            MIN(CAST((julianday(d.decided_at) - julianday(g.imported_at)) * 24 AS REAL)) as min_hours,
            MAX(CAST((julianday(d.decided_at) - julianday(g.imported_at)) * 24 AS REAL)) as max_hours
        FROM guarantee_decisions d
        JOIN guarantees g ON d.guarantee_id = g.id
        WHERE d.decided_at IS NOT NULL
    ")->fetch(PDO::FETCH_ASSOC);
    
    $peakHour = $db->query("
        SELECT strftime('%H', created_at) as hour, COUNT(*) as count
        FROM guarantee_history
        GROUP BY hour
        ORDER BY count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    $qualityMetrics = $db->query("
        SELECT 
            COUNT(DISTINCT g.id) as total,
            COUNT(DISTINCT CASE WHEN h.id IS NULL THEN g.id END) as ftr,
            COUNT(DISTINCT CASE WHEN (SELECT COUNT(*) FROM guarantee_history h2 
                                      WHERE h2.guarantee_id = g.id AND h2.event_type = 'modified') >= 3 
                          THEN g.id END) as complex
        FROM guarantees g
        LEFT JOIN guarantee_history h ON g.id = h.guarantee_id AND h.event_type = 'modified'
    ")->fetch(PDO::FETCH_ASSOC);
    
    $firstTimeRight = $qualityMetrics['total'] > 0 ? round(($qualityMetrics['ftr'] / $qualityMetrics['total']) * 100, 1) : 0;
    $complexGuarantees = $qualityMetrics['complex'];
    
    $busiestDay = $db->query("
        SELECT 
            CASE CAST(strftime('%w', created_at) AS INTEGER)
                WHEN 0 THEN 'الأحد' WHEN 1 THEN 'الإثنين' WHEN 2 THEN 'الثلاثاء'
                WHEN 3 THEN 'الأربعاء' WHEN 4 THEN 'الخميس' WHEN 5 THEN 'الجمعة' WHEN 6 THEN 'السبت'
            END as weekday,
            COUNT(*) as count
        FROM guarantee_history
        GROUP BY strftime('%w', created_at)
        ORDER BY count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    $weeklyTrend = $db->query("
        SELECT 
            COUNT(CASE WHEN imported_at >= date('now', '-7 days') THEN 1 END) as this_week,
            COUNT(CASE WHEN imported_at >= date('now', '-14 days') AND imported_at < date('now', '-7 days') THEN 1 END) as last_week
        FROM guarantees
    ")->fetch(PDO::FETCH_ASSOC);

    $trendPercent = 0;
    if (($weeklyTrend['last_week'] ?? 0) > 0) {
        $trendPercent = (($weeklyTrend['this_week'] - $weeklyTrend['last_week']) / $weeklyTrend['last_week']) * 100;
    }
    $trendDirection = ($trendPercent >= 0 ? '+' : '') . round($trendPercent, 1) . '%';


    // ============================================
    // SECTION 4: EXPIRATION & ACTIONS (RECONSTRUCTED)
    // ============================================
    
    // 4A: Expiration Pressure
    $expiration = $db->query("
        SELECT 
            COUNT(CASE WHEN json_extract(raw_data, '$.expiry_date') BETWEEN date('now') AND date('now', '+30 days') THEN 1 END) as next_30,
            COUNT(CASE WHEN json_extract(raw_data, '$.expiry_date') BETWEEN date('now') AND date('now', '+90 days') THEN 1 END) as next_90
        FROM guarantees
    ")->fetch(PDO::FETCH_ASSOC);

    // Peak Month
    $peakMonth = $db->query("
        SELECT strftime('%Y-%m', json_extract(raw_data, '$.expiry_date')) as month, COUNT(*) as count
        FROM guarantees
        WHERE json_extract(raw_data, '$.expiry_date') >= date('now')
        GROUP BY month
        ORDER BY count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Expiration next 12 months
    $expirationByMonth = $db->query("
        SELECT strftime('%Y-%m', json_extract(raw_data, '$.expiry_date')) as month, COUNT(*) as count
        FROM guarantees
        WHERE json_extract(raw_data, '$.expiry_date') BETWEEN date('now') AND date('now', '+1 year')
        GROUP BY month
        ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 4C: Actions
    $actions = $db->query("
        SELECT 
            COUNT(CASE WHEN event_subtype = 'extension' THEN 1 END) as extensions,
            COUNT(CASE WHEN event_subtype = 'reduction' THEN 1 END) as reductions,
            COUNT(CASE WHEN event_type = 'released' THEN 1 END) as releases,
            COUNT(CASE WHEN event_type = 'released' AND created_at >= date('now', '-7 days') THEN 1 END) as recent_releases
        FROM guarantee_history
    ")->fetch(PDO::FETCH_ASSOC);

    $multipleExtensions = $db->query("
        SELECT COUNT(*) FROM (
            SELECT guarantee_id
            FROM guarantee_history
            WHERE event_subtype = 'extension'
            GROUP BY guarantee_id
            HAVING COUNT(*) > 1
        )
    ")->fetchColumn();

    $extensionProbability = $db->query("
        SELECT 
            s.official_name,
            ROUND(CAST(COUNT(CASE WHEN h.event_subtype = 'extension' THEN 1 END) AS REAL) / 
                  CAST(COUNT(DISTINCT d.guarantee_id) AS REAL) * 100, 0) as probability
        FROM suppliers s
        JOIN guarantee_decisions d ON s.id = d.supplier_id
        LEFT JOIN guarantee_history h ON d.guarantee_id = h.guarantee_id AND h.event_subtype = 'extension'
        GROUP BY s.id
        HAVING COUNT(DISTINCT d.guarantee_id) >= 3
        ORDER BY probability DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $topEventTypes = $db->query("
        SELECT event_type, COUNT(*) as count
        FROM guarantee_history
        GROUP BY event_type
        ORDER BY count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);


    // ============================================
    // SECTION 5: AI & MACHINE LEARNING
    // ============================================
    $aiStats = $db->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN decision_source IN ('ai_match', 'ai_quick', 'direct_match') THEN 1 END) as ai_matches,
            COUNT(CASE WHEN decision_source = 'manual' OR decision_source IS NULL THEN 1 END) as manual
        FROM guarantee_decisions
    ")->fetch(PDO::FETCH_ASSOC);
    
    $aiMatchRate = $aiStats['total'] > 0 ? round(($aiStats['ai_matches'] / $aiStats['total']) * 100, 1) : 0;
    $manualIntervention = $aiStats['total'] > 0 ? round(($aiStats['manual'] / $aiStats['total']) * 100, 1) : 0;
    $automationRate = 100 - $manualIntervention;
    
    $autoMatchEvents = $db->query("
        SELECT COUNT(*) 
        FROM guarantee_history 
        WHERE event_type IN ('auto_matched', 'modified')
        AND event_subtype IN ('auto_match', 'bank_match', 'ai_match')
    ")->fetchColumn();
    
    // ML from learning_confirmations
    $mlStats = ['confirmations' => 0, 'rejections' => 0, 'total' => 0];
    $confirmedPatterns = [];
    $rejectedPatterns = [];
    $confidenceDistribution = ['high' => 0, 'medium' => 0, 'low' => 0];
    
    try {
        $mlStatsCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='learning_confirmations'")->fetch();
        if ($mlStatsCheck) {
             $mlStats = $db->query("
                SELECT 
                    COUNT(CASE WHEN action = 'confirm' THEN 1 END) as confirmations,
                    COUNT(CASE WHEN action = 'reject' THEN 1 END) as rejections,
                    COUNT(*) as total
                FROM learning_confirmations
            ")->fetch(PDO::FETCH_ASSOC);
            
            $confirmedPatterns = $db->query("
                SELECT lc.raw_supplier_name, s.official_name, lc.count
                FROM learning_confirmations lc
                JOIN suppliers s ON lc.supplier_id = s.id
                WHERE lc.action = 'confirm'
                ORDER BY lc.count DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $rejectedPatterns = $db->query("
                SELECT lc.raw_supplier_name, s.official_name, lc.count
                FROM learning_confirmations lc
                JOIN suppliers s ON lc.supplier_id = s.id
                WHERE lc.action = 'reject'
                ORDER BY lc.count DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        $learningPatternsCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='learning_patterns'")->fetch();
        if ($learningPatternsCheck) {
            $confidenceDistribution = $db->query("
                SELECT 
                    COUNT(CASE WHEN confidence >= 80 THEN 1 END) as high,
                    COUNT(CASE WHEN confidence >= 50 AND confidence < 80 THEN 1 END) as medium,
                    COUNT(CASE WHEN confidence < 50 THEN 1 END) as low
                FROM learning_patterns
            ")->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Tables don't exist
    }
    
    $mlAccuracy = $mlStats['total'] > 0 ? round(($mlStats['confirmations'] / $mlStats['total']) * 100, 1) : 0;
    $timeSaved = round(($aiStats['ai_matches'] ?? 0) * 2 / 60, 1); // 2 min per decision


    // ============================================
    // SECTION 6: FINANCIAL & TYPES
    // ============================================
    // 🔧 REVERTED: Normalization should happen at import time, not here.
    $typeDistribution = $db->query("
        SELECT json_extract(raw_data, '$.type') as type, COUNT(*) as count
        FROM guarantees
        GROUP BY type
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $amountCorrelation = $db->query("
        SELECT 
            CASE 
                WHEN CAST(json_extract(g.raw_data, '$.amount') AS REAL) < 100000 THEN 'صغير (<100K)'
                WHEN CAST(json_extract(g.raw_data, '$.amount') AS REAL) < 500000 THEN 'متوسط (100-500K)'
                ELSE 'كبير (>500K)'
            END as range,
            COUNT(DISTINCT g.id) as total,
            COUNT(DISTINCT h.guarantee_id) as extended,
            ROUND(CAST(COUNT(DISTINCT h.guarantee_id) AS REAL) / CAST(COUNT(DISTINCT g.id) AS REAL) * 100, 1) as ext_rate
        FROM guarantees g
        LEFT JOIN guarantee_history h ON g.id = h.guarantee_id AND h.event_subtype = 'extension'
        GROUP BY range
        ORDER BY ext_rate DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    error_log("Statistics error: " . $errorMessage);
    // Safe defaults
    $overview = ['total' => 0, 'active' => 0, 'expired' => 0, 'this_month' => 0, 'total_amount' => 0, 'avg_amount' => 0, 'max_amount' => 0, 'min_amount' => 0, 'total_assets' => 0, 'total_occurrences' => 0, 'active_batches' => 0];
    $pending = 0; $ready = 0; $released = 0;
    $batchStats = [];
    $topRecurring = [];
    $topSuppliers = [];
    $topBanks = [];
    $stableSuppliers = [];
    $riskySuppliers = [];
    $challengingSuppliers = [];
    $uniqueCounts = ['suppliers' => 0, 'banks' => 0];
    $exclusiveSuppliers = 0;
    $timing = ['avg_hours' => 0, 'min_hours' => 0, 'max_hours' => 0];
    $peakHour = ['hour' => 'N/A', 'count' => 0];
    $qualityMetrics = ['total' => 0, 'ftr' => 0, 'complex' => 0];
    $firstTimeRight = 0;
    $complexGuarantees = 0;
    $busiestDay = ['weekday' => 'N/A', 'count' => 0];
    $weeklyTrend = ['this_week' => 0, 'last_week' => 0];
    $trendPercent = 0;
    $trendDirection = '0%';
    $expiration = ['next_30' => 0, 'next_90' => 0];
    $peakMonth = ['month' => 'N/A', 'count' => 0];
    $expirationByMonth = [];
    $actions = ['extensions' => 0, 'reductions' => 0, 'releases' => 0, 'recent_releases' => 0];
    $multipleExtensions = 0;
    $extensionProbability = [];
    $topEventTypes = [];
    $aiStats = ['total' => 0, 'ai_matches' => 0, 'manual' => 0];
    $aiMatchRate = 0;
    $manualIntervention = 0;
    $automationRate = 0;
    $autoMatchEvents = 0;
    $mlStats = ['confirmations' => 0, 'rejections' => 0, 'total' => 0];
    $confirmedPatterns = [];
    $rejectedPatterns = [];
    $confidenceDistribution = ['high' => 0, 'medium' => 0, 'low' => 0];
    $mlAccuracy = 0;
    $timeSaved = 0;
    $typeDistribution = [];
    $amountCorrelation = [];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإحصائيات المتقدمة - BGL3</title>
    
    <!-- Design System CSS -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    <link rel="stylesheet" href="../public/css/batch-detail.css"> 
    
    <style>
        /* Statistics Page - Layout Only without Overrides */
        .stats-container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: var(--space-lg);
            padding-top: var(--space-xl);
        }
        
        .page-header {
            margin-bottom: var(--space-2xl);
        }
        
        /* Grid System - Using CSS Grid */
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-lg); }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-lg); }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-lg); }
        .grid-5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: var(--space-md); }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
            .grid-5 { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .grid-2, .grid-3, .grid-4, .grid-5 { grid-template-columns: 1fr; }
            .stats-container { padding: var(--space-md); }
        }
        
        /* Hero Cards Replacement */
        .hero-stats-card {
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            box-shadow: var(--shadow-md);
            transition: all var(--transition-base);
        }
        
        .hero-stats-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .hero-stats-card.primary { border-top: 4px solid var(--accent-primary); }
        .hero-stats-card.success { border-top: 4px solid var(--accent-success); }
        .hero-stats-card.warning { border-top: 4px solid var(--accent-warning); }
        .hero-stats-card.danger  { border-top: 4px solid var(--accent-danger); }
        
        .hero-value { font-size: 36px; font-weight: var(--font-weight-bold); line-height: 1.2; margin-bottom: var(--space-xs); }
        .hero-label { font-size: var(--font-size-sm); color: var(--text-secondary); }

        /* Metric Mini Cards */
        .metric-mini-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: var(--space-md);
            text-align: center;
            border: 1px solid var(--border-light);
        }
        
        /* Section Header */
        .section-separator {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            margin: 40px 0 24px 0;
            padding-bottom: var(--space-sm);
            border-bottom: 2px solid var(--border-light);
        }
        .section-title {
            font-size: var(--font-size-xl);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
        }

        /* Progress Bars */
        .progress-track {
            background: var(--border-light);
            height: 8px;
            border-radius: var(--radius-full);
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: var(--radius-full);
        }
    </style>
</head>
<body>
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="stats-container">
        
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="font-bold text-primary mb-1 text-2xl">لوحة القيادة والتحليل</h1>
                <p class="text-secondary text-sm">نظرة شمولية على الضمانات، الدفعات، والكفاءة التشغيلية</p>
            </div>
            <span class="badge badge-neutral-light"><?= date('Y-m-d') ?></span>
        </div>

        <!-- SECTION 1: SYSTEM HEALTH (ASSETS vs OCCURRENCES) -->
        <div class="grid-4 mb-6">
            <div class="hero-stats-card" style="border-top: 4px solid var(--accent-primary);">
                <div class="hero-value text-primary"><?= formatNumber($overview['total_assets']) ?></div>
                <div class="hero-label">أصول فريدة (Assets)</div>
                <div class="text-xs text-muted mt-2">ضمانات مميزة في النظام</div>
            </div>
            <div class="hero-stats-card" style="border-top: 4px solid var(--accent-info);">
                <div class="hero-value text-info"><?= formatNumber($overview['total_occurrences']) ?></div>
                <div class="hero-label">سجلات ظهور (Occurrences)</div>
                <div class="text-xs text-muted mt-2">مجموع الأسطر المستوردة</div>
            </div>
            <div class="hero-stats-card" style="border-top: 4px solid var(--accent-success);">
                <div class="hero-value text-success"><?= $efficiencyRatio ?>x</div>
                <div class="hero-label">معدل الكفاءة</div>
                <div class="text-xs text-muted mt-2">متوسط ظهور الضمان الواحد</div>
            </div>
            <div class="hero-stats-card" style="border-top: 4px solid var(--accent-warning);">
                <div class="hero-value text-warning"><?= formatNumber($overview['active_batches']) ?></div>
                <div class="hero-label">دفعات نشطة</div>
                <div class="text-xs text-muted mt-2">جلسات عمل مفتوحة</div>
            </div>
        </div>

        <!-- SECTION 2: BATCH ANALYSIS -->
        <div class="card mb-6">
            <div class="card-header flex-between">
                <h3 class="card-title">تحليل أداء الدفعات (Batch Context Analysis)</h3>
                <span class="badge badge-primary-light">آخر 5 دفعات</span>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>الدفعة</th>
                            <th>تاريخ الاستيراد</th>
                            <th>إجمالي الأسطر</th>
                            <th>ضمانات جديدة</th>
                            <th>تكرارات (Re-occurrences)</th>
                            <th>نسبة التكرار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batchStats as $batch): 
                            $recurRate = $batch['total_rows'] > 0 
                                ? round(($batch['recurring_items'] / $batch['total_rows']) * 100, 1) 
                                : 0;
                        ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($batch['batch_name']) ?></td>
                            <td class="text-mono"><?= $batch['import_date'] ?></td>
                            <td class="text-center font-bold"><?= formatNumber($batch['total_rows']) ?></td>
                            <td class="text-center text-success">
                                <span class="badge badge-success-light">+<?= formatNumber($batch['new_items']) ?></span>
                            </td>
                            <td class="text-center text-info">
                                <span class="badge badge-info-light">↻ <?= formatNumber($batch['recurring_items']) ?></span>
                            </td>
                            <td class="text-center">
                                <div class="flex-align-center gap-2" style="justify-content: center;">
                                    <div class="progress-track" style="width: 60px; height: 6px;">
                                        <div class="progress-fill bg-info" style="width: <?= $recurRate ?>%"></div>
                                    </div>
                                    <span class="text-xs font-bold"><?= $recurRate ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SECTION 3: TOP RECURRING ASSETS -->
        <div class="grid-2 mb-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">الأصول الأكثر نشاطاً (تكراراً)</h3>
                </div>
                <div class="card-body p-0">
                     <table class="table">
                        <thead><tr><th>رقم الضمان</th><th>المورد</th><th>عدد مرات الظهور</th></tr></thead>
                        <tbody>
                            <?php foreach ($topRecurring as $item): ?>
                            <tr>
                                <td class="font-bold font-mono text-primary"><?= htmlspecialchars($item['guarantee_number']) ?></td>
                                <td class="text-sm"><?= htmlspecialchars($item['supplier']) ?></td>
                                <td class="text-center font-bold text-lg"><?= $item['occurrence_count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">أهم الموردين (حسب الأصول الفريدة)</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead><tr><th>المورد</th><th>عدد الضمانات</th></tr></thead>
                        <tbody>
                            <?php foreach ($topSuppliers as $supplier): ?>
                            <tr>
                                <td><?= htmlspecialchars($supplier['official_name']) ?></td>
                                <td class="font-bold"><?= formatNumber($supplier['count']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- SECTION 3B: TOP BANKS -->
        <div class="card mb-6">
            <div class="card-header">
                <h3 class="card-title">الأداء المالي للبنوك</h3>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>البنك</th>
                            <th>عدد الضمانات</th>
                            <th>إجمالي المبالغ</th>
                            <th>تمديدات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topBanks as $bank): ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($bank['bank_name']) ?></td>
                            <td class="text-center font-bold"><?= formatNumber($bank['count']) ?></td>
                            <td class="text-center"><?= formatMoney($bank['total_amount']) ?></td>
                            <td class="text-center">
                                <?php if ($bank['extensions'] > 0): ?>
                                <span class="badge badge-warning-light"><?= $bank['extensions'] ?></span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>


        <!-- ============================================ -->
        <!-- SECTION 4: DETAILED SUPPLIER ANALYSIS -->
        <!-- ============================================ -->
        <div class="section-separator">
            <span class="icon-lg">🔍</span>
            <span class="section-title">تصنيف أداء الموردين</span>
        </div>

        <!-- 2B: Supplier Analysis -->
        <div class="grid-3 mb-4">
            <div class="card">
                <div class="card-header border-bottom-0 pb-2">
                    <h4 class="font-bold text-base text-success">✓ الموردين الأكثر استقراراً</h4>
                </div>
                <div class="card-body pt-0">
                    <?php foreach ($stableSuppliers as $s): ?>
                    <div class="flex justify-between items-center py-2 border-bottom border-light">
                        <span class="text-sm"><?= htmlspecialchars($s['official_name']) ?></span>
                        <span class="badge badge-success-light"><?= $s['count'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header border-bottom-0 pb-2">
                    <h4 class="font-bold text-base text-danger">⚠️ الموردين عاليي المخاطر</h4>
                </div>
                <div class="card-body pt-0">
                    <?php foreach (array_slice($riskySuppliers, 0, 5) as $s): ?>
                    <div class="flex justify-between items-center py-2 border-bottom border-light">
                        <span class="text-sm"><?= htmlspecialchars($s['official_name']) ?></span>
                        <span class="text-xs font-bold text-danger"><?= $s['risk_score'] ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header border-bottom-0 pb-2">
                    <h4 class="font-bold text-base text-warning">🎯 الصعب مطابقتهم تلقائياً</h4>
                </div>
                <div class="card-body pt-0">
                    <?php foreach ($challengingSuppliers as $s): ?>
                    <div class="flex justify-between items-center py-2 border-bottom border-light">
                        <span class="text-sm"><?= htmlspecialchars($s['official_name']) ?></span>
                        <span class="badge badge-warning-light"><?= $s['manual_count'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 2C: Relationships -->
        <div class="grid-3 mb-4">
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= formatNumber($uniqueCounts['suppliers']) ?></div>
                <div class="text-xs text-muted">موردين فريدين</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= formatNumber($uniqueCounts['banks']) ?></div>
                <div class="text-xs text-muted">بنوك فريدة</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-info mb-1"><?= formatNumber($exclusiveSuppliers) ?></div>
                <div class="text-xs text-muted">موردين حصريين (بنك واحد)</div>
            </div>
        </div>
        
        <div class="card mb-5">
            <div class="card-header">
                <h4 class="card-title">أقوى التحالفات (بنك-مورد)</h4>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead><tr><th>المورد</th><th></th><th>البنك</th><th>التكرار</th></tr></thead>
                    <tbody>
                        <?php foreach ($bankSupplierPairs as $pair): ?>
                        <tr>
                            <td><?= htmlspecialchars($pair['supplier']) ?></td>
                            <td class="text-center text-muted">↔</td>
                            <td><?= htmlspecialchars($pair['bank']) ?></td>
                            <td class="font-bold"><?= $pair['count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SECTION 3: TIME & PERFORMANCE -->
        <!-- ============================================ -->
        <div class="section-separator">
            <span class="icon-lg">⏱️</span>
            <span class="section-title">الوقت والأداء</span>
        </div>

        <!-- 3A: Processing Speed -->
        <div class="grid-3 mb-4">
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= round($timing['avg_hours'] ?? 0, 1) ?>h</div>
                <div class="text-sm text-secondary">متوسط وقت المعالجة</div>
                <div class="text-xs text-muted mt-1">من الاستيراد → القرار</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-success mb-1"><?= round($timing['min_hours'] ?? 0, 1) ?>h</div>
                <div class="text-sm text-secondary">أسرع معالجة</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-danger mb-1"><?= round($timing['max_hours'] ?? 0, 1) ?>h</div>
                <div class="text-sm text-secondary">أبطأ معالجة</div>
            </div>
        </div>
        
        <div class="grid-2 mb-4">
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= $peakHour['hour'] ?? 'N/A' ?>:00</div>
                <div class="text-sm text-secondary">ساعة الذروة</div>
                <div class="text-xs text-muted mt-1"><?= formatNumber($peakHour['count'] ?? 0) ?> حدث</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= $busiestDay['weekday'] ?? 'N/A' ?></div>
                <div class="text-sm text-secondary">اليوم الأكثر نشاطاً</div>
                <div class="text-xs text-muted mt-1"><?= formatNumber($busiestDay['count'] ?? 0) ?> حدث</div>
            </div>
        </div>

        <!-- 3B: Quality Metrics -->
        <div class="grid-3 mb-4">
            <div class="card p-4 text-center bg-white border-success" style="border-top: 4px solid var(--accent-success);">
                <div class="text-4xl font-bold text-success mb-2"><?= $firstTimeRight ?>%</div>
                <div class="font-bold text-sm">First-Time-Right</div>
                <div class="text-xs text-muted mt-1">نسبة النجاح من أول مرة</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold mb-1" style="color: <?= $manualIntervention > 30 ? 'var(--accent-danger)' : 'var(--accent-success)' ?>;">
                    <?= $manualIntervention ?>%
                </div>
                <div class="text-sm text-secondary">معدل التدخل اليدوي</div>
                <div class="badge badge-neutral mt-2"><?= $manualIntervention > 30 ? 'يحتاج تحسين' : 'ممتاز' ?></div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= formatNumber($complexGuarantees) ?></div>
                <div class="text-sm text-secondary">ضمانات معقدة</div>
                <div class="text-xs text-muted mt-1">تم تعديلها 3+ مرات</div>
            </div>
        </div>

        <!-- 3C: Trends -->
        <div class="grid-3 mb-5">
            <div class="metric-mini-card">
                <div class="text-3xl font-bold mb-1" style="color: <?= $trendPercent >= 0 ? 'var(--accent-success)' : 'var(--accent-danger)' ?>;">
                    <?= $trendDirection ?>
                </div>
                <div class="text-sm text-secondary">النمو الأسبوعي</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= formatNumber($weeklyTrend['this_week']) ?></div>
                <div class="text-sm text-secondary">ضمانات هذا الأسبوع</div>
            </div>
            <div class="metric-mini-card">
                <div class="text-2xl font-bold text-primary mb-1"><?= formatNumber($weeklyTrend['last_week']) ?></div>
                <div class="text-sm text-secondary">ضمانات الأسبوع الماضي</div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SECTION 4: EXPIRATION & ACTION PLANNING -->
        <!-- ============================================ -->
        <div class="section-separator">
            <span class="icon-lg">📅</span>
            <span class="section-title">التخطيط والإجراءات</span>
        </div>

        <!-- 4A: Expiration Pressure -->
        <div class="grid-3 mb-4">
            <div class="card p-4 border-warning" style="background-color: var(--accent-warning-light); border: 1px solid var(--accent-warning);">
                <div class="text-3xl font-bold text-warning mb-1"><?= formatNumber($expiration['next_30']) ?></div>
                <div class="text-sm" style="color: #92400e;">تنتهي خلال 30 يوم</div>
            </div>
            <div class="card p-4 border-danger" style="background-color: var(--accent-danger-light); border: 1px solid var(--accent-danger);">
                <div class="text-3xl font-bold text-danger mb-1"><?= formatNumber($expiration['next_90']) ?></div>
                <div class="text-sm" style="color: #991b1b;">تنتهي خلال 90 يوم</div>
            </div>
            <div class="card p-4 text-center">
                <div class="text-2xl font-bold text-primary mb-1"><?= $peakMonth['month'] ?></div>
                <div class="text-sm text-secondary">الشهر الأخطر (<?= formatNumber($peakMonth['count']) ?>)</div>
            </div>
        </div>

        <!-- 4B: Monthly Distribution -->
        <?php if (!empty($expirationByMonth)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h4 class="card-title">توزيع الانتهاءات القادمة (12 شهر)</h4>
            </div>
            <div class="card-body">
                <?php foreach (array_slice($expirationByMonth, 0, 6) as $month): ?>
                <div class="flex items-center gap-3 mb-3 last:mb-0">
                    <span class="text-sm font-medium w-20"><?= $month['month'] ?></span>
                    <div class="flex-1">
                        <div class="progress-track" style="height: 6px;">
                            <div class="progress-fill bg-primary" style="background-color: var(--accent-primary); width: <?= min(($month['count'] / $peakMonth['count']) * 100, 100) ?>%;"></div>
                        </div>
                    </div>
                    <span class="text-sm font-bold w-10 text-right"><?= $month['count'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 4C: Actions & Probability -->
        <div class="grid-2 mb-5">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">الإجراءات المسجلة</h4>
                </div>
                <div class="card-body">
                    <div class="grid-3 gap-2 mb-4">
                        <div class="text-center p-2 rounded bg-secondary">
                            <div class="text-xl font-bold text-warning"><?= formatNumber($actions['extensions']) ?></div>
                            <div class="text-xs text-muted">تمديدات</div>
                        </div>
                        <div class="text-center p-2 rounded bg-secondary">
                            <div class="text-xl font-bold text-info"><?= formatNumber($actions['reductions']) ?></div>
                            <div class="text-xs text-muted">تخفيضات</div>
                        </div>
                        <div class="text-center p-2 rounded bg-secondary">
                            <div class="text-xl font-bold text-success"><?= formatNumber($actions['releases']) ?></div>
                            <div class="text-xs text-muted">إفراجات</div>
                        </div>
                    </div>
                    <div class="text-sm text-secondary bg-hover p-2 rounded">
                        <div class="flex justify-between mb-1">
                            <span>تمديدات متعددة:</span>
                            <strong class="text-primary"><?= formatNumber($multipleExtensions) ?></strong>
                        </div>
                        <div class="flex justify-between">
                            <span>إفراجات حديثة (7 أيام):</span>
                            <strong class="text-primary"><?= formatNumber($actions['recent_releases']) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">احتمالية التمديد (حسب المورد)</h4>
                </div>
                <div class="card-body">
                    <?php foreach ($extensionProbability as $prob): ?>
                    <div class="flex justify-between items-center mb-3 text-sm">
                        <span class="truncate pr-2"><?= htmlspecialchars($prob['official_name']) ?></span>
                        <div class="flex items-center gap-2">
                            <div class="progress-track w-24">
                                <div class="progress-fill" style="background-color: var(--accent-warning); width: <?= $prob['probability'] ?>%;"></div>
                            </div>
                            <span class="font-bold w-8 text-right"><?= $prob['probability'] ?>%</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SECTION 5: AI & MACHINE LEARNING -->
        <!-- ============================================ -->
        <div class="section-separator">
            <span class="icon-lg">🧠</span>
            <span class="section-title">الذكاء الاصطناعي والتعلم الآلي</span>
        </div>

        <div class="card mb-5 overflow-hidden border-0 shadow-lg" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color: white;">
            <!-- 5A: Main Metrics -->
            <div class="grid-4 p-5 border-bottom" style="border-color: rgba(255,255,255,0.1);">
                <div class="text-center">
                    <div class="text-4xl font-bold mb-1"><?= $mlAccuracy ?>%</div>
                    <div class="text-sm opacity-90">دقة التعلم</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold mb-1"><?= $automationRate ?>%</div>
                    <div class="text-sm opacity-90">معدل الأتمتة</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold mb-1"><?= $aiMatchRate ?>%</div>
                    <div class="text-sm opacity-90">نسبة تطابق AI</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold mb-1"><?= $mlStats['total'] ?></div>
                    <div class="text-sm opacity-90">أحداث التعلم</div>
                </div>
            </div>

            <!-- 5B: Patterns -->
            <div class="grid-2 p-5 gap-4">
                <?php if (!empty($confirmedPatterns)): ?>
                <div class="bg-white bg-opacity-10 rounded p-4">
                    <h4 class="font-bold text-sm mb-3 opacity-90">✅ الأنماط المؤكدة</h4>
                    <?php foreach ($confirmedPatterns as $p): ?>
                    <div class="flex justify-between items-center py-2 border-bottom" style="border-color: rgba(255,255,255,0.1);">
                        <div>
                            <div class="font-bold text-xs"><?= htmlspecialchars($p['raw_supplier_name']) ?></div>
                            <div class="text-xs opacity-70">→ <?= htmlspecialchars($p['official_name']) ?></div>
                        </div>
                        <span class="bg-success text-white text-xs px-2 py-1 rounded-full bg-opacity-80">×<?= $p['count'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($rejectedPatterns)): ?>
                <div class="bg-white bg-opacity-10 rounded p-4">
                    <h4 class="font-bold text-sm mb-3 opacity-90">❌ الأخطاء الشائعة</h4>
                    <?php foreach ($rejectedPatterns as $p): ?>
                    <div class="flex justify-between items-center py-2 border-bottom" style="border-color: rgba(255,255,255,0.1);">
                        <div>
                            <div class="font-bold text-xs"><?= htmlspecialchars($p['raw_supplier_name']) ?></div>
                            <div class="text-xs opacity-70">Suggested: <?= htmlspecialchars($p['official_name']) ?></div>
                        </div>
                        <span class="bg-danger text-white text-xs px-2 py-1 rounded-full bg-opacity-80">×<?= $p['count'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
             <!-- 5C: Performance Summary inside the colored card -->
             <div class="p-4 bg-black bg-opacity-20 flex justify-around">
                 <div class="text-center">
                     <div class="text-xl font-bold"><?= $timeSaved ?>h</div>
                     <div class="text-xs opacity-75">وقت موفر</div>
                 </div>
                 <div class="text-center">
                     <div class="text-xl font-bold"><?= formatNumber($autoMatchEvents) ?></div>
                     <div class="text-xs opacity-75">مطابقة تلقائية</div>
                 </div>
             </div>
        </div>

        <!-- ============================================ -->
        <!-- SECTION 6: FINANCIAL & TYPE ANALYSIS -->
        <!-- ============================================ -->
        <div class="section-separator">
            <span class="icon-lg">💰</span>
            <span class="section-title">التحليل المالي والأنواع</span>
        </div>

        <!-- 6A: Amount Analytics -->
        <div class="grid-4 mb-5">
            <div class="card p-3 text-center">
                <div class="text-xl font-bold text-primary mb-1"><?= formatMoney($overview['total_amount']) ?></div>
                <div class="text-xs text-muted">إجمالي المبالغ</div>
            </div>
            <div class="card p-3 text-center">
                <div class="text-xl font-bold text-primary mb-1"><?= formatMoney($overview['avg_amount']) ?></div>
                <div class="text-xs text-muted">متوسط المبلغ</div>
            </div>
            <div class="card p-3 text-center">
                <div class="text-xl font-bold text-primary mb-1"><?= formatMoney($overview['max_amount']) ?></div>
                <div class="text-xs text-muted">أكبر ضمان</div>
            </div>
            <div class="card p-3 text-center">
                <div class="text-xl font-bold text-primary mb-1"><?= formatMoney($overview['min_amount']) ?></div>
                <div class="text-xs text-muted">أصغر ضمان</div>
            </div>
        </div>

        <!-- 6B: Insights -->
        <div class="grid-2 mb-5">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">ارتباط المبلغ بالتمديد</h4>
                </div>
                <div class="card-body">
                    <?php foreach ($amountCorrelation as $corr): ?>
                    <div class="flex justify-between items-center p-2 mb-2 bg-hover rounded">
                        <span class="font-medium text-sm"><?= $corr['range'] ?></span>
                        <span class="badge <?= $corr['ext_rate'] > 30 ? 'badge-danger-light' : 'badge-success-light' ?>">
                            <?= $corr['ext_rate'] ?>% تمديد
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">توزيع الأنواع</h4>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <tbody>
                            <?php foreach ($typeDistribution as $type): ?>
                            <tr>
                                <td><?= htmlspecialchars($type['type']) ?></td>
                                <td class="text-left font-bold"><?= formatNumber($type['count']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="text-center p-5 text-muted text-xs mt-5 border-top border-light">
            <p>تم إنشاء هذه الإحصائيات في <?= date('Y-m-d H:i:s') ?></p>
            <p class="mt-1">BGL System v3.0 - Statistics Dashboard</p>
        </div>

    </div>
    <script src="../public/js/main.js"></script>
</body>
</html>