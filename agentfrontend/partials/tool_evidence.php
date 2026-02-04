<?php
$toolEvidence = $latestReport['tool_evidence'] ?? [];
?>
<div class="section-full">
  <h3 style="color: var(--text-secondary); font-size: 0.9rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem;">
    أدلة الأدوات
  </h3>
  <?php if ($toolEvidence): ?>
    <?php if (!empty($toolEvidence['route_index'])): ?>
      <div class="code-block">
        <strong>فهرسة المسارات</strong>
        <pre><?php echo htmlspecialchars(json_encode($toolEvidence['route_index'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
      </div>
    <?php endif; ?>
    <?php if (!empty($toolEvidence['run_checks'])): ?>
      <div class="code-block">
        <strong>تشغيل الفحوص</strong>
        <pre><?php echo htmlspecialchars(json_encode($toolEvidence['run_checks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <p style="color: var(--text-secondary);">لا توجد أدلة أدوات في التقرير الأخير.</p>
  <?php endif; ?>
</div>
