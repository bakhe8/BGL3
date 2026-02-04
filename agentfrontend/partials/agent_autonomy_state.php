<?php
$llmState = strtoupper((string)($llmStatus['state'] ?? 'UNKNOWN'));
$llmStateLabel = $llmState === 'HOT' ? 'حار' : ($llmState === 'COLD' ? 'بارد' : 'غير معروف');
$llmModel = (string)($llmStatus['model'] ?? '');
$llmBase = (string)($llmStatus['base_url'] ?? '');
$volText = (string)($volition['volition'] ?? '');
$volConf = $volition['confidence'] ?? null;
$volSource = (string)($volition['source'] ?? '');
$autoStatus = (string)($autonomousPolicy['status'] ?? '');
$autoAction = (string)($autonomousPolicy['action'] ?? '');
$autoAdded = is_array($autonomousPolicy['added'] ?? null) ? count($autonomousPolicy['added']) : null;
$autoUpdated = is_array($autonomousPolicy['updated'] ?? null) ? count($autonomousPolicy['updated']) : null;
$autoRemoved = is_array($autonomousPolicy['removed'] ?? null) ? count($autonomousPolicy['removed']) : null;
$deltaHighlights = $envDelta['payload']['highlights'] ?? [];
$deltaSummary = $envDelta['payload']['summary']['changed_keys'] ?? null;
?>
<section class="glass-card" id="agent-autonomy-state">
  <div class="card-header">حالة الاستقلالية والسياق</div>

  <div class="section-grid-auto">
    <div>
      <div style="color: var(--text-secondary); font-size:0.85rem;">حالة النموذج</div>
      <div style="font-weight:700; color: <?= $llmState === 'HOT' ? 'var(--success)' : 'var(--accent-gold)' ?>;" data-live="llm-state">
        <?= htmlspecialchars($llmStateLabel) ?>
      </div>
      <?php if ($llmModel): ?><div style="font-size:0.8rem; color: var(--text-secondary);" data-live="llm-model"><?= htmlspecialchars($llmModel) ?></div><?php endif; ?>
      <?php if ($llmBase): ?><div style="font-size:0.75rem; color: var(--text-secondary);" data-live="llm-base"><?= htmlspecialchars($llmBase) ?></div><?php endif; ?>
    </div>
  </div>

  <div style="margin-top:16px; display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
    <div style="border:1px solid var(--border-color); padding:12px; border-radius:10px;">
      <div style="font-weight:700;">الإرادة</div>
      <?php if ($volText): ?>
        <div><?= htmlspecialchars($volText) ?></div>
        <div style="color: var(--text-secondary); font-size:0.8rem;">
          <?= $volSource ? 'المصدر: ' . htmlspecialchars($volSource) : '' ?>
          <?= $volConf !== null ? ' | الثقة: ' . htmlspecialchars($volConf) : '' ?>
        </div>
      <?php else: ?>
        <div style="color: var(--text-secondary);">لا توجد إرادة مسجلة.</div>
      <?php endif; ?>
    </div>

    <div style="border:1px solid var(--border-color); padding:12px; border-radius:10px;">
      <div style="font-weight:700;">السياسة الذاتية</div>
      <?php if ($autoStatus): ?>
        <div>الحالة: <?= htmlspecialchars($autoStatus) ?></div>
        <?php if ($autoAction): ?><div>الإجراء: <?= htmlspecialchars($autoAction) ?></div><?php endif; ?>
        <div style="color: var(--text-secondary); font-size:0.8rem;">
          <?= $autoAdded !== null ? 'أضيف: ' . htmlspecialchars($autoAdded) : '' ?>
          <?= $autoUpdated !== null ? ' | تم تحديث: ' . htmlspecialchars($autoUpdated) : '' ?>
          <?= $autoRemoved !== null ? ' | تم حذف: ' . htmlspecialchars($autoRemoved) : '' ?>
        </div>
      <?php else: ?>
        <div style="color: var(--text-secondary);">لا توجد تغييرات سياسة ذاتية.</div>
      <?php endif; ?>
    </div>

    <div style="border:1px solid var(--border-color); padding:12px; border-radius:10px;">
      <div style="font-weight:700;">أبرز التغييرات</div>
      <?php if (!empty($deltaHighlights)): ?>
        <div style="font-size:0.85rem; color: var(--text-secondary);">المفاتيح المتغيرة: <?= htmlspecialchars((string)($deltaSummary ?? '')) ?></div>
        <ul style="margin:6px 0 0 18px;">
          <?php foreach (array_slice($deltaHighlights, 0, 6) as $h): ?>
            <li><?= htmlspecialchars($h['key'] ?? '') ?>: <?= htmlspecialchars((string)($h['from'] ?? '')) ?> → <?= htmlspecialchars((string)($h['to'] ?? '')) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div style="color: var(--text-secondary);">لا يوجد اختلافات جديدة.</div>
      <?php endif; ?>
    </div>
  </div>

  <div style="margin-top:16px;">
    <div class="card-header" style="border:none; padding-bottom:0;">أحداث الاستقلالية</div>
    <?php if (!empty($autonomyEvents)): ?>
      <table style="width:100%; border-collapse: collapse;">
        <tr>
          <th>النوع</th><th>المسار</th><th>الطريقة</th><th>الحالة</th><th>الوقت</th>
        </tr>
        <?php foreach ($autonomyEvents as $e): ?>
          <tr>
            <td><?= htmlspecialchars($e['event_type'] ?? '') ?></td>
            <td><?= htmlspecialchars($e['route'] ?? '') ?></td>
            <td><?= htmlspecialchars($e['method'] ?? '') ?></td>
            <td><?= htmlspecialchars((string)($e['status'] ?? '')) ?></td>
            <td><?= isset($e['timestamp']) ? date('H:i:s', (int)$e['timestamp']) : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <div style="color: var(--text-secondary);">لا توجد أحداث استقلالية مسجلة بعد.</div>
    <?php endif; ?>
  </div>
</section>
