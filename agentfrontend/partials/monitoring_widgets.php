<?php
/**
 * Visual Monitoring Widgets for Agent Dashboard
 * BGL3 Document Issuance System
 * Provides real-time charts and alerts for operational monitoring
 */

// Ensure core autoload for App\ classes when this partial is accessed directly.
require_once __DIR__ . '/../../app/Support/autoload.php';

// Get current operational stats from StatsService (guarded for missing classes)
$operationalStats = [
    'import_success_rate' => 0,
    'api_error_rate' => 0,
    'contract_latency_ms' => 0,
    'active_guarantees' => 0,
    'expired_guarantees' => 0,
    'pending_guarantees' => 0,
];

if (class_exists('App\\Services\\StatsService')) {
    $statsService = new App\Services\StatsService();
    $metrics = $statsService->getDashboardMetrics();
    if (is_array($metrics)) {
        $operationalStats = array_merge($operationalStats, $metrics);
    }
} else {
    error_log('monitoring_widgets: App\\Services\\StatsService not found');
}

// Get active alerts from Alert system (guarded)
$activeAlerts = [];
if (class_exists('App\\Support\\Alert')) {
    $alerts = App\Support\Alert::getActiveAlerts();
    if (is_array($alerts)) {
        $activeAlerts = $alerts;
    }
} else {
    error_log('monitoring_widgets: App\\Support\\Alert not found');
}
?>

<div class="monitoring-section">
    <h3>أدوات المراقبة البصرية</h3>
    
    <!-- Operational Performance Charts -->
    <div class="chart-container">
        <div class="chart-card">
            <h4>مخططات الأداء التشغيلي</h4>
            
            <!-- Import Success Rate Chart -->
            <div class="chart-item">
                <h5>معدل نجاح الاستيراد</h5>
                <div class="chart-wrapper">
                    <div class="progress-chart">
                        <div class="progress-bar" style="width: <?php echo $operationalStats['import_success_rate']; ?>%">
                            <span><?php echo $operationalStats['import_success_rate']; ?>%</span>
                        </div>
                    </div>
                    <div class="chart-target">الهدف: ≥ 99%</div>
                </div>
            </div>
            
            <!-- API Error Rate Chart -->
            <div class="chart-item">
                <h5>معدل أخطاء API</h5>
                <div class="chart-wrapper">
                    <div class="error-chart">
                        <div class="error-bar" style="width: <?php echo min($operationalStats['api_error_rate'] * 100, 100); ?>%">
                            <span><?php echo $operationalStats['api_error_rate']; ?>%</span>
                        </div>
                    </div>
                    <div class="chart-target">الهدف: ≤ 1%</div>
                </div>
            </div>
            
            <!-- Contract Latency Chart -->
            <div class="chart-item">
                <h5>زمن استجابة العقود (مللي ثانية)</h5>
                <div class="chart-wrapper">
                    <div class="latency-chart">
                        <div class="latency-indicator" style="width: <?php echo min(($operationalStats['contract_latency_ms'] / 1000) * 100, 100); ?>%">
                            <span><?php echo $operationalStats['contract_latency_ms']; ?> مللي ثانية</span>
                        </div>
                    </div>
                    <div class="chart-target">الهدف: ≤ 1000 مللي ثانية</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- System Alerts -->
    <div class="alerts-container">
        <div class="alerts-card">
            <h4>تنبيهات النظام</h4>
            
            <?php if (empty($activeAlerts)): ?>
                <div class="alert-item alert-success">
                    <span class="alert-icon">✓</span>
                    <span class="alert-message">جميع الأنظمة تعمل بشكل طبيعي</span>
                </div>
            <?php else: ?>
                <?php foreach ($activeAlerts as $alert): ?>
                    <div class="alert-item alert-<?php echo $alert['level']; ?>">
                        <span class="alert-icon">⚠️</span>
                        <span class="alert-message"><?php echo $alert['message']; ?></span>
                        <span class="alert-time"><?php echo $alert['timestamp']; ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Guarantee Status Overview -->
            <div class="status-overview">
                <h5>نظرة عامة على حالة الضمانات</h5>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="status-count"><?php echo $operationalStats['active_guarantees']; ?></span>
                        <span class="status-label">نشطة</span>
                    </div>
                    <div class="status-item">
                        <span class="status-count"><?php echo $operationalStats['expired_guarantees']; ?></span>
                        <span class="status-label">منتهية</span>
                    </div>
                    <div class="status-item">
                        <span class="status-count"><?php echo $operationalStats['pending_guarantees']; ?></span>
                        <span class="status-label">قيد المعالجة</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.monitoring-section {
    margin: 20px 0;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
}

.chart-container, .alerts-container {
    margin-bottom: 30px;
}

.chart-card, .alerts-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.chart-item {
    margin-bottom: 20px;
}

.chart-wrapper {
    margin-top: 10px;
}

.progress-chart, .error-chart, .latency-chart {
    background: #e9ecef;
    height: 30px;
    border-radius: 15px;
    overflow: hidden;
    position: relative;
}

.progress-bar {
    background: #28a745;
    height: 100%;
    transition: width 0.3s ease;
}

.error-bar {
    background: #dc3545;
    height: 100%;
    transition: width 0.3s ease;
}

.latency-indicator {
    background: #007bff;
    height: 100%;
    transition: width 0.3s ease;
}

.progress-bar span, .error-bar span, .latency-indicator span {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: white;
    font-weight: bold;
}

.chart-target {
    font-size: 12px;
    color: #6c757d;
    margin-top: 5px;
}

.alert-item {
    display: flex;
    align-items: center;
    padding: 10px;
    margin-bottom: 10px;
    border-radius: 5px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
}

.alert-warning {
    background: #fff3cd;
    color: #856404;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
}

.alert-icon {
    margin-left: 10px;
    font-weight: bold;
}

.alert-time {
    margin-right: auto;
    font-size: 12px;
    color: #6c757d;
}

.status-overview {
    margin-top: 20px;
}

.status-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.status-item {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 5px;
}

.status-count {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #007bff;
}

.status-label {
    font-size: 14px;
    color: #6c757d;
}
</style>
