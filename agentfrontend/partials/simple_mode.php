<?php
// Simple user mode: Arabic-only concise summary + two actions
$health = $latestReport['health_score'] ?? null;
$fail = $latestReport['failing_routes'] ?? [];
$suggest = $latestReport['suggestions'] ?? [];
$summary_line = $health !== null
    ? (($health >= 90) ? "النظام سليم إجمالاً." : "النظام يحتاج مراجعة لبعض المسارات.")
    : "لم يتم تنفيذ فحص بعد.";

// Pick top 3 plain suggestions
$simple_suggestions = array_slice($suggest, 0, 3);
?>
<div class="section-full simple-mode">
  <h3>وضع المستخدم البسيط</h3>
  <p><?= htmlspecialchars($summary_line) ?></p>
  <p>
    <strong>أهم المشكلات:</strong>
    <?php if ($fail): ?>
      <?php $uris = array_map(fn($r) => $r['uri'] ?? 'مسار غير معروف', array_slice($fail, 0, 3)); ?>
      <?= htmlspecialchars(implode(' ، ', $uris)) ?>
    <?php else: ?>
      لا توجد مسارات فاشلة حالياً.
    <?php endif; ?>
  </p>
  <div>
    <strong>اقتراحات سريعة:</strong>
    <ul>
      <?php if ($simple_suggestions): ?>
        <?php foreach ($simple_suggestions as $s): ?>
          <li><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
      <?php else: ?>
        <li>لا توجد اقتراحات حالياً.</li>
      <?php endif; ?>
    </ul>
  </div>
  <div style="display:flex; gap:10px; margin-top:10px;">
    <form method="POST">
      <input type="hidden" name="action" value="assure">
      <button type="submit" class="btn-primary">تشغيل فحص شامل الآن</button>
    </form>
    <form method="POST">
      <input type="hidden" name="action" value="auto_fix_simple">
      <button type="submit" class="btn-secondary">تطبيق الإصلاحات المقترحة</button>
    </form>
  </div>
  <p style="margin-top:10px; color: var(--text-secondary); font-size:0.9rem;">
    تلميح: يمكنك الضغط على “تشغيل فحص شامل” ثم مراجعة الملخص أعلاه. إذا استمر ظهور مشكلة، اضغط “تأكيد الحل اليدوي” بجوارها بعد إصلاحها.
  </p>
</div>
