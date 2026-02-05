<?php
try {
    $indexed = $conn->query("SELECT COUNT(*) FROM embeddings")->fetchColumn();
    $db_ok = true;
} catch (Exception $e) {
    $indexed = 0;
    $db_ok = false;
}
?>

<section class="glass-card">
    <div class="card-header">🔍 Vector Database</div>
    <div class="section-grid-auto">
        <div class="stat-box">
            <div class="stat-value" style="color: <?= $db_ok ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= $db_ok ? 'متصل' : 'غير متصل' ?>
            </div>
            <div class="stat-label">
                <?= number_format($indexed) ?> عنصر مفهرس
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent);">LRU</div>
            <div class="stat-label">Cache Enabled</div>
        </div>
    </div>
</section>
