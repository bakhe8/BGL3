<?php
$cfg = $effectiveCfg ?? [];
$sources = $effectiveSources ?? [];

function bgl_cfg_get($data, $path, $default = '') {
    if (!$path) return $default;
    $parts = explode('.', $path);
    $cur = $data;
    foreach ($parts as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) return $default;
        $cur = $cur[$p];
    }
    return $cur;
}

$keyList = [
    'execution_mode',
    'agent_mode',
    'decision.mode',
    'base_url',
    'run_scenarios',
    'scenario_exploration',
    'autonomous_scenario',
    'autonomous_only',
    'keep_browser',
    'headless',
    'llm.base_url',
    'llm.model',
];

$flagOverrides = 0;
foreach ($sources as $src) {
    if ($src === 'flags') $flagOverrides++;
}
?>

<section class="glass-card">
  <div class="card-header">الإعدادات الفعّالة (المصدر)</div>
  <p style="color: var(--text-secondary); font-size:0.85rem; margin-top:-4px;">
    المصدر: <code>.bgl_core/config.yml</code> + <code>storage/agent_flags.json</code> (الأخير يعلو).
    <?php if ($flagOverrides > 0): ?>
      <br>توجد <strong><?= (int)$flagOverrides ?></strong> قيمة متجاوزة من flags.
    <?php endif; ?>
  </p>
  <table style="width:100%; border-collapse: collapse; font-size:0.85rem;">
    <tr>
      <th style="text-align:right;">المفتاح</th>
      <th style="text-align:right;">القيمة</th>
      <th style="text-align:right;">المصدر</th>
    </tr>
    <?php foreach ($keyList as $key): ?>
      <?php
        $val = bgl_cfg_get($cfg, $key, '');
        if (is_bool($val)) $val = $val ? 'true' : 'false';
        if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
        $src = $sources[$key] ?? 'config';
      ?>
      <tr>
        <td><code><?= htmlspecialchars($key) ?></code></td>
        <td><?= htmlspecialchars((string)$val) ?></td>
        <td><?= $src === 'flags' ? '<span style="color:var(--accent-gold);">flags</span>' : 'config' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>
