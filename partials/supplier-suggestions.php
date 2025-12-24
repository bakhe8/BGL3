<?php
/**
 * Partial: Supplier Suggestions
 * Returns HTML fragment for supplier suggestion chips
 * Used by: api/suggestions.php
 */

// $suggestions array must be provided by including script
if (!isset($suggestions)) {
    $suggestions = [];
}
?>

<div id="supplier-suggestions" class="chips-row">
    <?php if (empty($suggestions)): ?>
        <div style="font-size: 11px; color: #94a3b8; padding: 4px;">لا توجد اقتراحات</div>
    <?php else: ?>
        <?php foreach ($suggestions as $s): ?>
            <button class="chip chip-candidate" 
                    data-action="selectSupplier" 
                    data-id="<?= htmlspecialchars($s['id']) ?>" 
                    data-name="<?= htmlspecialchars($s['official_name']) ?>" 
                    data-score="<?= htmlspecialchars($s['score']) ?>">
                <?php if ($s['score'] > 90): ?>⭐ <?php endif; ?>
                <?= htmlspecialchars($s['official_name']) ?>
                <?php if ($s['score'] < 100): ?>
                    <span class="chip-source"><?= $s['score'] ?>%</span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
