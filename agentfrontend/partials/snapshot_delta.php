<div class="glass-card">
    <div class="card-header">ملخص التغيّر (Delta)</div>
    <?php
      $summary = $deltaSnapshot['summary'] ?? [];
      $highlights = $deltaSnapshot['highlights'] ?? [];
      $changed = (int)($summary['changed_keys'] ?? 0);
    ?>
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <div style="font-size:0.9rem; color: var(--text-secondary);">عدد التغييرات:</div>
        <div data-live="delta-changed" style="font-weight:700; color: var(--accent-gold);"><?= $changed ?></div>
    </div>
    <div id="delta-list" style="margin-top:8px;">
        <?php if (empty($highlights)): ?>
            <p style="color: var(--text-secondary); font-style: italic;">لا يوجد تغيّر مهم في آخر Snapshot.</p>
        <?php else: ?>
            <?php foreach($highlights as $h): ?>
                <div style="padding:6px 0; border-bottom:1px solid var(--glass-border);">
                    <div style="font-size:0.85rem; color: var(--text-secondary);"><?= htmlspecialchars($h['key'] ?? '') ?></div>
                    <div style="font-size:0.9rem;">
                        <?= htmlspecialchars((string)($h['from'] ?? 'null')) ?> → <?= htmlspecialchars((string)($h['to'] ?? 'null')) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
