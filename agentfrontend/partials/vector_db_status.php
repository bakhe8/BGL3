<?php
// Ensure DB path is available even if $agentDbPath was not set upstream.
if (!isset($agentDbPath) || !is_string($agentDbPath) || $agentDbPath === '') {
    $agentDbPath = dirname(__DIR__, 2) . '/.bgl_core/brain/knowledge.db';
}

$indexed = 0;
$db_ok = false;

try {
    $db = new PDO("sqlite:" . $agentDbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hasEmbeddings = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='embeddings'")->fetchColumn();
    if ($hasEmbeddings) {
        $indexed = (int)$db->query("SELECT COUNT(*) FROM embeddings")->fetchColumn();
        $db_ok = true;
    }
} catch (\Throwable $e) {
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
