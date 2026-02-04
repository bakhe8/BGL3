<div class="glass-card">
    <div class="card-header">الحالة الحيوية للنظام</div>
    <?php foreach($vitals as $key => $v): ?>
        <div class="vital-row">
            <span class="vital-name"><?= $v['name'] ?></span>
            <span class="vital-status <?= $v['ok'] ? 'status-ok' : 'status-warn' ?>" data-live="vital-<?= htmlspecialchars($key) ?>-status">
                <?= $v['status'] ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>

<?php
$scoreRaw = $stats['health_score'] ?? null;
$score = is_numeric($scoreRaw) ? max(0, min(100, (float)$scoreRaw)) : null;
$scoreColor = $score === null ? 'var(--border-color)' : ($score > 80 ? 'var(--success)' : 'var(--accent-gold)');
$scoreText = $score === null ? 'غير متوفر' : (round($score) . '%');
$dash = $score === null ? 0 : $score;
?>
<div class="glass-card hero-score">
    <div class="card-header">مؤشر الصحة للوكيل</div>
    <svg viewBox="0 0 36 36" class="circular-chart">
        <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
        <path class="circle" stroke="<?= $scoreColor ?>" data-live="health-score-dash"
            stroke-dasharray="<?= $dash ?>, 100" 
            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
        <text x="18" y="20.35" class="score-text" data-live="health-score-text"><?= $scoreText ?></text>
    </svg>
    <div class="stat-label" style="margin-top: 15px;">كفاءة النظام التقديرية</div>
</div>
