<?php
// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

/**
 * BGL System v3.0 - Statistics Page
 * ==================================
 * 
 * Comprehensive statistics dashboard for banking guarantees
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

header('Content-Type: text/html; charset=utf-8');

// Connect to database
$db = Database::connect();

// Calculate Statistics
try {
    // DEBUG: Check what's happening
    error_log("Statistics.php: Starting queries");
    error_log("Database instance: " . get_class($db));
    
    // Overview Statistics
    $stmt = $db->query("SELECT COUNT(*) as total FROM guarantees");
    $totalGuarantees = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    error_log("Total guarantees found: " . $totalGuarantees);
    
    $stmt = $db->query("SELECT COALESCE(SUM(CAST(json_extract(raw_data, '$.amount') AS REAL)), 0) as total_amount FROM guarantees");
    $totalAmount = $stmt->fetch(PDO::FETCH_ASSOC)['total_amount'];
    
    // Active vs Expired
    $stmt = $db->query("SELECT COUNT(*) as active FROM guarantees WHERE json_extract(raw_data, '$.expiry_date') >= date('now')");
    $activeCount = $stmt->fetch(PDO::FETCH_ASSOC)['active'];
    $expiredCount = $totalGuarantees - $activeCount;
    
    // Status Breakdown
    $stmt = $db->query("
        SELECT 
            COALESCE(d.supplier_id, 0) as has_supplier,
            COALESCE(d.bank_id, 0) as has_bank,
            COUNT(*) as count
        FROM guarantees g
        LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
        GROUP BY has_supplier, has_bank
    ");
    $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pending = 0;
    $ready = 0;
    foreach ($statusData as $row) {
        if ($row['has_supplier'] && $row['has_bank']) {
            $ready += $row['count'];
        } else {
            $pending += $row['count'];
        }
    }
    
    // Top Suppliers
    $stmt = $db->query("
        SELECT s.official_name, COUNT(*) as count
        FROM guarantee_decisions d
        JOIN suppliers s ON d.supplier_id = s.id
        WHERE d.supplier_id IS NOT NULL
        GROUP BY s.id, s.official_name
        ORDER BY count DESC
        LIMIT 10
    ");
    $topSuppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top Banks
    $stmt = $db->query("
        SELECT b.arabic_name as bank_name, COUNT(*) as count
        FROM guarantee_decisions d
        JOIN banks b ON d.bank_id = b.id
        WHERE d.bank_id IS NOT NULL
        GROUP BY b.id, b.arabic_name
        ORDER BY count DESC
        LIMIT 10
    ");
    $topBanks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Unique counts
    $stmt = $db->query("SELECT COUNT(DISTINCT supplier_id) as count FROM guarantee_decisions WHERE supplier_id IS NOT NULL");
    $uniqueSuppliers = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $db->query("SELECT COUNT(DISTINCT bank_id) as count FROM guarantee_decisions WHERE bank_id IS NOT NULL");
    $uniqueBanks = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Time-based analytics
    $stmt = $db->query("SELECT COUNT(*) as count FROM guarantees WHERE date(imported_at) >= date('now', 'start of month')");
    $thisMonth = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM guarantees WHERE json_extract(raw_data, '$.expiry_date') BETWEEN date('now') AND date('now', '+30 days')");
    $expiringNext30 = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Type distribution
    $stmt = $db->query("
        SELECT 
            json_extract(raw_data, '$.type') as type,
            COUNT(*) as count
        FROM guarantees
        GROUP BY type
        ORDER BY count DESC
    ");
    $typeDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Amount analytics
    if ($totalGuarantees > 0) {
        $avgAmount = $totalAmount / $totalGuarantees;
        $stmt = $db->query("SELECT MAX(CAST(json_extract(raw_data, '$.amount') AS REAL)) as max_amount FROM guarantees");
        $maxAmount = $stmt->fetch(PDO::FETCH_ASSOC)['max_amount'];
        $stmt = $db->query("SELECT MIN(CAST(json_extract(raw_data, '$.amount') AS REAL)) as min_amount FROM guarantees");
        $minAmount = $stmt->fetch(PDO::FETCH_ASSOC)['min_amount'];
    } else {
        $avgAmount = 0;
        $maxAmount = 0;
        $minAmount = 0;
    }
    
} catch (Exception $e) {
    // Set defaults on error
    $errorMessage = $e->getMessage();
    error_log("Statistics error: " . $errorMessage);
    
    $totalGuarantees = 0;
    $totalAmount = 0;
    $activeCount = 0;
    $expiredCount = 0;
    $pending = 0;
    $ready = 0;
    $topSuppliers = [];
    $topBanks = [];
    $uniqueSuppliers = 0;
    $uniqueBanks = 0;
    $thisMonth = 0;
    $expiringNext30 = 0;
    $typeDistribution = [];
    $avgAmount = 0;
    $maxAmount = 0;
    $minAmount = 0;
}

error_log("=== After main try-catch: ready=" . ($ready ?? 'UNDEF') . ", total=" . ($totalGuarantees ?? 'UNDEF'));

// ============================================
// 🚀 PHASE 1: INTELLIGENT KPIs
// ============================================

// DEBUG: Log variable values
error_log("KPI Calc - ready: " . ($ready ?? 'NULL') . ", total: " . ($totalGuarantees ?? 'NULL'));

// 1. Completion Rate (Ready vs Total)
$completionRate = $totalGuarantees > 0 ? round(((float)$ready / $totalGuarantees) * 100, 1) : 0;
error_log("KPI Calc - completionRate: " . $completionRate);

// 2. Processing Rate (guarantees processed per week)
// Get date of first guarantee import
$stmt = $db->query("SELECT MIN(imported_at) as first_import FROM guarantees");
$firstImport = $stmt->fetch(PDO::FETCH_ASSOC)['first_import'];

if ($firstImport) {
    $daysSinceStart = max(1, (strtotime('now') - strtotime($firstImport)) / 86400);
    $weeksSinceStart = max(1, $daysSinceStart / 7);
    $processingRate = round($ready / $weeksSinceStart, 1);
} else {
    $processingRate = 0;
}

// 3. AI Matching Success Rate
// Count guarantees with AI-matched suppliers or banks
$stmt = $db->query("
    SELECT COUNT(*) as ai_matched 
    FROM guarantee_decisions 
    WHERE decision_source IN ('ai_quick', 'direct_match', 'ai_match')
");
$aiMatched = $stmt->fetch(PDO::FETCH_ASSOC)['ai_matched'];
$aiSuccessRate = $totalGuarantees > 0 ? round(((float)$aiMatched / $totalGuarantees) * 100, 1) : 0;

// 4. Time Savings Calculation
// Assumption: Manual processing = 30 min/guarantee, AI processing = 4 min/guarantee
$manualTimePerGuarantee = 30; // minutes
$aiTimePerGuarantee = 4; // minutes
$timeSavedPerGuarantee = $manualTimePerGuarantee - $aiTimePerGuarantee; // 26 minutes
$totalTimeSaved = $aiMatched * $timeSavedPerGuarantee; // in minutes
$timeSavedHours = round($totalTimeSaved / 60, 1);
$timeSavedPercentage = round((1 - ($aiTimePerGuarantee / $manualTimePerGuarantee)) * 100, 0);

// 5. Average Response Time (from timeline events)
// NOTE: LAG() window function not properly supported in this SQLite version
// Commenting out for now - can be added later if needed
// $stmt = $db->query("
//     SELECT AVG(
//         CAST((julianday(created_at) - julianday(
//             LAG(created_at) OVER (PARTITION BY guarantee_id ORDER BY created_at)
//         )) * 24 AS REAL)
//     ) as avg_response_hours
//     FROM guarantee_history
//     WHERE event_type IN ('extension', 'reduction', 'release')
// ");
// $avgResponseHours = $stmt->fetch(PDO::FETCH_ASSOC)['avg_response_hours'];
$avgResponseDays = 0; // Placeholder until we implement alternative calculation

error_log("=== After KPI calculations: completionRate=$completionRate, processingRate=$processingRate, aiSuccessRate=$aiSuccessRate");



// Format number function
function formatNumber($num) {
    return number_format($num, 0, '.', ',');
}

function formatMoney($num) {
    return number_format($num, 2, '.', ',') . ' ر.س';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإحصائيات - BGL System v3.0</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --bg-secondary: #f8fafc;
            --border-primary: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --accent-primary: #3b82f6;
            --accent-success: #16a34a;
            --accent-warning: #d97706;
            --accent-danger: #dc2626;
            --font-family: 'Tajawal', sans-serif;
            --radius-md: 8px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: var(--font-family);
            background: var(--bg-body);
            color: var(--text-primary);
            padding: 20px;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: var(--bg-card);
            padding: 20px 24px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .btn {
            padding: 10px 20px;
            background: var(--accent-primary);
            color: white;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-block;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .grid {
            display: grid;
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .grid-4 {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }
        
        .grid-2 {
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        }
        
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-md);
        }
        
        .stat-card {
            text-align: center;
        }
        
        .stat-icon {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-primary);
            padding-bottom: 12px;
        }
        
        .list-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-primary);
        }
        
        .list-item:last-child {
            border-bottom: none;
        }
        
        .list-item-name {
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .list-item-value {
            color: var(--text-secondary);
            font-weight: 600;
        }
        
        .status-bar {
            display: flex;
            gap: 16px;
            margin-top: 16px;
        }
        
        .status-item {
            flex: 1;
            text-align: center;
            padding: 16px;
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
        }
        
        .status-item.pending {
            background: rgba(217, 119, 6, 0.1);
            color: var(--accent-warning);
        }
        
        .status-item.approved {
            background: rgba(22, 163, 74, 0.1);
            color: var(--accent-success);
        }
        
        .status-item.expired {
            background: rgba(220, 38, 38, 0.1);
            color: var(--accent-danger);
        }
        
        .status-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .status-label {
            font-size: 13px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        
        @media (max-width: 768px) {
            .grid-4, .grid-2 {
                grid-template-columns: 1fr;
            }
            
            .status-bar {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📊 إحصائيات النظام</h1>
            <a href="../index.php" class="btn">العودة للرئيسية</a>
        </div>
        
        <?php if ($totalGuarantees === 0): ?>
            <!-- Empty State -->
            <div class="card">
                <div class="empty-state">
                    <div class="empty-state-icon">📊</div>
                    <h2>لا توجد بيانات للإحصائيات</h2>
                    <p>قاعدة البيانات فارغة. ابدأ بإضافة ضمانات بنكية لعرض الإحصائيات.</p>
                    <?php if (isset($errorMessage)): ?>
                        <div style="margin-top: 20px; padding: 15px; background: #fee; border-radius: 8px; color: #c00;">
                            <strong>خطأ:</strong> <?= htmlspecialchars($errorMessage) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            
            <!-- Overview Cards -->
            <div class="grid grid-4">
                <div class="card stat-card">
                    <div class="stat-icon">📋</div>
                    <div class="stat-value"><?= formatNumber($totalGuarantees) ?></div>
                    <div class="stat-label">إجمالي الضمانات</div>
                </div>
                
                <div class="card stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value"><?= formatMoney($totalAmount) ?></div>
                    <div class="stat-label">إجمالي المبالغ</div>
                </div>
                
                <div class="card stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-value"><?= formatNumber($activeCount) ?></div>
                    <div class="stat-label">ضمانات نشطة</div>
                </div>
                
                <div class="card stat-card">
                    <div class="stat-icon">⏰</div>
                    <div class="stat-value"><?= formatNumber($expiredCount) ?></div>
                    <div class="stat-label">ضمانات منتهية</div>
                </div>
            </div>
            
            <!-- 🚀 INTELLIGENT PERFORMANCE METRICS -->
            <div class="card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 24px;">
                <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 20px; border-bottom: 2px solid rgba(255,255,255,0.3); padding-bottom: 12px;">
                    ⚡ مؤشرات الأداء الذكية
                </h2>
                <div class="grid grid-4">
                    <div style="text-align: center; padding: 16px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                        <div style="font-size: 36px; font-weight: 700; margin-bottom: 8px;">
                            <?= $completionRate ?>%
                        </div>
                        <div style="font-size: 13px; opacity: 0.9;">نسبة الإنجاز</div>
                        <div style="font-size: 11px; opacity: 0.7; margin-top: 4px;">
                            <?= formatNumber($ready) ?> من <?= formatNumber($totalGuarantees) ?>
                        </div>
                    </div>
                    
                    <div style="text-align: center; padding: 16px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                        <div style="font-size: 36px; font-weight: 700; margin-bottom: 8px;">
                            <?= $processingRate ?>
                        </div>
                        <div style="font-size: 13px; opacity: 0.9;">ضمان/أسبوع</div>
                        <div style="font-size: 11px; opacity: 0.7; margin-top: 4px;">معدل المعالجة</div>
                    </div>
                    
                    <div style="text-align: center; padding: 16px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                        <div style="font-size: 36px; font-weight: 700; margin-bottom: 8px;">
                            <?= $aiSuccessRate ?>%
                        </div>
                        <div style="font-size: 13px; opacity: 0.9;">نجاح الذكاء الاصطناعي</div>
                        <div style="font-size: 11px; opacity: 0.7; margin-top: 4px;">
                            <?= formatNumber($aiMatched) ?> مطابقة تلقائية
                        </div>
                    </div>
                    
                    <div style="text-align: center; padding: 16px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                        <div style="font-size: 36px; font-weight: 700; margin-bottom: 8px;">
                            <?= $timeSavedPercentage ?>%
                        </div>
                        <div style="font-size: 13px; opacity: 0.9;">توفير الوقت</div>
                        <div style="font-size: 11px; opacity: 0.7; margin-top: 4px;">
                            ~<?= $timeSavedHours ?> ساعة موفرة
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Status Breakdown -->
            <div class="card">
                <h2 class="card-title">حالة الضمانات</h2>
                <div class="status-bar">
                    <div class="status-item pending">
                        <div class="status-value"><?= formatNumber($pending) ?></div>
                        <div class="status-label">قيد المعالجة</div>
                    </div>
                    <div class="status-item approved">
                        <div class="status-value"><?= formatNumber($ready) ?></div>
                        <div class="status-label">معتمد</div>
                    </div>
                </div>
            </div>
            
            <!-- Top 10 Lists -->
            <div class="grid grid-2">
                <!-- Top Suppliers -->
                <div class="card">
                    <h2 class="card-title">أكثر 10 موردين</h2>
                    <?php if (empty($topSuppliers)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 20px 0;">لا توجد بيانات موردين</p>
                    <?php else: ?>
                        <?php foreach ($topSuppliers as $supplier): ?>
                            <div class="list-item">
                                <span class="list-item-name"><?= htmlspecialchars($supplier['official_name']) ?></span>
                                <span class="list-item-value"><?= formatNumber($supplier['count']) ?> ضمان</span>
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-primary); color: var(--text-secondary); font-size: 13px;">
                            إجمالي الموردين: <?= formatNumber($uniqueSuppliers) ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Top Banks -->
                <div class="card">
                    <h2 class="card-title">أكثر 10 بنوك</h2>
                    <?php if (empty($topBanks)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 20px 0;">لا توجد بيانات بنوك</p>
                    <?php else: ?>
                        <?php foreach ($topBanks as $bank): ?>
                            <div class="list-item">
                                <span class="list-item-name"><?= htmlspecialchars($bank['bank_name']) ?></span>
                                <span class="list-item-value"><?= formatNumber($bank['count']) ?> ضمان</span>
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-primary); color: var(--text-secondary); font-size: 13px;">
                            إجمالي البنوك: <?= formatNumber($uniqueBanks) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Time & Type Analytics -->
            <div class="grid grid-2">
                <!-- Time-based -->
                <div class="card">
                    <h2 class="card-title">التحليلات الزمنية</h2>
                    <div class="list-item">
                        <span class="list-item-name">ضمانات هذا الشهر</span>
                        <span class="list-item-value"><?= formatNumber($thisMonth) ?></span>
                    </div>
                    <div class="list-item">
                        <span class="list-item-name">تنتهي خلال 30 يوم</span>
                        <span class="list-item-value"><?= formatNumber($expiringNext30) ?></span>
                    </div>
                </div>
                
                <!-- Type distribution -->
                <div class="card">
                    <h2 class="card-title">حسب النوع</h2>
                    <?php if (empty($typeDistribution)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 20px 0;">لا توجد بيانات</p>
                    <?php else: ?>
                        <?php foreach ($typeDistribution as $type): ?>
                            <div class="list-item">
                                <span class="list-item-name"><?= htmlspecialchars($type['type'] ?: 'غير محدد') ?></span>
                                <span class="list-item-value"><?= formatNumber($type['count']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Amount Analytics -->
            <div class="card">
                <h2 class="card-title">تحليلات المبالغ</h2>
                <div class="grid grid-4">
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--accent-primary); margin-bottom: 4px;">
                            <?= formatMoney($avgAmount) ?>
                        </div>
                        <div style="font-size: 13px; color: var(--text-secondary);">متوسط المبلغ</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--accent-success); margin-bottom: 4px;">
                            <?= formatMoney($maxAmount) ?>
                        </div>
                        <div style="font-size: 13px; color: var(--text-secondary);">أكبر ضمان</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--accent-warning); margin-bottom: 4px;">
                            <?= formatMoney($minAmount) ?>
                        </div>
                        <div style="font-size: 13px; color: var(--text-secondary);">أصغر ضمان</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px;">
                            <?= formatMoney($totalAmount) ?>
                        </div>
                        <div style="font-size: 13px; color: var(--text-secondary);">الإجمالي</div>
                    </div>
                </div>
            </div>
            
        <?php endif; ?>
    </div>
</body>
</html>
