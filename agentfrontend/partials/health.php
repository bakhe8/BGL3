<div class="glass-card">
    <div class="card-header">الحالة الحيوية (System Vitals)</div>
    <?php foreach($vitals as $v): ?>
        <div class="vital-row">
            <span class="vital-name"><?= $v['name'] ?></span>
            <span class="vital-status <?= $v['ok'] ? 'status-ok' : 'status-warn' ?>">
                <?= $v['status'] ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>

<div class="glass-card hero-score">
    <div class="card-header">مؤشر الصحة (Agent Vitality)</div>
    <svg viewBox="0 0 36 36" class="circular-chart">
        <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
        <path class="circle" stroke="<?= $stats['health_score'] > 80 ? 'var(--success)' : 'var(--accent-gold)' ?>" 
            stroke-dasharray="<?= $stats['health_score'] ?>, 100" 
            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
        <text x="18" y="20.35" class="score-text"><?= $stats['health_score'] ?>%</text>
    </svg>
    <div class="stat-label" style="margin-top: 15px;">كفاءة النظام التقديرية</div>
</div>
