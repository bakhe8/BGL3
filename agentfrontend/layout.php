<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BGL3 | Agent Command Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;700&family=Noto+Kufi+Arabic:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="agentfrontend/theme_light.css">
</head>
<body>

    <header>
        <div class="brand">
            <h1>BGL3 Agent</h1>
            <p>لوحة التحكم المركزية</p>
        </div>
        <div class="badge-live">
            <div class="pulse"></div>
            حالة النظام: نشط
        </div>
    </header>

    <main class="container">
        
        <!-- Flash Messages -->
        <?php if ($feedback): ?>
            <div class="flash-alert flash-<?= $feedback['type'] ?>">
                <div style="font-size: 1.5rem;">
                    <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?>
                </div>
                <div>
                    <div style="font-weight:700"><?= htmlspecialchars($feedback['title']) ?></div>
                    <div><?= htmlspecialchars($feedback['message']) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            
            <!-- SECTION 1: HIGH LEVEL SUMMARY -->
            <div class="section-full">
                <?php include __DIR__ . '/partials/summary.php'; ?>
            </div>

            <!-- SECTION 2: ATTENTION REQUIRED -->
            <!-- Blockers are critical, show full width if they exist -->
            <div class="section-full">
                <?php include __DIR__ . '/partials/blockers.php'; ?>
            </div>

            <div class="section-half">
                <?php include __DIR__ . '/partials/proposed_playbooks_simple.php'; ?>
            </div>
            
            <div class="section-half">
                <?php include __DIR__ . '/partials/proposals_simple.php'; ?>
            </div>

            <!-- SECTION 3: SYSTEM HEALTH (Simplified) -->
            <div class="section-full" style="margin-top: 2rem;">
                <h3 style="color: var(--text-secondary); font-size: 0.9rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem;">
                    مؤشرات الأداء والحالة التقنية
                </h3>
            </div>

            <div class="section-third">
                <?php include __DIR__ . '/partials/health.php'; ?>
            </div>
            <div class="section-third">
                <?php include __DIR__ . '/partials/permissions.php'; ?>
            </div>
            <div class="section-third">
                <?php include __DIR__ . '/partials/kpis.php'; ?>
            </div>
            
            <!-- SECTION 4: ACTIVITY LOG -->
            <div class="section-full">
                <?php include __DIR__ . '/partials/activity.php'; ?>
            </div>
            
            <!-- HIDDEN/SECONDARY SECTIONS (Not for non-specialists) -->
            <!-- 
                partials: domain_map, flows, js_inventory, gap_tests, external_checks, worst_routes, rules, experiences, intents, decisions
                These are hidden to keep the UI minimal.
            -->

        </div>
    </main>

    <footer style="margin-top: 50px; text-align: center; color: var(--text-secondary); font-size: 0.8rem;">
        BGL3 Agent Operations Dashboard &bull; Phase 6 Unified Core &bull; 2026
    </footer>

</body>
</html>
