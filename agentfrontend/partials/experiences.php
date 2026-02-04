<div class="glass-card" style="grid-column: span 2;">
    <div class="card-header">ذاكرة الخبرة (Experiences)</div>
    <?php if (empty($experiences)): ?>
        <p style="color: var(--text-secondary); font-style: italic;">لا توجد خبرات جديدة قابلة للتفاعل حالياً.</p>
    <?php else: ?>
        <?php foreach($experiences as $exp): ?>
            <div class="exp-item" data-exp-hash="<?= htmlspecialchars($exp['exp_hash'] ?? '') ?>" style="border-bottom: 1px solid var(--glass-border); padding: 10px 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; gap:10px;">
                    <strong style="color: var(--accent-gold);"><?= htmlspecialchars($exp['scenario']) ?></strong>
                    <span style="font-size: 0.85rem; color: var(--text-secondary);">ثقة <?= round($exp['confidence'] * 100) ?>%</span>
                </div>
                <p style="margin: 6px 0; color: var(--text-primary);"><?= htmlspecialchars($exp['summary']) ?></p>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                    <span style="font-size: 0.75rem; color: var(--text-secondary);">أدلة: <?= (int)$exp['evidence_count'] ?> | وقت: <?= date('H:i', (int)$exp['created_at']) ?></span>
                    <div style="display:flex; gap:6px;">
                        <button class="mini-btn" data-exp-action="promote" data-exp-scenario="<?= htmlspecialchars($exp['scenario']) ?>" data-exp-summary="<?= htmlspecialchars($exp['summary']) ?>">تحويل لاقتراح</button>
                        <button class="mini-btn" data-exp-action="accepted" data-exp-scenario="<?= htmlspecialchars($exp['scenario']) ?>" data-exp-summary="<?= htmlspecialchars($exp['summary']) ?>">تمت المعالجة</button>
                        <button class="mini-btn danger" data-exp-action="ignored" data-exp-scenario="<?= htmlspecialchars($exp['scenario']) ?>" data-exp-summary="<?= htmlspecialchars($exp['summary']) ?>">تجاهل</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
  .mini-btn {
    background: #1f2a3a;
    color: #e8eef7;
    border: 1px solid #2e3a4d;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 0.78rem;
    cursor: pointer;
  }
  .mini-btn:hover { border-color: #4b5a75; }
  .mini-btn.danger {
    background: #351a1a;
    border-color: #5a2626;
    color: #ffd6d6;
  }
</style>
