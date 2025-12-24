<?php
/**
 * V3 API - Get Timeline (Server-Driven Partial HTML)
 * Returns the HTML for the timeline sidebar
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

header('Content-Type: text/html; charset=utf-8');

try {
    $index = $_GET['index'] ?? 1;
    $db = Database::connect();
    
    // Get guarantee ID for this index
    // Note: We use the same ordering as get-record.php to match the record
    $stmtIds = $db->query('SELECT id FROM guarantees ORDER BY imported_at DESC LIMIT 100');
    $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
    
    $guaranteeId = $ids[$index - 1] ?? null;
    $timeline = [];

    if ($guaranteeId) {
        $historyStmt = $db->prepare('
            SELECT * FROM guarantee_history 
            WHERE guarantee_id = ? 
            ORDER BY created_at DESC, id DESC
        ');
        $historyStmt->execute([$guaranteeId]);
        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Map icons
        foreach ($history as $h) {
            $icon = '📋';
            switch ($h['action']) {
                case 'update': $icon = '📝'; break;
                case 'extend': $icon = '🔄'; break;
                case 'reduce': $icon = '📉'; break;
                case 'release': $icon = '📤'; break;
                case 'approved': $icon = '✅'; break;
                case 'auto_matched': $icon = '🤖'; break;
                case 'manual_match': $icon = '🔗'; break;
            }
            // Auto match user
            if ($h['action'] === 'auto_matched' && empty($h['created_by'])) {
                $h['created_by'] = 'System';
            }
            
            $timeline[] = [
                'id' => $h['id'],
                'icon' => $icon,
                'action' => ucfirst($h['action']),
                'created_at' => $h['created_at'],
                'user' => $h['created_by'],
                'change_reason' => $h['change_reason']
            ];
        }
    }
    
    // Render the Sidebar HTML
    // We render the wrapper <aside id="timeline-section"> to replace the existing one
    ?>
    <aside class="sidebar" id="timeline-section">
        <header class="timeline-header">
            <div class="timeline-title">
                <span>⏲️</span>
                <span>Timeline</span>
            </div>
            <span class="timeline-count cursor-help" title="<?= count($timeline) ?> أحداث">
                <span><?= count($timeline) ?></span> حدث
            </span>
        </header>
        <div class="timeline-body h-full overflow-y-auto">
            <div class="timeline-list">
                <div class="timeline-line"></div>
                
                <?php if (empty($timeline)): ?>
                    <div class="text-center text-gray-400 text-sm py-8">
                        لا توجد أحداث في التاريخ
                    </div>
                <?php else: ?>
                    <?php foreach ($timeline as $idx => $event): ?>
                    <?php $isFirst = ($idx === 0); ?>
                        <div class="timeline-item" 
                             style="<?= $isFirst ? 'cursor: default; opacity: 1;' : 'cursor: pointer;' ?>" 
                             <?= $isFirst ? '' : 'onclick="UnifiedController.loadHistory(\'' . $event['id'] . '\')"' ?>>
                            <div class="timeline-dot <?= $idx === 0 ? 'active' : '' ?>"></div>
                            <div class="event-card <?= $idx === 0 ? 'current' : '' ?>">
                                <div class="event-header">
                                    <span class="event-icon"><?= htmlspecialchars($event['icon'] ?? '📋') ?></span>
                                    <span class="event-type"><?= htmlspecialchars($event['action'] ?? 'حدث') ?></span>
                                </div>
                                <div class="event-date"><?= htmlspecialchars($event['created_at'] ?? '') ?></div>
                                <?php if (!empty($event['change_reason'])): ?>
                                    <div class="event-note"><?= htmlspecialchars($event['change_reason']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($event['user'])): ?>
                                    <div class="event-user">👤 <?= htmlspecialchars($event['user']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
    </aside>
<?php
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div style="color:red">Error loading timeline: ' . $e->getMessage() . '</div>';
}
