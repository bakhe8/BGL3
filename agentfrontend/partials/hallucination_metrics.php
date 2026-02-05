<?php
$conn = new PDO("sqlite:" . $agentDbPath);

// Get hallucination rate from llm_scores
$last24h = time() - 86400;
$scores = $conn->query("
    SELECT AVG(score) as avg_score, COUNT(*) as total
    FROM llm_scores
    WHERE timestamp >= $last24h
")->fetch(PDO::FETCH_ASSOC);

$hallucination_rate = (1 - ($scores['avg_score'] ?? 0.7)) * 100;

// File hallucinations from outcomes
$file_errors = $conn->query("
    SELECT COUNT(*) as count
    FROM outcomes
    WHERE notes LIKE '%does not exist%'
    AND created_at >= $last24h
")->fetchColumn();
?>

<section class="glass-card">
    <div class="card-header">📊 مقاييس الهلوسة</div>
    <div class="section-grid-auto">
        <div class="stat-box">
            <div class="stat-value"
                style="color: <?= $hallucination_rate > 30 ? 'var(--danger)' : 'var(--success)' ?>;">
                <?= number_format($hallucination_rate, 1) ?>%
            </div>
            <div class="stat-label">معدل الهلوسة (آخر 24 ساعة)</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= $file_errors ?></div>
            <div class="stat-label">أخطاء أسماء ملفات</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= $scores['total'] ?? 0 ?></div>
            <div class="stat-label">إجمالي الاستعلامات</div>
        </div>
    </div>
</section>