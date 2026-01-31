<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BGL3 | Agent Command Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&family=Noto+Kufi+Arabic:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="agentfrontend/theme.css">
</head>
<body>
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <?php include __DIR__ . '/partials/summary.php'; ?>
    <?php include __DIR__ . '/partials/quick_actions.php'; ?>
    <?php include __DIR__ . '/partials/domain_map.php'; ?>
    <?php include __DIR__ . '/partials/flows.php'; ?>
    <?php include __DIR__ . '/partials/kpis.php'; ?>
    <?php include __DIR__ . '/partials/js_inventory.php'; ?>

    <?php if ($feedback): ?>
        <div class="flash-alert flash-<?= $feedback['type'] ?>">
            <div style="font-size: 1.5rem;">
                <?= $feedback['type'] === 'success' ? '✅' : '❌' ?>
            </div>
            <div>
                <div class="flash-title"><?= htmlspecialchars($feedback['title']) ?></div>
                <div class="flash-msg"><?= htmlspecialchars($feedback['message']) ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/partials/proposed_playbooks_simple.php'; ?>
    <?php include __DIR__ . '/partials/proposals_simple.php'; ?>
    <?php include __DIR__ . '/partials/external_checks.php'; ?>
    <?php include __DIR__ . '/partials/health.php'; ?>
    <?php include __DIR__ . '/partials/permissions.php'; ?>
    <?php include __DIR__ . '/partials/worst_routes.php'; ?>
    <?php include __DIR__ . '/partials/rules.php'; ?>
    <?php include __DIR__ . '/partials/experiences.php'; ?>
    <?php include __DIR__ . '/partials/blockers.php'; ?>
    <?php include __DIR__ . '/partials/intents.php'; ?>
    <?php include __DIR__ . '/partials/decisions.php'; ?>
    <?php include __DIR__ . '/partials/gap_tests.php'; ?>
    <?php include __DIR__ . '/partials/activity.php'; ?>
    <?php include __DIR__ . '/partials/events.php'; ?>

    <footer style="margin-top: 50px; text-align: center; color: var(--text-secondary); font-size: 0.8rem; font-weight: 300;">
        BGL3 Agent Operations Dashboard &bull; Phase 6 Unified Core &bull; 2026
    </footer>

</body>
</html>
