<?php
/**
 * Unified Header Component
 * Used across all BGL3 pages for consistent navigation
 */

// Detect current page for active state
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Helper function to check if link is active
function isActive($page, $currentPage, $currentDir)
{
    if ($currentDir === 'views' && $page === $currentPage) {
        return true;
    }
    if ($currentDir !== 'views' && $page === 'index' && $currentPage === 'index') {
        return true;
    }
    return false;
}

// Determine base path (root or views/)
$basePath = ($currentDir === 'views') ? '../' : './';
?>

<header class="top-bar">
    <div class="brand">
        <div class="brand-icon">&#x1F4CB;</div>
        <span>نظام إدارة الضمانات</span>
    </div>

    <nav class="global-actions">
        <a href="<?= $basePath ?>index.php"
            class="btn-global <?= isActive('index', $currentPage, $currentDir) ? 'active' : '' ?>">
            🏠 الرئيسية
        </a>
        <a href="<?= $basePath ?>views/batches.php"
            class="btn-global <?= isActive('batches', $currentPage, $currentDir) ? 'active' : '' ?>">
            📦 الدفعات
        </a>
        <a href="<?= $basePath ?>views/statistics.php"
            class="btn-global <?= isActive('statistics', $currentPage, $currentDir) ? 'active' : '' ?>">
            📊 إحصائيات
        </a>
        <a href="<?= $basePath ?>views/settings.php"
            class="btn-global <?= isActive('settings', $currentPage, $currentDir) ? 'active' : '' ?>">
            ⚙ إعدادات
        </a>
    </nav>
</header>