<?php
/**
 * BGL3 Agent - Premium Operations Center
 * 
 * A high-end browser interface to monitor and interact with the BGL3 Agent.
 */

declare(strict_types=1);

$rootVendor = dirname(__DIR__) . '/vendor/autoload.php';
$localVendor = __DIR__ . '/vendor/autoload.php';
if (file_exists($localVendor)) {
    require_once $localVendor;
} elseif (file_exists($rootVendor)) {
    require_once $rootVendor;
} else {
    // Fallback: allow dashboard to keep running with minimal deps
    // (bootstrap continues; sections that need Composer may be degraded)
}

use App\Support\Database;
// use Symfony\Component\Yaml\Yaml; // Removed due to dependency issues in current environment
/**
 * Handle Blocker Resolution via POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve') {
    $id = (int)$_POST['blocker_id'];
    $dbPath = dirname(__DIR__) . '/.bgl_core/brain/knowledge.db';
    if (file_exists($dbPath)) {
        $lite = new PDO("sqlite:" . $dbPath);
        $stmt = $lite->prepare("UPDATE agent_blockers SET status = 'RESOLVED' WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: agent-dashboard.php?resolved=" . $id);
        exit;
    }
}

/**
 * Handle Rule Commitment via POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commit_rule') {
    $rule_id = $_POST['rule_id'];
    $cmd = "python .bgl_core/brain/commit_rule.py " . escapeshellarg($rule_id);
    exec($cmd, $output, $return_var);
    if ($return_var === 0) {
        header("Location: agent-dashboard.php?committed=" . $rule_id);
    } else {
        header("Location: agent-dashboard.php?error=commit_failed");
    }
    exit;
}

/**
 * Handle Permission Grant/Deny via POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'permission') {
    $perm_id = (int)$_POST['perm_id'];
    $status = $_POST['status']; // GRANTED or DENIED
    
    $agentDbPath = dirname(__DIR__) . '/.bgl_core/brain/knowledge.db';
    try {
        $lite = new PDO("sqlite:" . $agentDbPath);
        $stmt = $lite->prepare("UPDATE agent_permissions SET status = ? WHERE id = ?");
        $stmt->execute([$status, $perm_id]);
        header("Location: agent-dashboard.php?perm_updated=" . $perm_id);
    } catch (\Exception $e) {
        header("Location: agent-dashboard.php?error=db");
    }
    exit;
}

/**
 * Handle Context Digest (runtime_events -> experiences)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'digest') {
    $cmd = "python .bgl_core/brain/context_digest.py";
    exec($cmd, $output, $return_var);
    header("Location: agent-dashboard.php?" . ($return_var === 0 ? "digested=1" : "error=digest"));
    exit;
}

/**
 * Handle Master Verify trigger (fire-and-forget)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assure') {
    pclose(popen("start /B python .bgl_core/brain/master_verify.py", "r"));
    header("Location: agent-dashboard.php?assure_started=1");
    exit;
}

// Run scenarios explicitly (fire-and-forget). Falls back to master_verify with env flag.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_scenarios') {
    $runner = '.bgl_core/brain/run_scenarios.py';
    if (file_exists(__DIR__ . '/' . $runner)) {
        pclose(popen("start /B python {$runner}", "r"));
    } else {
        pclose(popen("start /B cmd /c \"set BGL_RUN_SCENARIOS=1&&python .bgl_core/brain/master_verify.py\"", "r"));
    }
    header("Location: agent-dashboard.php?scenarios_started=1");
    exit;
}

/**
 * Handle Permission Auto-Fix via POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix_permissions') {
    // 1. Fix Logs
    $logDir = __DIR__ . '/storage/logs';
    if (!is_dir($logDir)) { mkdir($logDir, 0777, true); }
    $logFile = $logDir . '/test.log';
    if (!file_exists($logFile)) { touch($logFile); }
    chmod($logFile, 0666); // Ensure writable

    // 2. Fix Config
    $configDir = __DIR__ . '/app/Config';
    if (!is_dir($configDir)) { mkdir($configDir, 0777, true); }
    $configFile = $configDir . '/agent.json';
    if (!file_exists($configFile)) { 
        file_put_contents($configFile, json_encode(["status" => "initialized"])); 
    }
    chmod($configFile, 0666); // Ensure writable

    header("Location: agent-dashboard.php?fixed_permissions=1");
    exit;
}


// Run API contract/property tests (Schemathesis/Dredd) via master_verify toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_api_contract') {
    pclose(popen("start /B cmd /c \"set BGL_RUN_SCENARIOS=0&&set BGL_RUN_API_CONTRACT=1&&python .bgl_core/brain/master_verify.py\"", "r"));
    header("Location: agent-dashboard.php?api_contract_started=1");
    exit;
}

// Restart browser: clear status file; a new launch will recreate it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restart_browser') {
    $statusPath = __DIR__ . '/.bgl_core/logs/browser_reports/browser_status.json';
    if (file_exists($statusPath)) {
        @unlink($statusPath);
    }
    header("Location: agent-dashboard.php?browser_restarted=1");
    exit;
}

/**
 * Toggle agent_mode (assisted <-> auto). If safe is ever set, next toggle goes to assisted.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_mode') {
    $cfgPath = __DIR__ . '/.bgl_core/config.yml';
    if (file_exists($cfgPath)) {
        $text = file_get_contents($cfgPath);
        // Detect current mode (agent_mode or decision.mode)
        $current = 'assisted';
        if (preg_match('/agent_mode:\s*\"?([a-zA-Z]+)\"?/i', $text, $m)) {
            $current = strtolower($m[1]);
        } elseif (preg_match('/decision:\\s*\\n\\s*mode:\\s*\"?([a-zA-Z]+)\"?/i', $text, $m)) {
            $current = strtolower($m[1]);
        }
        $next = $current === 'assisted' ? 'auto' : 'assisted';
        // Replace both occurrences
        $text = preg_replace('/agent_mode:\\s*\"?[a-zA-Z]+\"?/', 'agent_mode: "' . $next . '"', $text);
        $text = preg_replace('/(decision:\\s*\\n\\s*mode:)\\s*\"?[a-zA-Z]+\"?/', "$1 \"" . $next . '"', $text);
        file_put_contents($cfgPath, $text);
    }
    header("Location: agent-dashboard.php?mode_toggled=1");
    exit;
}

// Apply proposal in sandbox (fire-and-forget orchestrator)
// Apply proposal in sandbox (fire-and-forget orchestrator)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['apply_proposal', 'force_apply'])) {
    $pid = $_POST['proposal_id'] ?? '';
    $isForce = $_POST['action'] === 'force_apply';
    $flag = $isForce ? '--force' : '';
    
    if ($pid) {
        pclose(popen("start /B python .bgl_core/brain/apply_proposal.py --proposal {$pid} {$flag}", "r"));
        $msg = $isForce ? "proposal_forced=" . urlencode($pid) : "proposal_started=" . urlencode($pid);
        header("Location: agent-dashboard.php?" . $msg);
        exit;
    }
}

// Approve auto-generated playbook (GET for simplicity)
if (isset($_GET['action']) && $_GET['action'] === 'approve_playbook' && !empty($_GET['id'])) {
    $pid = preg_replace('/[^A-Za-z0-9_\\-]/', '', $_GET['id']);
    $cmd = "python .bgl_core/brain/approve_playbook.py " . escapeshellarg($pid);
    exec($cmd, $output, $return_var);
    header("Location: agent-dashboard.php?" . ($return_var === 0 ? "playbook_approved={$pid}" : "error=playbook"));
    exit;
}

// Reject auto-generated playbook (delete proposed file)
if (isset($_GET['action']) && $_GET['action'] === 'reject_playbook' && !empty($_GET['id'])) {
    $pid = preg_replace('/[^A-Za-z0-9_\\-]/', '', $_GET['id']);
    $file = __DIR__ . "/.bgl_core/brain/playbooks_proposed/{$pid}.md";
    if (file_exists($file)) {
        unlink($file);
    }
    header("Location: agent-dashboard.php?playbook_rejected=" . urlencode($pid));
    exit;
}

/**
 * Capture Feedback Messages
 */
$feedback = null;
if (isset($_GET['error'])) {
    $feedback = [
        'type' => 'error',
        'title' => 'فشل في العملية (Operation Failed)',
        'message' => match($_GET['error']) {
            'commit_failed' => 'لم نتمكن من كتابة القاعدة الجديدة في ملف الدستور المعماري. يرجى التأكد من صلاحيات الكتابة على مجلد .bgl_core.',
            'db' => 'خطأ في الاتصال بقاعدة بيانات المعرفة. يرجى التحقق من وجود الملف knowledge.db.',
            default => 'حدث خطأ غير متوقع أثناء معالجة الطلب.'
        }
    ];
} elseif (isset($_GET['committed'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم التطوير (Rules Evolved)',
        'message' => 'تم بنجاح دمج القاعدة الجديدة في دستور المشروع. سيلتزم الوكيل بها اعتباراً من الآن.'
    ];
} elseif (isset($_GET['perm_updated'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تحديث الصلاحيات (Permission Synced)',
        'message' => 'تم تحديث قرارك الأمني بنجاح في سجلات الوكيل.'
    ];
} elseif (isset($_GET['resolved'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم حل المشكلة (Task Unblocked)',
        'message' => 'تم استلام تأكيدك للحل اليدوي. شكراً لمساعدتك الوكيل على التقدم.'
    ];
} elseif (isset($_GET['digested'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم تحديث الخبرة',
        'message' => 'تم تلخيص الأحداث الحديثة إلى ذاكرة الخبرات.'
    ];
} elseif (isset($_GET['assure_started'])) {
    $feedback = [
        'type' => 'info',
        'title' => 'تم إطلاق الفحص الشامل',
        'message' => 'Master Verify يعمل الآن في الخلفية.'
    ];
} elseif (isset($_GET['proposal_started'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم بدء التطبيق (Sandbox)',
        'message' => 'جاري تجربة الاقتراح في بيئة معزولة...'
    ];
} elseif (isset($_GET['proposal_forced'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم التطبيق المباشر (Production)',
        'message' => '⚠️ تم تنفيذ التعديلات على النظام الحي (Forced Apply).'
    ];
}

/**
 * A lightweight, dependency-free YAML minimal parser for domain_rules.yml
 */
class SimpleYamlParser {
    public static function parseFile(string $filePath): array {
        if (!file_exists($filePath)) return [];
        $lines = file($filePath);
        $result = ['rules' => []];
        $currentRule = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            // Detecting rule start
            if (preg_match('/^-\s*id:\s*"(.*)"/', $line, $matches)) {
                if ($currentRule) $result['rules'][] = $currentRule;
                $currentRule = ['id' => $matches[1]];
                continue;
            }
            
            if ($currentRule !== null) {
                if (preg_match('/^name:\s*"(.*)"/', $line, $matches)) $currentRule['name'] = $matches[1];
                if (preg_match('/^description:\s*"(.*)"/', $line, $matches)) $currentRule['description'] = $matches[1];
                if (preg_match('/^action:\s*"(.*)"/', $line, $matches)) $currentRule['action'] = $matches[1];
            }
        }
        if ($currentRule) $result['rules'][] = $currentRule;
        return $result;
    }
}

/**
 * Robust YAML light parser for simple key-value structures.
 */
function bgl_yaml_parse(string $path): array|null {
    if (!file_exists($path)) return null;
    if (function_exists('yaml_parse_file')) {
        $fn = 'yaml_parse_file';
        return @$fn($path);
    }
    
    // Custom parser for nested structures (Lite)
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $data = [];
    $currentKey = null;
    $currentList = null;
    $currentItem = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed) || str_starts_with($trimmed, '#')) continue;

        // 1. Top level key (e.g., "operational_kpis:")
        if (preg_match('/^([a-zA-Z0-9_]+):$/', $trimmed, $m)) {
            if ($currentKey && $currentList !== null) {
                $data[$currentKey] = $currentList;
            }
            $currentKey = $m[1];
            $currentList = [];
            $currentItem = null;
            continue;
        }

        // 2. List item start (e.g., "  - name: ...")
        if (str_contains($line, '- ')) {
            if ($currentItem !== null) {
                $currentList[] = $currentItem;
            }
            $currentItem = [];
            // Parse inline key-val if exists on the bullet line
            $content = trim(substr($trimmed, 1)); // remove '-'
            if (preg_match('/^([a-zA-Z0-9_]+):\s*(.*)$/', $content, $m)) {
                $val = trim($m[2], ' "');
                if (str_starts_with($val, '[')) {
                     // simplistic array parse [a, b]
                     $val = explode(',', trim($val, '[]'));
                     $val = array_map('trim', $val);
                }
                $currentItem[$m[1]] = $val;
            }
            continue;
        }

        // 3. Sub-property (e.g., "    target: ...")
        if ($currentKey && $currentItem !== null && preg_match('/^([a-zA-Z0-9_]+):\s*(.*)$/', $trimmed, $m)) {
            $val = trim($m[2], ' "');
            if (str_starts_with($val, '[')) {
                 $val = explode(',', trim($val, '[]'));
                 $val = array_map('trim', $val);
            }
            $currentItem[$m[1]] = $val;
        }
        
        // 4. Simple top-level key-val (e.g., "version: 1.0")
        if (!$currentKey && preg_match('/^([a-zA-Z0-9_]+):\s*(.*)$/', $trimmed, $m)) {
             $data[$m[1]] = trim($m[2], ' "');
        }
    }
    
    // Flush last items
    if ($currentItem !== null) $currentList[] = $currentItem;
    if ($currentKey && $currentList !== null) $data[$currentKey] = $currentList;

    return $data;
}

class PremiumDashboard
{
    private ?PDO $db;
    public string $projectPath;
    private string $agentDbPath;
    private string $decisionDbPath;

    public function __construct()
    {
        $this->projectPath = dirname(__DIR__);
        $this->agentDbPath = $this->projectPath . '/.bgl_core/brain/knowledge.db';
        $this->decisionDbPath = $this->projectPath . '/.bgl_core/brain/decision.db';
        try {
            $this->db = Database::connect();
        } catch (\Exception $e) {
            $this->db = null;
        }
    }

    public function getSystemVitals(): array
    {
        $vitals = [];
        // Brain
        $rulesFile = $this->projectPath . '/.bgl_core/brain/domain_rules.yml';
        $vitals['brain'] = [
            'name' => 'Neural Network (Rules)',
            'status' => file_exists($rulesFile) ? 'ACTIVE' : 'OFFLINE',
            'ok' => file_exists($rulesFile)
        ];

        // Memory
        $vitals['memory'] = [
            'name' => 'Agentic Memory (SQLite)',
            'status' => file_exists($this->agentDbPath) ? 'SYNCED' : 'INITIALIZING',
            'ok' => file_exists($this->agentDbPath)
        ];

        // DB
        $vitals['database'] = [
            'name' => 'Core Database (MySQL)',
            'status' => $this->db ? 'CONNECTED' : 'DISCONNECTED',
            'ok' => $this->db !== null
        ];

        // Hardware Vitals (Real-Time)
        $hwFile = $this->projectPath . '/.bgl_core/logs/hardware_vitals.json';
        if (file_exists($hwFile)) {
            $hw = json_decode(file_get_contents($hwFile), true);
            if ($hw) {
                $vitals['cpu'] = [
                    'name' => 'CPU Usage',
                    'status' => $hw['cpu']['usage_percent'] . '%',
                    'ok' => $hw['cpu']['usage_percent'] < 85
                ];
                $vitals['ram'] = [
                    'name' => 'RAM Usage',
                    'status' => $hw['memory']['used_gb'] . ' / ' . $hw['memory']['total_gb'] . ' GB',
                    'ok' => $hw['memory']['percent'] < 90
                ];
                if (isset($hw['gpu']) && $hw['gpu']) {
                    $vitals['gpu'] = [
                        'name' => 'GPU Load',
                        'status' => $hw['gpu']['load'] . '%',
                        'ok' => $hw['gpu']['load'] < 90
                    ];
                }
            }
        }

        return $vitals;
    }

    public function getAgentStats(): array
    {
        $stats = ['decisions' => 0, 'corrections' => 0, 'health_score' => 95];
        if (!$this->db) return $stats;

        try {
            $stats['decisions'] = (int)$this->db->query("SELECT COUNT(*) FROM guarantee_decisions")->fetchColumn();
            $stats['corrections'] = (int)$this->db->query("SELECT COUNT(*) FROM guarantee_decisions WHERE override_reason IS NOT NULL")->fetchColumn();
            
            // Artificial "Health Score" based on recent errors vs successes
            $total = max(1, $stats['decisions']);
            $stats['health_score'] = round(100 - (($stats['corrections'] / $total) * 100));
        } catch (\Exception $e) {}

        return $stats;
    }

    public function getBlockers(): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $stmt = $lite->query("SELECT * FROM agent_blockers WHERE status = 'PENDING' ORDER BY timestamp DESC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getProposals(): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        $proposals = [];
        try {
            // 1. Fetch Proposals
            $kb = new PDO("sqlite:" . $this->agentDbPath);
            $stmt = $kb->query("SELECT * FROM agent_proposals");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fetch Statuses from Decision DB
            $statuses = [];
            if (file_exists($this->decisionDbPath)) {
                try {
                    $dec = new PDO("sqlite:" . $this->decisionDbPath);
                    $sql = "SELECT i.intent, o.result, o.notes, o.timestamp 
                            FROM outcomes o 
                            JOIN decisions d ON o.decision_id = d.id 
                            JOIN intents i ON d.intent_id = i.id 
                            WHERE i.intent LIKE 'apply_%'
                            ORDER BY o.id DESC"; // Latest first
                    $history = $dec->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($history as $h) {
                        $pid = str_replace('apply_', '', $h['intent']);
                        if (!isset($statuses[$pid])) {
                            $statuses[$pid] = [
                                'result' => $h['result'],
                                'notes'  => $h['notes']
                            ];
                        }
                    }
                } catch (\Exception $e) {}
            }

            // 3. Merge
            foreach ($rows as $r) {
                $stat = $statuses[$r['id']] ?? [];
                $r['status'] = $stat['result'] ?? null;
                $r['status_note'] = $stat['notes'] ?? null;
                $proposals[] = $r;
            }
        } catch (\Exception $e) {
            return [];
        }
        return $proposals;
    }


    public function getPermissions(): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $stmt = $lite->query("SELECT * FROM agent_permissions WHERE status = 'PENDING' ORDER BY timestamp DESC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getRecentActivity(): array
    {
        $activities = [];

        // Humanization Dictionary
        $trans = [
            'reindex_full' => 'تحديث فهرس الذاكرة (Re-indexing)',
            'scenario_batch' => 'تحليل سيناريوهات البيانات (Data Analysis)',
            'investigate' => 'فحص عميق للنظام (Investigation)',
            'master_verify' => 'فحص شامل للصحة (Master Verify)',
            'fix_permissions' => 'إصلاح تصاريح الملفات',
            'apply_proposal' => 'تطبيق اقتراح تحسين',
            'commit_proposal' => 'اعتماد قاعدة جديدة',
        ];
        
        // 1. Agent Activity (Inference/Scans) from Knowledge DB
        if (file_exists($this->agentDbPath)) {
            try {
                $lite = new PDO("sqlite:" . $this->agentDbPath);
                $stmt = $lite->query("SELECT * FROM agent_activity ORDER BY timestamp DESC LIMIT 8");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $raw = $r['activity'] ?? 'Unknown Activity';
                    $readable = $trans[$raw] ?? $raw; // Translate or keep raw
                    
                    // Specific mapping for inference
                    if (str_contains($raw, 'Inference Detected')) {
                        $readable = 'اكتشاف أنماط جديدة (Inference)';
                    }

                    $activities[] = [
                        'type'    => 'AGENT',
                        'message' => $readable,
                        'timestamp' => $r['timestamp'],
                        'status'  => 'info'
                    ];
                }
            } catch (\Exception $e) {}
        }

        // 2. User/Decision Outcomes (Apply Proposal) from Decision DB
        if (file_exists($this->decisionDbPath)) {
            try {
                $dec = new PDO("sqlite:" . $this->decisionDbPath);
                // Get outcomes linked to intents
                $sql = "SELECT i.intent, o.result, o.timestamp 
                        FROM outcomes o 
                        JOIN decisions d ON o.decision_id = d.id 
                        JOIN intents i ON d.intent_id = i.id 
                        ORDER BY o.id DESC LIMIT 8";
                $rows = $dec->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($rows as $r) {
                    $msg = $r['intent'];
                    $status = 'success';
                    
                    if (str_starts_with($r['intent'], 'apply_')) {
                        $pid = substr($r['intent'], 6);
                        if ($r['result'] === 'success_direct') {
                            $msg = "تم التطبيق المباشر للاقتراح #$pid";
                            $status = 'warning';
                        } else {
                            $msg = "تمت تجربة الاقتراح #$pid (ساندبوكس)";
                            $status = 'success';
                        }
                    } else {
                         // Translate generic intents if in map
                         $msg = $trans[$msg] ?? $msg;
                    }

                    $activities[] = [
                        'type'    => 'USER',
                        'message' => $msg,
                        'timestamp' => $r['timestamp'],
                        'status'  => $status
                    ];
                }
            } catch (\Exception $e) {}
        }

        // Sort by time DESC (handle floats/strings)
        usort($activities, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        return array_slice($activities, 0, 8);
    }

    public function getExperiences(int $limit = 8): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $stmt = $lite->prepare("SELECT scenario, summary, confidence, evidence_count, created_at FROM experiences ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getRuntimeEvents(int $limit = 10): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $stmt = $lite->prepare("SELECT event_type, route, method, status, latency_ms, error, timestamp FROM runtime_events ORDER BY timestamp DESC LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getActiveLaws(): array
    {
        $rulesPath = $this->projectPath . '/.bgl_core/brain/domain_rules.yml';
        if (!file_exists($rulesPath)) return [];
        $yaml = SimpleYamlParser::parseFile($rulesPath);
        return $yaml['rules'] ?? [];
    }

    public function getBrowserStatus(): array
    {
        $statusPath = $this->projectPath . '/.bgl_core/logs/browser_reports/browser_status.json';
        if (!file_exists($statusPath)) {
            return ["busy" => false, "message" => "المتصفح غير نشط"];
        }
        $json = json_decode(file_get_contents($statusPath), true);
        if (!is_array($json)) {
            return ["busy" => false, "message" => "لا تتوفر بيانات المتصفح"];
        }
        return $json;
    }

    public function getPermissionIssues(): array
    {
        $issues = [];
        $checks = [
            'storage/logs/test.log' => 'write',
            'app/Config/agent.json' => 'write',
        ];
        foreach ($checks as $rel => $kind) {
            $path = $this->projectPath . '/' . $rel;
            if (!file_exists($path)) {
                $issues[] = "$rel مفقود (متوقع $kind)";
                continue;
            }
            if (!is_writable($path)) {
                $issues[] = "$rel غير قابل للكتابة";
            }
        }
        return $issues;
    }

    public function getWorstRoutes(int $limit = 5): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $sql = "
                WITH agg AS (
                    SELECT route,
                           SUM(CASE WHEN event_type IN ('http_error','route') AND status >=400 THEN 1 ELSE 0 END) as http_fail,
                           SUM(CASE WHEN event_type='network_fail' THEN 1 ELSE 0 END) as net_fail,
                           COUNT(*) as cnt
                    FROM runtime_events
                    WHERE route IS NOT NULL
                    GROUP BY route
                )
                SELECT route, (http_fail*5 + net_fail*3 + cnt*0.1) AS score, http_fail, net_fail, cnt
                FROM agg
                ORDER BY score DESC
                LIMIT :lim
            ";
            $stmt = $lite->prepare($sql);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getRecentIntents(int $limit = 5): array
    {
        if (!file_exists($this->decisionDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->decisionDbPath);
            $stmt = $lite->prepare("SELECT intent, confidence, reason, scope, context_snapshot, timestamp FROM intents ORDER BY id DESC LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getRecentDecisions(int $limit = 5): array
    {
        if (!file_exists($this->decisionDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->decisionDbPath);
            $sql = "SELECT d.decision, d.risk_level, d.requires_human, d.justification, d.created_at,
                           i.intent, i.confidence, i.reason, i.scope
                    FROM decisions d
                    JOIN intents i ON i.id = d.intent_id
                    ORDER BY d.id DESC
                    LIMIT ?";
            $stmt = $lite->prepare($sql);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function checkRateLimitGuard(): array
    {
        $targets = [
            $this->projectPath . '/app/Support/autoload.php',
            $this->projectPath . '/routes/api.php',
            $this->projectPath . '/routes/web.php',
        ];
        $found = false;
        $evidence = [];
        foreach ($targets as $t) {
            if (file_exists($t)) {
                $content = @file_get_contents($t);
                if ($content && (stripos($content, 'ratelimit') !== false || stripos($content, 'rate limit') !== false)) {
                    $found = true;
                    $evidence[] = $t;
                }
            }
        }
        return [
            'id' => 'GAP_RATE_LIMIT',
            'passed' => $found,
            'evidence' => $evidence,
            'scope' => ['api'],
        ];
    }
}

$dash = new PremiumDashboard();
$vitals = $dash->getSystemVitals();
$stats = $dash->getAgentStats();
$laws = $dash->getActiveLaws();
$blockers = $dash->getBlockers();
$proposals = $dash->getProposals();
$permissions = $dash->getPermissions();
$activities = $dash->getRecentActivity();
$experiences = $dash->getExperiences();
$events = $dash->getRuntimeEvents();
$browserStatus = $dash->getBrowserStatus();
$permissionIssues = $dash->getPermissionIssues();
$worstRoutes = $dash->getWorstRoutes();
$recentIntents = $dash->getRecentIntents();
$recentDecisions = $dash->getRecentDecisions();
$pendingPlaybooks = glob(__DIR__ . '/.bgl_core/brain/playbooks_proposed/*.md');
$pendingPlaybooks = $pendingPlaybooks ? count($pendingPlaybooks) : 0;
$externalChecks = [];
$perfMetrics = [];
$callgraphMeta = [];
$configPath = __DIR__ . '/.bgl_core/config.yml';
$executionMode = 'sandbox';
$agentMode = 'assisted';
$domainMap = null;
$domainMapRaw = null;
$flows = [];
$kpiCurrent = [];
$directAttempts = 0;
$successRate = null;
$proposedPatterns = [];
if (file_exists($configPath)) {
    $cfg = bgl_yaml_parse($configPath);
    if (is_array($cfg) && isset($cfg['execution_mode'])) {
        $executionMode = strtolower((string)$cfg['execution_mode']);
    }
    if (is_array($cfg) && isset($cfg['agent_mode'])) {
        $agentMode = strtolower((string)$cfg['agent_mode']);
    } elseif (is_array($cfg) && isset($cfg['decision']['mode'])) {
        $agentMode = strtolower((string)$cfg['decision']['mode']);
    }
}
$reportJson = __DIR__ . '/.bgl_core/logs/latest_report.json';
if (file_exists($reportJson)) {
    $jr = json_decode(file_get_contents($reportJson), true);
    if (is_array($jr)) {
        if (isset($jr['external_checks']) && is_array($jr['external_checks'])) {
            $externalChecks = $jr['external_checks'];
        } elseif (isset($jr['findings']['external_checks']) && is_array($jr['findings']['external_checks'])) {
            $externalChecks = $jr['findings']['external_checks'];
        }
        if (isset($jr['performance']) && is_array($jr['performance'])) {
            $perfMetrics = $jr['performance'];
        }
        if (isset($jr['callgraph_meta']) && is_array($jr['callgraph_meta'])) {
            $callgraphMeta = $jr['callgraph_meta'];
        }
    }
}
// count direct attempts from decision outcomes if available
$decisionDbPath = __DIR__ . '/.bgl_core/brain/decision.db';
if (file_exists($decisionDbPath)) {
    try {
        $lite = new PDO("sqlite:" . $decisionDbPath);
        $directAttempts = (int)$lite->query("SELECT COUNT(*) FROM outcomes WHERE result='mode_direct'")->fetchColumn();
        $rows = $lite->query("SELECT result, COUNT(*) c FROM outcomes GROUP BY result")->fetchAll(PDO::FETCH_ASSOC);
        $total = array_sum(array_column($rows, 'c'));
        if ($total > 0) {
            $success = 0;
            foreach ($rows as $r) {
                if (in_array($r['result'], ['success','success_with_override'], true)) {
                    $success += (int)$r['c'];
                }
            }
            $successRate = round(($success / $total) * 100, 1);
        }
    } catch (\Exception $e) {}
}
$gapRateLimit = $dash->checkRateLimitGuard();

// Proposed patterns (auto-generated discovery)
$proposedPath = __DIR__ . '/.bgl_core/brain/proposed_patterns.json';
if (file_exists($proposedPath)) {
    $json = json_decode(file_get_contents($proposedPath), true);
    if (is_array($json)) {
        $proposedPatterns = $json;
    }
}

// KPIs الحالية مستخلصة من runtime_events
try {
    if (file_exists($dash->projectPath . '/.bgl_core/brain/knowledge.db')) {
        $lite = new PDO("sqlite:" . $dash->projectPath . '/.bgl_core/brain/knowledge.db');
        // helper to run scalar
        $scalar = function(string $sql, array $params = []) use ($lite) {
            $stmt = $lite->prepare($sql);
            $stmt->execute($params);
            return (float)$stmt->fetchColumn();
        };

        // نطاقات
        $writeRoutes = [
            '/api/create-guarantee.php','/api/update_bank.php','/api/update_supplier.php',
            '/api/import_suppliers.php','/api/import_banks.php','/api/create-bank.php','/api/create-supplier.php'
        ];
        $writePlaceholders = implode(',', array_fill(0, count($writeRoutes), '?'));

        // إجمالي وأخطاء
        $totalWrites = $scalar("SELECT COUNT(*) FROM runtime_events WHERE route IN ($writePlaceholders)", $writeRoutes) ?: 0;
        $errorWrites = $scalar("SELECT COUNT(*) FROM runtime_events WHERE route IN ($writePlaceholders) AND status >= 400", $writeRoutes) ?: 0;
        $valFails = $scalar("SELECT COUNT(*) FROM runtime_events WHERE route IN ($writePlaceholders) AND status = 422", $writeRoutes) ?: 0;

        // معدل الأخطاء
        if ($totalWrites > 0) {
            $kpiCurrent['api_error_rate'] = round(($errorWrites / $totalWrites) * 100, 2);
            $kpiCurrent['validation_failure_rate'] = round(($valFails / $totalWrites) * 100, 2);
        }

        // زمن العقود (متوسط بسيط)
        $lat = $scalar("SELECT AVG(latency_ms) FROM runtime_events WHERE route IN ($writePlaceholders) AND latency_ms IS NOT NULL", $writeRoutes);
        if ($lat > 0) $kpiCurrent['contract_latency_ms'] = round($lat, 1);

        // استيراد: معدل النجاح
        $impTotal = $scalar("SELECT COUNT(*) FROM runtime_events WHERE event_type IN ('import_suppliers','import_banks')", []);
        $impFail  = $scalar("SELECT COUNT(*) FROM runtime_events WHERE event_type IN ('import_suppliers','import_banks') AND (status >= 400 OR error IS NOT NULL)", []);
        if ($impTotal > 0) {
            $kpiCurrent['import_success_rate'] = round((1 - ($impFail / $impTotal)) * 100, 2);
        }

        // Data Quality Score (heuristic: 100 - val_fail_rate on writes)
        if (isset($kpiCurrent['validation_failure_rate'])) {
            $kpiCurrent['data_quality_score'] = 100 - $kpiCurrent['validation_failure_rate'];
        } else {
             $kpiCurrent['data_quality_score'] = 100; // Default optimism
        }
    }
} catch (\Exception $e) {
    // صمت: عرض n/a عند الفشل
}

// Domain map & flows (for context display)
$domainMapPath = dirname(__DIR__) . '/docs/domain_map.yml';
if (file_exists($domainMapPath)) {
    $domainMapRaw = file_get_contents($domainMapPath);
    $parsed = bgl_yaml_parse($domainMapPath);
    if (is_array($parsed)) {
        $domainMap = $parsed;
    }
}
$flowsDir = dirname(__DIR__) . '/docs/flows';
if (is_dir($flowsDir)) {
    foreach (glob($flowsDir . '/*.md') as $flowFile) {
        $title = basename($flowFile);
        $fh = fopen($flowFile, 'r');
        if ($fh) {
            $first = trim(fgets($fh));
            fclose($fh);
            if (str_starts_with($first, '#')) {
                $title = ltrim($first, "# \t");
            }
        }
        $flows[] = ['title' => $title, 'file' => basename($flowFile)];
    }
}
?>
