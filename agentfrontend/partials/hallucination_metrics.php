<?php
// Resolve knowledge.db path safely (avoid undefined $agentDbPath => in-memory DB).
if (!isset($agentDbPath) || !is_string($agentDbPath) || $agentDbPath === '') {
    $agentDbPath = dirname(__DIR__, 2) . '/.bgl_core/brain/knowledge.db';
}

try {
    $conn = new PDO("sqlite:" . $agentDbPath);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Exception $e) {
    $conn = null;
}

// Get hallucination rate from llm_scores
$last24h = time() - 86400;
$scores = ['avg_score' => 0.7, 'total' => 0];
if ($conn) {
    try {
        $hasScores = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='llm_scores'")->fetchColumn();
        if ($hasScores) {
            $stmt = $conn->prepare("
                SELECT AVG(score) as avg_score, COUNT(*) as total
                FROM llm_scores
                WHERE timestamp >= ?
            ");
            $stmt->execute([$last24h]);
            $scores = $stmt->fetch(PDO::FETCH_ASSOC) ?: $scores;
        }
    } catch (\Exception $e) {
        // Keep defaults on error.
    }
}

$hallucination_rate = (1 - ($scores['avg_score'] ?? 0.7)) * 100;

// File hallucinations from outcomes
$file_errors = 0;
if ($conn) {
    try {
        $hasOutcomes = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='outcomes'")->fetchColumn();
        if ($hasOutcomes) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count
                FROM outcomes
                WHERE notes LIKE '%does not exist%'
                AND timestamp >= ?
            ");
            $stmt->execute([$last24h]);
            $file_errors = (int)($stmt->fetchColumn() ?: 0);
        }
    } catch (\Exception $e) {
        $file_errors = 0;
    }
}
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
