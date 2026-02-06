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

$projectRoot = dirname(__DIR__);
$agentDbPath = $projectRoot . '/.bgl_core/brain/knowledge.db';
$pythonBin = $projectRoot . '/.bgl_core/.venv312/Scripts/python.exe';
if (!file_exists($pythonBin)) {
    $pythonBin = 'python';
}
$pythonEsc = escapeshellarg($pythonBin);

use App\Support\Database;
// use Symfony\Component\Yaml\Yaml; // Removed due to dependency issues in current environment

// CLI runs may not populate REQUEST_METHOD; default to GET to avoid warnings.
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

function bgl_is_ajax(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false) return true;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return true;
    if (!empty($_POST['ajax']) && $_POST['ajax'] === '1') return true;
    return false;
}

function bgl_respond(array $payload, ?string $redirect = null): void {
    if (bgl_is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($redirect) {
        header("Location: {$redirect}");
        exit;
    }
    exit;
}

function bgl_flatten(array $data, string $prefix = ''): array {
    $out = [];
    foreach ($data as $k => $v) {
        $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out = array_merge($out, bgl_flatten($v, $key));
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
}

function bgl_experience_hash(string $scenario, string $summary): string {
    return sha1(trim($scenario) . '|' . trim($summary));
}

function bgl_start_bg(string $cmd): void {
    $cmd = trim($cmd);
    if ($cmd === '') return;
    // Windows: when using "start", the first quoted string is treated as the window title.
    // Use an empty title "" to avoid swallowing the executable path.
    // Escape embedded quotes for cmd.exe
    $safeCmd = str_replace('"', '^"', $cmd);
    $full = 'cmd /c "start \"\" /B ' . $safeCmd . '"';
    pclose(popen($full, "r"));
}

function bgl_start_tool_server_bg(string $pythonBin, string $scriptPath, int $port): void {
    $pythonBin = trim($pythonBin);
    $scriptPath = trim($scriptPath);
    if ($pythonBin === '' || $scriptPath === '' || $port <= 0) return;
    $args = $scriptPath . ' --port ' . $port;
    $ps = 'Start-Process -WindowStyle Hidden -FilePath ' . escapeshellarg($pythonBin) .
        ' -ArgumentList ' . escapeshellarg($args);
    $full = 'powershell -NoProfile -Command ' . escapeshellarg($ps);
    pclose(popen($full, "r"));
}

function bgl_start_tool_watchdog_bg(string $pythonBin, string $watchdogPath, int $port): void {
    $pythonBin = trim($pythonBin);
    $watchdogPath = trim($watchdogPath);
    if ($pythonBin === '' || $watchdogPath === '' || $port <= 0) return;
    $args = $watchdogPath . ' --port ' . $port;
    $ps = 'Start-Process -WindowStyle Hidden -FilePath ' . escapeshellarg($pythonBin) .
        ' -ArgumentList ' . escapeshellarg($args);
    $full = 'powershell -NoProfile -Command ' . escapeshellarg($ps);
    pclose(popen($full, "r"));
}

function bgl_route_health_from_db(string $dbPath, int $days = 7, int $limit = 12): array {
    $cutoff = time() - ($days * 86400);
    $out = [
        'routes_count' => 0,
        'failing_routes' => [],
        'worst_routes' => [],
        'health_score' => null,
    ];
    if (!file_exists($dbPath)) return $out;
    try {
        $lite = new PDO("sqlite:" . $dbPath);
        $out['routes_count'] = (int)$lite->query("SELECT COUNT(*) FROM routes")->fetchColumn();
        $stmt = $lite->prepare("SELECT uri, http_method, file_path, status_score, last_validated FROM routes WHERE last_validated >= ? ORDER BY last_validated DESC LIMIT ?");
        $stmt->execute([$cutoff, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $failing = [];
        $scores = [];
        foreach ($rows as $r) {
            $score = (int)($r['status_score'] ?? 0);
            $scores[] = $score;
            if ($score <= 0) {
                $failing[] = [
                    'uri' => $r['uri'] ?? '',
                    'method' => $r['http_method'] ?? '',
                    'file_path' => $r['file_path'] ?? '',
                    'score' => $score,
                ];
            }
        }
        $out['failing_routes'] = $failing;
        $out['worst_routes'] = $failing;
        if (!empty($scores)) {
            $avg = array_sum($scores) / max(1, count($scores));
            $out['health_score'] = round($avg, 1);
        }
    } catch (\Exception $e) {}
    return $out;
}

function bgl_exploration_failure_stats(string $dbPath, int $minutes = 120): array {
    $cutoff = time() - ($minutes * 60);
    $out = [
        'dom_no_change' => 0,
        'search_no_change' => 0,
        'http_error' => 0,
        'network_fail' => 0,
        'gap_deepen' => 0,
        'gap_deepen_recent' => 0,
        'total' => 0,
        'failure_rate' => 0.0,
        'status' => 'UNKNOWN',
    ];
    if (!file_exists($dbPath)) return $out;
    try {
        $lite = new PDO("sqlite:" . $dbPath);
        $stmt = $lite->prepare(
            "SELECT event_type, COUNT(*) c FROM runtime_events WHERE timestamp >= ? AND event_type IN ('dom_no_change','search_no_change','http_error','network_fail','gap_deepen') GROUP BY event_type"
        );
        $stmt->execute([$cutoff]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = $r['event_type'];
            $out[$k] = (int)$r['c'];
        }
        $out['total'] = array_sum([$out['dom_no_change'], $out['search_no_change'], $out['http_error'], $out['network_fail']]);
        if ($out['total'] > 0) {
            $out['failure_rate'] = round((($out['dom_no_change'] + $out['search_no_change'] + $out['http_error'] + $out['network_fail']) / $out['total']) * 100, 1);
        }
        // gap_deepen from outcomes (recent)
        try {
            $stmt2 = $lite->prepare("SELECT COUNT(*) FROM autonomy_goals WHERE goal='gap_deepen' AND created_at >= ?");
            $stmt2->execute([$cutoff]);
            $out['gap_deepen_recent'] = (int)$stmt2->fetchColumn();
        } catch (\Exception $e) {}
        if ($out['failure_rate'] >= 40 || $out['gap_deepen_recent'] >= 5) {
            $out['status'] = 'STALLED';
        } elseif ($out['failure_rate'] <= 10 && $out['gap_deepen_recent'] == 0) {
            $out['status'] = 'STABLE';
        } else {
            $out['status'] = 'MIXED';
        }
    } catch (\Exception $e) {}
    return $out;
}

// Serve report HTML (latest or template) via dashboard endpoint.
if (isset($_GET['report'])) {
    $type = (string)$_GET['report'];
    $reportMap = [
        'latest' => $projectRoot . '/.bgl_core/logs/latest_report.html',
        'template' => $projectRoot . '/.bgl_core/brain/report_template.html',
    ];
    if (isset($reportMap[$type]) && file_exists($reportMap[$type])) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($reportMap[$type]);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo "Report not available.";
    exit;
}

// Serve proposal diff (sandbox) if available.
if (isset($_GET['diff'])) {
    $pid = preg_replace('/[^0-9]/', '', (string)$_GET['diff']);
    $diffPath = $projectRoot . '/.bgl_core/logs/proposal_' . $pid . '_sandbox.diff';
    if ($pid !== '' && file_exists($diffPath)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($diffPath);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo "Diff not available.";
    exit;
}

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
        bgl_respond(
            ['ok' => true, 'message' => 'تم حل المشكلة بنجاح.', 'remove' => 'blocker', 'id' => $id],
            "agent-dashboard.php?resolved=" . $id
        );
    }
    bgl_respond(['ok' => false, 'message' => 'قاعدة المعرفة غير متوفرة.'], "agent-dashboard.php?error=db");
}

/**
 * Handle Rule Commitment via POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commit_rule') {
    $rule_id = $_POST['rule_id'];
    $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/commit_rule.py") . " " . escapeshellarg($rule_id);
    exec($cmd, $output, $return_var);
    if ($return_var === 0) {
        bgl_respond(['ok' => true, 'message' => 'تم دمج القاعدة بنجاح.'], "agent-dashboard.php?committed=" . $rule_id);
    } else {
        bgl_respond(['ok' => false, 'message' => 'تعذر دمج القاعدة.'], "agent-dashboard.php?error=commit_failed");
    }
}

// Handle Experience actions (promote/ignore/accept/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'experience_action') {
    $expHash = trim((string)($_POST['exp_hash'] ?? ''));
    $expAction = trim((string)($_POST['exp_action'] ?? ''));
    $expScenario = trim((string)($_POST['exp_scenario'] ?? ''));
    $expSummary = trim((string)($_POST['exp_summary'] ?? ''));
    if ($expHash === '' && ($expScenario !== '' || $expSummary !== '')) {
        $expHash = bgl_experience_hash($expScenario, $expSummary);
    }
    if ($expHash === '' || $expAction === '') {
        bgl_respond(['ok' => false, 'message' => 'بيانات الخبرة غير مكتملة.']);
    }
    $agentDbPath = dirname(__DIR__) . '/.bgl_core/brain/knowledge.db';
    try {
        $lite = new PDO("sqlite:" . $agentDbPath);
        $lite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lite->exec("CREATE TABLE IF NOT EXISTS experience_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exp_hash TEXT UNIQUE,
            action TEXT,
            created_at REAL
        )");
        $stmt = $lite->prepare("INSERT OR REPLACE INTO experience_actions (exp_hash, action, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$expHash, $expAction, time()]);

        if ($expAction === 'promote') {
            $name = $expScenario !== '' ? "تحسين من خبرة: {$expScenario}" : "تحسين من خبرة";
            $desc = $expSummary !== '' ? $expSummary : "تم تحويل خبرة إلى اقتراح للمراجعة.";
            $action = "investigate";
            $evidence = json_encode(['scenario' => $expScenario, 'summary' => $expSummary, 'hash' => $expHash], JSON_UNESCAPED_UNICODE);
            $ins = $lite->prepare("INSERT INTO agent_proposals (name, description, action, count, evidence, impact, solution, expectation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$name, $desc, $action, 1, $evidence, 'low', '', '']);
            $stmt = $lite->prepare("INSERT OR REPLACE INTO experience_actions (exp_hash, action, created_at) VALUES (?, ?, ?)");
            $stmt->execute([$expHash, 'promoted', time()]);
        }
        bgl_respond(['ok' => true, 'message' => 'تم تحديث الخبرة.']);
    } catch (\Exception $e) {
        bgl_respond(['ok' => false, 'message' => 'تعذر تحديث الخبرة.']);
    }
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
        bgl_respond(
            ['ok' => true, 'message' => 'تم تحديث قرار الصلاحية.', 'remove' => 'permission', 'id' => $perm_id],
            "agent-dashboard.php?perm_updated=" . $perm_id
        );
    } catch (\Exception $e) {
        bgl_respond(['ok' => false, 'message' => 'فشل تحديث الصلاحية.'], "agent-dashboard.php?error=db");
    }
}

/**
 * Handle Context Digest (runtime_events -> experiences)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'digest') {
    $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/context_digest.py");
    exec($cmd, $output, $return_var);
    if ($return_var === 0) {
        bgl_respond(['ok' => true, 'message' => 'تم تلخيص الأحداث إلى الخبرات.'], "agent-dashboard.php?digested=1");
    } else {
        bgl_respond(['ok' => false, 'message' => 'فشل تحديث الخبرة.'], "agent-dashboard.php?error=digest");
    }
}

/**
 * Handle Master Verify trigger (fire-and-forget)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assure') {
    $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/master_verify.py");
    bgl_start_bg($cmd);
    bgl_respond(['ok' => true, 'message' => 'تم إطلاق الفحص الشامل في الخلفية.'], "agent-dashboard.php?assure_started=1");
}

// Run scenarios explicitly (fire-and-forget). Falls back to master_verify with env flag.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_scenarios') {
    $runner = '.bgl_core/brain/run_scenarios.py';
    if (file_exists($projectRoot . '/' . $runner)) {
        $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/" . $runner);
        bgl_start_bg($cmd);
    } else {
        putenv("BGL_RUN_SCENARIOS=1");
        $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/master_verify.py");
        bgl_start_bg($cmd);
    }
    bgl_respond(['ok' => true, 'message' => 'تم تشغيل السيناريوهات في الخلفية.'], "agent-dashboard.php?scenarios_started=1");
}

// Update runtime flags (agent_flags.json) from dashboard controls
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_flags') {
    $flagsPath = dirname(__DIR__) . '/storage/agent_flags.json';
    $flags = [
        'execution_mode' => $_POST['execution_mode'] ?? 'sandbox',
        'agent_mode' => $_POST['agent_mode'] ?? 'assisted',
        'decision' => ['mode' => $_POST['decision_mode'] ?? 'assisted'],
        'scenario_exploration' => isset($_POST['scenario_exploration']) ? 1 : 0,
        'novelty_auto' => isset($_POST['novelty_auto']) ? 1 : 0,
        'autonomous_scenario' => isset($_POST['autonomous_scenario']) ? 1 : 0,
        'autonomous_only' => isset($_POST['autonomous_only']) ? 1 : 0,
        'autonomous_max_steps' => (int)($_POST['autonomous_max_steps'] ?? 8),
        'autonomous_ui_limit' => (int)($_POST['autonomous_ui_limit'] ?? 120),
        'autonomous_avoid_upload' => isset($_POST['autonomous_avoid_upload']) ? 1 : 0,
        'upload_file' => trim((string)($_POST['upload_file'] ?? '')),
        'scenario_include_api' => isset($_POST['scenario_include_api']) ? 1 : 0,
        'run_scenarios' => isset($_POST['run_scenarios']) ? 1 : 0,
        'keep_browser' => isset($_POST['keep_browser']) ? 1 : 0,
        'headless' => isset($_POST['headless']) ? 1 : 0,
        'base_url' => trim((string)($_POST['base_url'] ?? '')),
        'tool_server_port' => (int)($_POST['tool_server_port'] ?? 8891),
        'llm' => [
            'base_url' => trim((string)($_POST['llm_base_url'] ?? '')),
            'model' => trim((string)($_POST['llm_model'] ?? '')),
            'chat_timeout' => (int)($_POST['llm_chat_timeout'] ?? 60),
            'warmup_max_wait' => (int)($_POST['llm_warmup_max_wait'] ?? 45),
            'warmup_poll_s' => (float)($_POST['llm_warmup_poll_s'] ?? 2),
        ],
    ];

    // Clean empty overrides to avoid masking config.yml
    if ($flags['upload_file'] === '') unset($flags['upload_file']);
    if ($flags['base_url'] === '') unset($flags['base_url']);
    if (empty($flags['llm']['base_url'])) unset($flags['llm']['base_url']);
    if (empty($flags['llm']['model'])) unset($flags['llm']['model']);

    bgl_write_json($flagsPath, $flags);
    bgl_respond(['ok' => true, 'message' => 'تم حفظ الإعدادات.'], "agent-dashboard.php?flags_saved=1");
}

// Add a manual autonomy goal (operator directive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_goal') {
    $goal = trim((string)($_POST['goal'] ?? 'operator_goal'));
    $message = trim((string)($_POST['goal_message'] ?? ''));
    $uri = trim((string)($_POST['goal_uri'] ?? ''));
    $expiresHours = (int)($_POST['expires_hours'] ?? 24);
    if ($goal === '') $goal = 'operator_goal';

    $payload = [];
    if ($uri !== '') $payload['uri'] = $uri;
    if ($message !== '') $payload['message'] = $message;
    if (empty($payload)) {
        $payload['message'] = 'Operator goal';
    }
    $expiresAt = $expiresHours > 0 ? (time() + ($expiresHours * 3600)) : null;
    $dbPath = $projectRoot . '/.bgl_core/brain/knowledge.db';
    if (!file_exists($dbPath)) {
        bgl_respond(['ok' => false, 'message' => 'قاعدة المعرفة غير متوفرة.']);
    }
    try {
        $lite = new PDO("sqlite:" . $dbPath);
        $lite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lite->exec("CREATE TABLE IF NOT EXISTS autonomy_goals (id INTEGER PRIMARY KEY AUTOINCREMENT, goal TEXT, payload TEXT, source TEXT, created_at REAL, expires_at REAL)");
        $stmt = $lite->prepare("INSERT INTO autonomy_goals (goal, payload, source, created_at, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $goal,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            'operator',
            time(),
            $expiresAt,
        ]);
        bgl_respond(['ok' => true, 'message' => 'تم إضافة هدف موجّه للوكيل.'], "agent-dashboard.php?goal_added=1");
    } catch (\Exception $e) {
        bgl_respond(['ok' => false, 'message' => 'تعذر حفظ الهدف.']);
    }
}

// Warm up local LLM and write status snapshot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'warm_llm') {
    $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/llm_status.py") . " --warm";
    bgl_start_bg($cmd);
    bgl_respond(['ok' => true, 'message' => 'تم إرسال أمر تسخين الذكاء المحلي.'], "agent-dashboard.php?llm_warm_started=1");
}

// Run autonomous scenario only (no predefined scenarios)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_autonomous_now') {
    $runner = escapeshellarg($projectRoot . "/.bgl_core/brain/scenario_runner.py");
    $env = 'BGL_AUTONOMOUS_ONLY=1;BGL_AUTONOMOUS_SCENARIO=1';
    $args = $projectRoot . "/.bgl_core/brain/scenario_runner.py";
    $ps = '$env:' . $env . '; ' .
        'Start-Process -WindowStyle Hidden -FilePath ' . escapeshellarg($pythonBin) .
        ' -ArgumentList ' . escapeshellarg($args);
    $full = 'powershell -NoProfile -Command ' . escapeshellarg($ps);
    pclose(popen($full, "r"));
    bgl_respond(['ok' => true, 'message' => 'تم إطلاق سيناريو ذاتي واحد.'], "agent-dashboard.php?autonomous_started=1");
}

// Start tool_server (Copilot bridge)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_tool_server') {
    $port = isset($_POST['tool_server_port']) ? (int)$_POST['tool_server_port'] : 8891;
    bgl_start_tool_watchdog_bg($pythonBin, $projectRoot . "/scripts/tool_watchdog.py", $port);
    bgl_respond(['ok' => true, 'message' => 'تم تشغيل مراقب الجسر (Watchdog).'], "agent-dashboard.php?tool_server_started=1");
}

/**
 * Handle Permission Auto-Fix via POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix_permissions') {
    // 1. Fix Logs
    $logDir = $projectRoot . '/storage/logs';
    if (!is_dir($logDir)) { mkdir($logDir, 0777, true); }
    $logFile = $logDir . '/test.log';
    if (!file_exists($logFile)) { touch($logFile); }
    chmod($logFile, 0666); // Ensure writable

    // 2. Fix Config
    $configDir = $projectRoot . '/app/Config';
    if (!is_dir($configDir)) { mkdir($configDir, 0777, true); }
    $configFile = $configDir . '/agent.json';
    if (!file_exists($configFile)) { 
        file_put_contents($configFile, json_encode(["status" => "initialized"])); 
    }
    chmod($configFile, 0666); // Ensure writable

    bgl_respond(['ok' => true, 'message' => 'تم إصلاح الصلاحيات الأساسية.'], "agent-dashboard.php?fixed_permissions=1");
}


// Run API contract/property tests (Schemathesis/Dredd) via master_verify toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_api_contract') {
    putenv("BGL_RUN_SCENARIOS=0");
    putenv("BGL_RUN_API_CONTRACT=1");
    $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/master_verify.py");
    bgl_start_bg($cmd);
    bgl_respond(['ok' => true, 'message' => 'تم تشغيل فحص عقود الـ API.'], "agent-dashboard.php?api_contract_started=1");
}

// Run Python tests (smoke, full, or custom) and PHPUnit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['run_pytest_smoke', 'run_pytest_full', 'run_pytest_custom', 'run_phpunit', 'run_ci'])) {
    $logDir = $projectRoot . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $action = (string)$_POST['action'];
    $logPath = $logDir . '/dashboard_' . $action . '.log';
    $cmd = '';

    if ($action === 'run_pytest_smoke') {
        $cmd = "{$pythonEsc} -m pytest tests/test_llm_tools.py tests/test_logic_bridge_contract.py";
    } elseif ($action === 'run_pytest_full') {
        $cmd = "{$pythonEsc} -m pytest";
    } elseif ($action === 'run_pytest_custom') {
        $selected = $_POST['pytest_files'] ?? [];
        $selected = is_array($selected) ? $selected : [];
        $testsRoot = realpath($projectRoot . '/tests');
        $safeFiles = [];
        foreach ($selected as $f) {
            $f = trim((string)$f);
            if ($f === '') continue;
            $candidate = realpath($projectRoot . '/' . ltrim($f, '/\\'));
            if ($candidate && $testsRoot && str_starts_with($candidate, $testsRoot) && file_exists($candidate)) {
                $rel = str_replace('\\', '/', substr($candidate, strlen($projectRoot) + 1));
                $safeFiles[] = $rel;
            }
        }
        $argsRaw = trim((string)($_POST['pytest_args'] ?? ''));
        if ($argsRaw !== '' && !preg_match('/^[A-Za-z0-9_\\.\\-\\s\\/:=]+$/', $argsRaw)) {
            bgl_respond(['ok' => false, 'message' => 'خيارات Pytest غير صالحة.']);
        }
        if (empty($safeFiles)) {
            bgl_respond(['ok' => false, 'message' => 'اختر ملف اختبار واحد على الأقل.']);
        }
        $fileArgs = array_map('escapeshellarg', $safeFiles);
        $extraArgs = [];
        if ($argsRaw !== '') {
            foreach (preg_split('/\\s+/', $argsRaw) as $part) {
                if ($part !== '') $extraArgs[] = escapeshellarg($part);
            }
        }
        $cmd = "{$pythonEsc} -m pytest " . implode(' ', $fileArgs) . ' ' . implode(' ', $extraArgs);
    } elseif ($action === 'run_phpunit') {
        $phpunit = $projectRoot . '/vendor/bin/phpunit';
        if (file_exists($phpunit . '.bat')) {
            $phpunit = $phpunit . '.bat';
        }
        $cmd = escapeshellarg($phpunit);
    } elseif ($action === 'run_ci') {
        $ciPath = $projectRoot . '/run_ci.ps1';
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($ciPath);
    }

    if ($cmd !== '') {
        $cmdLogged = $cmd . ' > ' . escapeshellarg($logPath) . ' 2>&1';
        bgl_start_bg($cmdLogged);
        bgl_record_dashboard_run($projectRoot, $action, $cmd, $logPath);
        bgl_respond(['ok' => true, 'message' => 'تم إطلاق الاختبار في الخلفية.'], "agent-dashboard.php?test_started=" . urlencode($action));
    }
    bgl_respond(['ok' => false, 'message' => 'تعذر تشغيل الاختبار.']);
}

// Restart browser: clear status file; a new launch will recreate it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restart_browser') {
    $statusPath = $projectRoot . '/.bgl_core/logs/browser_reports/browser_status.json';
    if (file_exists($statusPath)) {
        @unlink($statusPath);
    }
    bgl_respond(['ok' => true, 'message' => 'تمت إعادة ضبط حالة المتصفح.'], "agent-dashboard.php?browser_restarted=1");
}

/**
 * Toggle agent_mode (assisted <-> auto). If safe is ever set, next toggle goes to assisted.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_mode') {
    $cfgPath = $projectRoot . '/.bgl_core/config.yml';
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
    bgl_respond(['ok' => true, 'message' => 'تم تبديل وضع الوكيل.'], "agent-dashboard.php?mode_toggled=1");
}

// Apply proposal in sandbox (fire-and-forget orchestrator)
// Apply proposal in sandbox (fire-and-forget orchestrator)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['apply_proposal', 'force_apply'])) {
    $pid = $_POST['proposal_id'] ?? '';
    $isForce = $_POST['action'] === 'force_apply';
    $flag = $isForce ? '--force' : '';
    $planArg = '';
    if (!empty($_POST['plan_path'])) {
        $absPlan = bgl_normalize_plan_path($projectRoot, (string)$_POST['plan_path']);
        if ($absPlan && file_exists($absPlan)) {
            $relPlan = bgl_relative_path($projectRoot, $absPlan);
            $planArg = ' --plan ' . escapeshellarg($relPlan);
        }
    }
    
    if ($pid) {
        $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/apply_proposal.py") . " --proposal {$pid} {$flag}{$planArg}";
        bgl_start_bg($cmd);
        $msg = $isForce ? "proposal_forced=" . urlencode($pid) : "proposal_started=" . urlencode($pid);
        bgl_respond(
            ['ok' => true, 'message' => $isForce ? 'تم التطبيق المباشر للاقتراح.' : 'تم تشغيل الاقتراح في الساندبوكس.', 'remove' => 'proposal', 'id' => $pid],
            "agent-dashboard.php?" . $msg
        );
    }
}

// Generate a patch plan for a proposal (LLM-assisted)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_plan') {
    $pid = trim((string)($_POST['proposal_id'] ?? ''));
    if ($pid === '') {
        bgl_respond(['ok' => false, 'message' => 'معرّف الاقتراح غير صالح.']);
    }
    $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/generate_patch_plan.py") . " --proposal " . escapeshellarg($pid);
    exec($cmd, $output, $return_var);
    if ($return_var === 0) {
        bgl_respond(['ok' => true, 'message' => 'تم توليد خطة للـ اقتراح.', 'reload' => true], "agent-dashboard.php?plan_generated=" . urlencode($pid));
    }
    bgl_respond(['ok' => false, 'message' => 'فشل توليد الخطة.']);
}

// Upload a patch plan and optionally attach it to a proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_plan') {
    $pid = trim((string)($_POST['proposal_id'] ?? ''));
    if (!isset($_FILES['plan_file'])) {
        bgl_respond(['ok' => false, 'message' => 'لم يتم اختيار ملف الخطة.']);
    }
    $file = $_FILES['plan_file'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        bgl_respond(['ok' => false, 'message' => 'فشل رفع ملف الخطة.']);
    }
    $orig = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['json', 'yml', 'yaml'], true)) {
        bgl_respond(['ok' => false, 'message' => 'صيغة الخطة غير مدعومة.']);
    }
    $planDir = bgl_patch_plan_dir($projectRoot);
    $base = bgl_safe_plan_name(pathinfo($orig, PATHINFO_FILENAME));
    $target = $planDir . '/' . $base . '.' . $ext;
    if (file_exists($target)) {
        $target = $planDir . '/' . $base . '_' . date('Ymd_His') . '.' . $ext;
    }
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        bgl_respond(['ok' => false, 'message' => 'تعذر حفظ ملف الخطة.']);
    }
    $rel = bgl_relative_path($projectRoot, $target);
    if ($pid !== '' && file_exists($projectRoot . '/.bgl_core/brain/knowledge.db')) {
        try {
            $lite = new PDO("sqlite:" . $projectRoot . '/.bgl_core/brain/knowledge.db');
            $stmt = $lite->prepare("UPDATE agent_proposals SET solution = ? WHERE id = ?");
            $stmt->execute([$rel, $pid]);
        } catch (\Exception $e) {}
    }
    bgl_respond(['ok' => true, 'message' => 'تم رفع الخطة وربطها بنجاح.', 'reload' => true], "agent-dashboard.php?plan_uploaded=1");
}

// Attach an existing plan to a proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'attach_plan') {
    $pid = trim((string)($_POST['proposal_id'] ?? ''));
    $planPath = trim((string)($_POST['plan_path'] ?? ''));
    if ($pid === '' || $planPath === '') {
        bgl_respond(['ok' => false, 'message' => 'بيانات الخطة غير مكتملة.']);
    }
    $absPlan = bgl_normalize_plan_path($projectRoot, $planPath);
    if (!$absPlan || !file_exists($absPlan)) {
        bgl_respond(['ok' => false, 'message' => 'مسار الخطة غير صالح.']);
    }
    $rel = bgl_relative_path($projectRoot, $absPlan);
    try {
        $lite = new PDO("sqlite:" . $projectRoot . '/.bgl_core/brain/knowledge.db');
        $stmt = $lite->prepare("UPDATE agent_proposals SET solution = ? WHERE id = ?");
        $stmt->execute([$rel, $pid]);
    } catch (\Exception $e) {}
    bgl_respond(['ok' => true, 'message' => 'تم ربط الخطة بالاقتراح.', 'reload' => true], "agent-dashboard.php?plan_attached=1");
}

// Clear attached plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_plan') {
    $pid = trim((string)($_POST['proposal_id'] ?? ''));
    if ($pid === '') {
        bgl_respond(['ok' => false, 'message' => 'بيانات الخطة غير مكتملة.']);
    }
    try {
        $lite = new PDO("sqlite:" . $projectRoot . '/.bgl_core/brain/knowledge.db');
        $stmt = $lite->prepare("UPDATE agent_proposals SET solution = '' WHERE id = ?");
        $stmt->execute([$pid]);
    } catch (\Exception $e) {}
    bgl_respond(['ok' => true, 'message' => 'تم إزالة الخطة من الاقتراح.', 'reload' => true], "agent-dashboard.php?plan_cleared=1");
}

// Apply a patch plan directly (creates proposal if needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['apply_plan', 'force_plan'])) {
    $planPath = trim((string)($_POST['plan_path'] ?? ''));
    $pid = trim((string)($_POST['proposal_id'] ?? ''));
    $isForce = $_POST['action'] === 'force_plan';
    if ($planPath === '') {
        bgl_respond(['ok' => false, 'message' => 'مسار الخطة غير محدد.']);
    }
    $absPlan = bgl_normalize_plan_path($projectRoot, $planPath);
    if (!$absPlan || !file_exists($absPlan)) {
        bgl_respond(['ok' => false, 'message' => 'مسار الخطة غير صالح.']);
    }
    $rel = bgl_relative_path($projectRoot, $absPlan);
    if ($pid === '' && file_exists($projectRoot . '/.bgl_core/brain/knowledge.db')) {
        try {
            $lite = new PDO("sqlite:" . $projectRoot . '/.bgl_core/brain/knowledge.db');
            $name = 'خطة كتابة: ' . basename($rel) . ' #' . date('His');
            $desc = 'اقتراح تلقائي لتطبيق خطة كتابة.';
            $ins = $lite->prepare("INSERT INTO agent_proposals (name, description, action, count, evidence, impact, solution, expectation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$name, $desc, 'apply_plan', 1, '', 'medium', $rel, '']);
            $pid = (string)($lite->lastInsertId() ?: '');
        } catch (\Exception $e) {}
    } elseif ($pid !== '') {
        try {
            $lite = new PDO("sqlite:" . $projectRoot . '/.bgl_core/brain/knowledge.db');
            $stmt = $lite->prepare("UPDATE agent_proposals SET solution = ? WHERE id = ?");
            $stmt->execute([$rel, $pid]);
        } catch (\Exception $e) {}
    }
    if ($pid === '') {
        bgl_respond(['ok' => false, 'message' => 'تعذر إنشاء اقتراح للخطة.']);
    }
    $flag = $isForce ? '--force' : '';
    $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/apply_proposal.py") . " --proposal {$pid} {$flag} --plan " . escapeshellarg($rel);
    bgl_start_bg($cmd);
    $msg = $isForce ? "proposal_forced=" . urlencode($pid) : "proposal_started=" . urlencode($pid);
    bgl_respond(['ok' => true, 'message' => $isForce ? 'تم التطبيق المباشر للخطة.' : 'تم تشغيل الخطة في الساندبوكس.', 'reload' => true], "agent-dashboard.php?" . $msg);
}

// Approve auto-generated playbook (GET for simplicity)
if (isset($_GET['action']) && $_GET['action'] === 'approve_playbook' && !empty($_GET['id'])) {
    $pid = preg_replace('/[^A-Za-z0-9_\\-]/', '', $_GET['id']);
    $cmd = "{$pythonEsc} " . escapeshellarg($projectRoot . "/.bgl_core/brain/approve_playbook.py") . " " . escapeshellarg($pid);
    exec($cmd, $output, $return_var);
    if ($return_var === 0) {
        bgl_respond(['ok' => true, 'message' => 'تم اعتماد الـ Playbook.', 'remove' => 'playbook', 'id' => $pid], "agent-dashboard.php?playbook_approved={$pid}");
    } else {
        bgl_respond(['ok' => false, 'message' => 'فشل اعتماد الـ Playbook.'], "agent-dashboard.php?error=playbook");
    }
}

// Reject auto-generated playbook (delete proposed file)
if (isset($_GET['action']) && $_GET['action'] === 'reject_playbook' && !empty($_GET['id'])) {
    $pid = preg_replace('/[^A-Za-z0-9_\\-]/', '', $_GET['id']);
    $file = $projectRoot . "/.bgl_core/brain/playbooks_proposed/{$pid}.md";
    if (file_exists($file)) {
        unlink($file);
    }
    bgl_respond(['ok' => true, 'message' => 'تم رفض الـ Playbook.', 'remove' => 'playbook', 'id' => $pid], "agent-dashboard.php?playbook_rejected=" . urlencode($pid));
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
} elseif (isset($_GET['flags_saved'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم حفظ الإعدادات',
        'message' => 'تم تحديث مفاتيح التحكم السلوكي للوكيل.'
    ];
} elseif (isset($_GET['plan_uploaded'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم رفع الخطة',
        'message' => 'تم حفظ خطة الكتابة وربطها بالاقتراح.'
    ];
} elseif (isset($_GET['plan_attached'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم ربط الخطة',
        'message' => 'تم ربط الخطة بالاقتراح.'
    ];
} elseif (isset($_GET['plan_cleared'])) {
    $feedback = [
        'type' => 'info',
        'title' => 'تم إزالة الخطة',
        'message' => 'تم إزالة الخطة من الاقتراح.'
    ];
} elseif (isset($_GET['plan_generated'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تم توليد الخطة',
        'message' => 'تم توليد خطة كتابة لهذا الاقتراح.'
    ];
} elseif (isset($_GET['llm_warm_started'])) {
    $feedback = [
        'type' => 'info',
        'title' => 'تسخين الذكاء المحلي',
        'message' => 'تم إرسال أمر التسخين. راقب حالة LLM في اللوحة.'
    ];
} elseif (isset($_GET['autonomous_started'])) {
    $feedback = [
        'type' => 'info',
        'title' => 'تشغيل سيناريو ذاتي',
        'message' => 'تم إطلاق سيناريو ذاتي واحد في الخلفية.'
    ];
} elseif (isset($_GET['tool_server_started'])) {
    $feedback = [
        'type' => 'info',
        'title' => 'تشغيل جسر المحادثة',
        'message' => 'تم إطلاق tool_server.py على المنفذ المحلي.'
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
} elseif (isset($_GET['goal_added'])) {
    $feedback = [
        'type' => 'success',
        'title' => 'تمت إضافة الهدف',
        'message' => 'سيظهر الهدف ضمن قائمة الأهداف الحالية.'
    ];
} elseif (isset($_GET['test_started'])) {
    $feedback = [
        'type' => 'info',
        'title' => 'تم إطلاق الاختبار',
        'message' => 'تم تشغيل الاختبار في الخلفية. راجع سجل الاختبارات لنتيجة التنفيذ.'
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

function bgl_read_json(string $path): array {
    if (!file_exists($path)) return [];
    try {
        $raw = file_get_contents($path);
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    } catch (\Exception $e) {
        return [];
    }
}

function bgl_write_json(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $payload) !== false;
}

function bgl_patch_plan_dir(string $projectRoot): string {
    $dir = $projectRoot . '/.bgl_core/patch_plans';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function bgl_relative_path(string $projectRoot, string $absPath): string {
    $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    $abs = str_replace('\\', '/', $absPath);
    if (str_starts_with($abs, $projectRoot . '/')) {
        return substr($abs, strlen($projectRoot) + 1);
    }
    return $abs;
}

function bgl_safe_plan_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[^A-Za-z0-9_\\-\\.]+/', '_', $name);
    $name = trim($name, '._-');
    return $name !== '' ? $name : 'plan';
}

function bgl_normalize_plan_path(string $projectRoot, string $path, bool $mustExist = true): ?string {
    $base = realpath(bgl_patch_plan_dir($projectRoot));
    if (!$base) return null;
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') return null;
    if (str_contains($path, '..')) return null;
    $candidate = $path;
    if (!preg_match('/^[A-Za-z]:\\//', $candidate) && !str_starts_with($candidate, '/')) {
        $candidate = $base . '/' . ltrim($candidate, '/');
    }
    $candidate = str_replace('\\', '/', $candidate);
    $real = $mustExist ? realpath($candidate) : $candidate;
    if ($real === false) return null;
    $real = str_replace('\\', '/', $real);
    if (!str_starts_with($real, str_replace('\\', '/', $base))) return null;
    return $real;
}

function bgl_list_patch_plans(string $projectRoot): array {
    $dir = bgl_patch_plan_dir($projectRoot);
    if (!is_dir($dir)) return [];
    $plans = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['json','yml','yaml'], true)) continue;
        $abs = $file->getPathname();
        $plans[] = [
            'path' => bgl_relative_path($projectRoot, $abs),
            'name' => $file->getBasename(),
            'mtime' => $file->getMTime(),
            'size' => $file->getSize(),
        ];
    }
    usort($plans, fn($a, $b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));
    return $plans;
}

function bgl_detect_plan_path(string $projectRoot, string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    // direct path
    if (preg_match('/\\.(json|ya?ml)$/i', $value)) {
        $abs = bgl_normalize_plan_path($projectRoot, $value);
        if ($abs && file_exists($abs)) return bgl_relative_path($projectRoot, $abs);
    }
    // embedded json
    $payload = json_decode($value, true);
    if (is_array($payload)) {
        foreach (['plan','patch_plan','write_plan'] as $k) {
            $cand = $payload[$k] ?? null;
            if (is_string($cand) && preg_match('/\\.(json|ya?ml)$/i', $cand)) {
                $abs = bgl_normalize_plan_path($projectRoot, $cand);
                if ($abs && file_exists($abs)) return bgl_relative_path($projectRoot, $abs);
            }
        }
    }
    return null;
}

function bgl_tail_file(string $path, int $lines = 120, int $maxBytes = 120000): string {
    if (!file_exists($path)) return '';
    $size = filesize($path);
    if ($size === false || $size <= 0) return '';
    $readBytes = min($size, max(1024, $maxBytes));
    $fp = @fopen($path, 'rb');
    if (!$fp) return '';
    $offset = $size - $readBytes;
    if ($offset < 0) $offset = 0;
    fseek($fp, $offset);
    $data = fread($fp, $readBytes);
    fclose($fp);
    if ($data === false) return '';
    if ($offset > 0) {
        $pos = strpos($data, "\n");
        if ($pos !== false) {
            $data = substr($data, $pos + 1);
        }
    }
    $rows = preg_split("/\r\n|\n/", (string)$data);
    $rows = array_slice($rows, -$lines);
    return implode("\n", $rows);
}

function bgl_read_diff_snippet(string $path, int $lines = 160): string {
    return bgl_tail_file($path, $lines, 200000);
}

function bgl_record_dashboard_run(string $projectRoot, string $key, string $command, string $logPath): void {
    $runsPath = $projectRoot . '/storage/logs/dashboard_runs.json';
    $runs = bgl_read_json($runsPath);
    $runs[$key] = [
        'timestamp' => time(),
        'command' => $command,
        'log' => $logPath,
    ];
    bgl_write_json($runsPath, $runs);
}

function bgl_deep_merge(array $base, array $override): array {
    $merged = $base;
    foreach ($override as $k => $v) {
        if (is_array($v) && isset($merged[$k]) && is_array($merged[$k])) {
            $merged[$k] = bgl_deep_merge($merged[$k], $v);
        } else {
            $merged[$k] = $v;
        }
    }
    return $merged;
}

function bgl_get_nested(array $data, string $path, $default = null) {
    $cur = $data;
    foreach (explode('.', $path) as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) {
            return $default;
        }
        $cur = $cur[$p];
    }
    return $cur;
}

function bgl_http_get_json(string $url, float $timeout = 0.3): ?array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === null) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function bgl_http_post_json(string $url, array $payload, float $timeout = 0.6): ?array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === null) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function bgl_llm_base_api(string $url): string {
    $u = trim($url);
    if ($u === '') return $u;
    // Normalize to base API (strip /v1/chat/completions if present)
    $u = preg_replace('#/v1/chat/completions/?$#', '', $u);
    return rtrim($u, '/');
}

function bgl_service_ping(string $url, float $timeout = 1.5): bool {
    $resp = bgl_http_get_json($url, $timeout);
    if (!is_array($resp)) return false;
    $status = strtolower((string)($resp['status'] ?? ''));
    return $status === 'ok';
}

function bgl_llm_warmup(string $baseUrl, string $model = ''): bool {
    $base = bgl_llm_base_api($baseUrl);
    if ($base === '') return false;
    $tags = bgl_http_get_json($base . '/api/tags', 0.6);
    if ($model === '' && is_array($tags) && isset($tags['models']) && is_array($tags['models'])) {
        $names = array_map(fn($m) => (string)($m['name'] ?? ''), $tags['models']);
        $names = array_values(array_filter($names, fn($n) => $n !== ''));
        foreach ($names as $n) {
            if (stripos($n, 'llama3.1') !== false) { $model = $n; break; }
        }
        if ($model === '' && !empty($names)) {
            $model = $names[0];
        }
    }
    if ($model === '') return false;
    $payload = [
        'model' => $model,
        'prompt' => '',
        'keep_alive' => '10m',
    ];
    // Loading a model can take a few seconds; allow a larger timeout.
    $resp = bgl_http_post_json($base . '/api/generate', $payload, 10.0);
    return is_array($resp);
}

function bgl_llm_keepalive(string $baseUrl, string $cooldownPath, int $minSeconds = 45, string $model = ''): bool {
    if ($baseUrl === '') return false;
    if (!bgl_should_attempt($cooldownPath, $minSeconds)) return false;
    $base = bgl_llm_base_api($baseUrl);
    if ($base === '') return false;
    $tags = bgl_http_get_json($base . '/api/tags', 0.6);
    if ($model === '' && is_array($tags) && isset($tags['models']) && is_array($tags['models'])) {
        $names = array_map(fn($m) => (string)($m['name'] ?? ''), $tags['models']);
        $names = array_values(array_filter($names, fn($n) => $n !== ''));
        foreach ($names as $n) {
            if (stripos($n, 'llama3.1') !== false) { $model = $n; break; }
        }
        if ($model === '' && !empty($names)) {
            $model = $names[0];
        }
    }
    if ($model === '') return false;
    $payload = [
        'model' => $model,
        'prompt' => '',
        'keep_alive' => '10m',
    ];
    $resp = bgl_http_post_json($base . '/api/generate', $payload, 3.0);
    return is_array($resp);
}

function bgl_should_attempt(string $lockPath, int $minSeconds): bool {
    if (file_exists($lockPath)) {
        $age = time() - (int)@filemtime($lockPath);
        if ($age >= 0 && $age < $minSeconds) return false;
    }
    @touch($lockPath);
    return true;
}

function bgl_ensure_tool_server(
    string $pythonBin,
    string $scriptPath,
    int $port,
    string $cooldownPath,
    int $minSeconds = 15
): bool {
    $url = "http://127.0.0.1:{$port}/health";
    if (bgl_service_ping($url, 1.2)) return true;
    if (!bgl_should_attempt($cooldownPath, $minSeconds)) return false;
    bgl_start_tool_watchdog_bg($pythonBin, $scriptPath, $port);
    usleep(350000);
    return bgl_service_ping($url, 1.2);
}

function bgl_ensure_llm_hot(string $baseUrl, string $cooldownPath, int $minSeconds = 30, string $model = ''): bool {
    if ($baseUrl === '') return false;
    if (!bgl_should_attempt($cooldownPath, $minSeconds)) return false;
    return bgl_llm_warmup($baseUrl, $model);
}

function bgl_calc_data_quality_score(string $sqlitePath): ?float {
    if (!file_exists($sqlitePath)) return null;
    try {
        $db = new PDO("sqlite:" . $sqlitePath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $banksTotal = (int)$db->query("SELECT COUNT(*) FROM banks")->fetchColumn();
        $supTotal = (int)$db->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();

        $banks = $db->query("SELECT arabic_name, english_name, short_name, normalized_name, contact_email FROM banks")->fetchAll(PDO::FETCH_ASSOC);
        $suppliers = $db->query("SELECT official_name, normalized_name FROM suppliers")->fetchAll(PDO::FETCH_ASSOC);

        $validBanks = 0;
        foreach ($banks as $b) {
            $hasName = trim((string)($b['arabic_name'] ?? '')) !== ''
                || trim((string)($b['english_name'] ?? '')) !== ''
                || trim((string)($b['short_name'] ?? '')) !== '';
            $hasNorm = trim((string)($b['normalized_name'] ?? '')) !== '';
            if (!$hasName || !$hasNorm) continue;
            $email = trim((string)($b['contact_email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $validBanks++;
        }

        $validSuppliers = 0;
        foreach ($suppliers as $s) {
            $hasName = trim((string)($s['official_name'] ?? '')) !== '';
            $hasNorm = trim((string)($s['normalized_name'] ?? '')) !== '';
            if ($hasName && $hasNorm) $validSuppliers++;
        }

        $total = $banksTotal + $supTotal;
        if ($total === 0) return null;
        return round((($validBanks + $validSuppliers) / $total) * 100, 2);
    } catch (\Exception $e) {
        return null;
    }
}

function bgl_data_quality_details(string $sqlitePath, int $limit = 20): array {
    $out = [
        'banks_total' => 0,
        'suppliers_total' => 0,
        'banks_invalid' => [],
        'suppliers_invalid' => [],
    ];
    if (!file_exists($sqlitePath)) return $out;
    try {
        $db = new PDO("sqlite:" . $sqlitePath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $out['banks_total'] = (int)$db->query("SELECT COUNT(*) FROM banks")->fetchColumn();
        $out['suppliers_total'] = (int)$db->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();

        $banks = $db->query("SELECT id, arabic_name, english_name, short_name, normalized_name, contact_email FROM banks")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($banks as $b) {
            $hasName = trim((string)($b['arabic_name'] ?? '')) !== ''
                || trim((string)($b['english_name'] ?? '')) !== ''
                || trim((string)($b['short_name'] ?? '')) !== '';
            $hasNorm = trim((string)($b['normalized_name'] ?? '')) !== '';
            $email = trim((string)($b['contact_email'] ?? ''));
            $emailOk = ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL));
            if ($hasName && $hasNorm && $emailOk) continue;
            if (count($out['banks_invalid']) < $limit) {
                $out['banks_invalid'][] = [
                    'id' => $b['id'] ?? '',
                    'name' => ($b['arabic_name'] ?: ($b['english_name'] ?: ($b['short_name'] ?: 'غير معروف'))),
                    'issues' => [
                        $hasName ? null : 'اسم مفقود',
                        $hasNorm ? null : 'normalized_name مفقود',
                        $emailOk ? null : 'contact_email غير صالح',
                    ],
                ];
            }
        }

        $suppliers = $db->query("SELECT id, official_name, normalized_name FROM suppliers")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($suppliers as $s) {
            $hasName = trim((string)($s['official_name'] ?? '')) !== '';
            $hasNorm = trim((string)($s['normalized_name'] ?? '')) !== '';
            if ($hasName && $hasNorm) continue;
            if (count($out['suppliers_invalid']) < $limit) {
                $out['suppliers_invalid'][] = [
                    'id' => $s['id'] ?? '',
                    'name' => ($s['official_name'] ?: 'غير معروف'),
                    'issues' => [
                        $hasName ? null : 'اسم رسمي مفقود',
                        $hasNorm ? null : 'normalized_name مفقود',
                    ],
                ];
            }
        }
    } catch (\Exception $e) {
        return $out;
    }
    return $out;
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
            'name' => 'قواعد الدماغ (Rules)',
            'status' => file_exists($rulesFile) ? 'نشط' : 'غير متصل',
            'ok' => file_exists($rulesFile)
        ];

        // Memory
        $vitals['memory'] = [
            'name' => 'ذاكرة الوكيل (SQLite)',
            'status' => file_exists($this->agentDbPath) ? 'متزامنة' : 'قيد التهيئة',
            'ok' => file_exists($this->agentDbPath)
        ];

        // DB
        $vitals['database'] = [
            'name' => 'قاعدة البيانات الأساسية (MySQL)',
            'status' => $this->db ? 'متصلة' : 'غير متصلة',
            'ok' => $this->db !== null
        ];

        // Hardware Vitals (Real-Time)
        $hwFile = $this->projectPath . '/.bgl_core/logs/hardware_vitals.json';
        if (file_exists($hwFile)) {
            $hw = json_decode(file_get_contents($hwFile), true);
            if ($hw) {
                $vitals['cpu'] = [
                    'name' => 'استخدام المعالج',
                    'status' => $hw['cpu']['usage_percent'] . '%',
                    'ok' => $hw['cpu']['usage_percent'] < 85
                ];
                $vitals['ram'] = [
                    'name' => 'استخدام الذاكرة',
                    'status' => $hw['memory']['used_gb'] . ' / ' . $hw['memory']['total_gb'] . ' GB',
                    'ok' => $hw['memory']['percent'] < 90
                ];
                if (isset($hw['gpu']) && $hw['gpu']) {
                    $vitals['gpu'] = [
                        'name' => 'ضغط المعالج الرسومي',
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
        $stats = ['decisions' => null, 'corrections' => null, 'health_score' => null];
        if (!$this->db) return $stats;

        try {
            $stats['decisions'] = (int)$this->db->query("SELECT COUNT(*) FROM guarantee_decisions")->fetchColumn();
            $stats['corrections'] = (int)$this->db->query("SELECT COUNT(*) FROM guarantee_decisions WHERE override_reason IS NOT NULL")->fetchColumn();
            
            // Derived score (only when DB is reachable).
            $total = max(1, (int)$stats['decisions']);
            $stats['health_score'] = round(100 - (((int)$stats['corrections'] / $total) * 100));
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
                            WHERE i.intent LIKE 'apply_%' OR i.intent LIKE 'proposal.apply|%'
                            ORDER BY o.id DESC"; // Latest first
                    $history = $dec->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($history as $h) {
                        $pid = str_replace('apply_', '', $h['intent']);
                        if (str_starts_with($h['intent'], 'proposal.apply|')) {
                            $parts = explode('|', $h['intent']);
                            $pid = $parts[1] ?? $pid;
                        }
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
                $planPath = null;
                foreach (['solution','expectation','action','evidence'] as $field) {
                    if (!isset($r[$field])) continue;
                    $cand = bgl_detect_plan_path($this->projectPath, (string)$r[$field]);
                    if ($cand) {
                        $planPath = $cand;
                        break;
                    }
                }
                $r['plan_path'] = $planPath;
                $r['plan_exists'] = $planPath ? file_exists($this->projectPath . '/' . $planPath) : false;
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
            $stmt = $lite->query("
                SELECT p.*
                FROM agent_permissions p
                JOIN (
                    SELECT operation, MAX(id) AS max_id
                    FROM agent_permissions
                    WHERE status = 'PENDING'
                    GROUP BY operation
                ) latest ON latest.max_id = p.id
                ORDER BY p.timestamp DESC
            ");
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
                    
                    $pid = '';
                    if (str_starts_with($r['intent'], 'apply_')) {
                        $pid = substr($r['intent'], 6);
                    } elseif (str_starts_with($r['intent'], 'proposal.apply|')) {
                        $parts = explode('|', $r['intent']);
                        $pid = $parts[1] ?? '';
                    }
                    if (!empty($pid)) {
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

    public function getExperienceStats(): array
    {
        if (!file_exists($this->agentDbPath)) {
            return ['total' => 0, 'recent' => 0, 'last_ts' => null];
        }
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $total = (int)$lite->query("SELECT COUNT(*) FROM experiences")->fetchColumn();
            $last = $lite->query("SELECT MAX(created_at) FROM experiences")->fetchColumn();
            $lastTs = $last !== false ? (float)$last : null;
            $since = time() - 3600;
            $stmt = $lite->prepare("SELECT COUNT(*) FROM experiences WHERE created_at >= ?");
            $stmt->execute([$since]);
            $recent = (int)$stmt->fetchColumn();
            return ['total' => $total, 'recent' => $recent, 'last_ts' => $lastTs];
        } catch (\Exception $e) {
            return ['total' => 0, 'recent' => 0, 'last_ts' => null];
        }
    }

    public function getExperiences(int $limit = 8): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $lite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $lite->exec("CREATE TABLE IF NOT EXISTS experience_actions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                exp_hash TEXT UNIQUE,
                action TEXT,
                created_at REAL
            )");
            $actions = [];
            $actRows = $lite->query("SELECT exp_hash, action FROM experience_actions")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($actRows as $r) {
                $actions[$r['exp_hash']] = $r['action'];
            }

            $stmt = $lite->prepare("SELECT scenario, summary, confidence, evidence_count, created_at FROM experiences ORDER BY created_at DESC LIMIT 200");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $seen = [];
            $out = [];
            foreach ($rows as $r) {
                $scenario = (string)($r['scenario'] ?? '');
                $summary = (string)($r['summary'] ?? '');
                $hash = bgl_experience_hash($scenario, $summary);
                if (isset($seen[$hash])) continue;
                $seen[$hash] = true;
                $act = $actions[$hash] ?? null;
                if (in_array($act, ['ignored', 'accepted', 'rejected', 'promoted', 'auto_promoted'], true)) {
                    continue;
                }
                $r['exp_hash'] = $hash;
                $r['action'] = $act;
                $out[] = $r;
                if (count($out) >= $limit) break;
            }
            return $out;
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

    public function getAutonomyEvents(int $limit = 10): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $sql = "SELECT event_type, route, method, status, latency_ms, error, timestamp
                    FROM runtime_events
                    WHERE event_type LIKE 'autonomous_%' OR event_type IN ('novel_probe','novel_probe_skipped')
                    ORDER BY timestamp DESC LIMIT ?";
            $stmt = $lite->prepare($sql);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getEnvSnapshot(string $kind): ?array
    {
        if (!file_exists($this->agentDbPath)) return null;
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $stmt = $lite->prepare("SELECT payload_json, created_at, run_id FROM env_snapshots WHERE kind = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$kind]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $payload = json_decode($row['payload_json'] ?? '{}', true);
            return [
                'created_at' => $row['created_at'] ?? null,
                'run_id' => $row['run_id'] ?? null,
                'payload' => is_array($payload) ? $payload : [],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getLatestDelta(): array
    {
        $snap = $this->getEnvSnapshot('diagnostic_delta');
        if (!$snap || !is_array($snap['payload'] ?? null)) {
            return ['summary' => ['changed_keys' => 0], 'highlights' => []];
        }
        return $snap['payload'];
    }

    public function getRecentRoutes(int $limit = 6, int $days = 7): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $cutoff = time() - ($days * 86400);
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $stmt = $lite->prepare("SELECT uri, http_method, file_path, last_validated FROM routes WHERE last_validated >= ? ORDER BY last_validated DESC LIMIT ?");
            $stmt->execute([$cutoff, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getLogHighlights(int $limit = 6): array
    {
        $out = [];
        $sources = [
            ['name' => 'backend', 'path' => $this->projectPath . '/storage/logs/laravel.log'],
            ['name' => 'backend', 'path' => $this->projectPath . '/storage/logs/app.log'],
            ['name' => 'agent', 'path' => $this->projectPath . '/.bgl_core/logs/ts.log'],
        ];
        $patterns = ['ERROR', 'Exception', 'Traceback', 'CRITICAL', 'FATAL'];
        foreach ($sources as $src) {
            $path = $src['path'];
            if (!file_exists($path)) continue;
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) continue;
            $lines = array_slice($lines, -200);
            foreach (array_reverse($lines) as $line) {
                $match = false;
                foreach ($patterns as $p) {
                    if (stripos($line, $p) !== false) { $match = true; break; }
                }
                if (!$match) continue;
                $out[] = [
                    'source' => $src['name'],
                    'message' => mb_substr(trim($line), 0, 220),
                ];
                if (count($out) >= $limit) break 2;
            }
        }
        return $out;
    }

    public function getAutonomyGoals(int $limit = 10): array
    {
        if (!file_exists($this->agentDbPath)) return [];
        try {
            $lite = new PDO("sqlite:" . $this->agentDbPath);
            $lite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $lite->exec("CREATE TABLE IF NOT EXISTS autonomy_goals (id INTEGER PRIMARY KEY AUTOINCREMENT, goal TEXT, payload TEXT, source TEXT, created_at REAL, expires_at REAL)");
            $stmt = $lite->prepare("SELECT goal, payload, source, created_at, expires_at FROM autonomy_goals ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $payload = [];
                try {
                    $payload = json_decode($r['payload'] ?? '{}', true) ?: [];
                } catch (\Exception $e) {}
                $out[] = [
                    'goal' => $r['goal'] ?? '',
                    'source' => $r['source'] ?? '',
                    'payload' => $payload,
                    'created_at' => $r['created_at'] ?? null,
                    'expires_at' => $r['expires_at'] ?? null,
                ];
            }
            return $out;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getLLMStatus(): array
    {
        // Prefer live status from Ollama /api/ps (fast, short timeout).
        $candidates = [];
        $envBase = getenv('LLM_BASE_URL');
        if ($envBase) $candidates[] = $envBase;

        // Read config + flags to discover configured base URL
        $cfgPath = $this->projectPath . '/.bgl_core/config.yml';
        $flagsPath = $this->projectPath . '/storage/agent_flags.json';
        $cfg = bgl_yaml_parse($cfgPath);
        $flags = bgl_read_json($flagsPath);
        $effective = bgl_deep_merge(is_array($cfg) ? $cfg : [], is_array($flags) ? $flags : []);
        $cfgBase = $effective['llm']['base_url'] ?? ($effective['llm_base_url'] ?? null);
        if ($cfgBase) $candidates[] = $cfgBase;

        // Common defaults
        $candidates[] = 'http://127.0.0.1:11434';
        $candidates[] = 'http://localhost:11434';

        $seen = [];
        foreach ($candidates as $c) {
            $base = bgl_llm_base_api((string)$c);
            if ($base === '' || isset($seen[$base])) continue;
            $seen[$base] = true;
            $ps = bgl_http_get_json($base . '/api/ps', 1.5);
            if (is_array($ps) && array_key_exists('models', $ps)) {
                $models = is_array($ps['models']) ? $ps['models'] : [];
                $state = !empty($models) ? 'HOT' : 'COLD';
                $modelName = '';
                if (!empty($models) && is_array($models[0]) && isset($models[0]['name'])) {
                    $modelName = (string)$models[0]['name'];
                }
                $displayBase = $base . '/v1/chat/completions';
                return [
                    'timestamp' => time(),
                    'state' => $state,
                    'base_url' => $displayBase,
                    'model' => $modelName,
                    'source' => 'live',
                ];
            }
        }

        // Fallback: read cached status file
        $statusPath = $this->projectPath . '/.bgl_core/logs/llm_status.json';
        if (!file_exists($statusPath)) {
            return ['state' => 'UNKNOWN'];
        }
        $json = json_decode(file_get_contents($statusPath), true);
        return is_array($json) ? $json : ['state' => 'UNKNOWN'];
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
$patchPlans = bgl_list_patch_plans($projectRoot);
$permissions = $dash->getPermissions();
$activities = $dash->getRecentActivity();
$experienceStats = $dash->getExperienceStats();
$experiences = $dash->getExperiences();
$events = $dash->getRuntimeEvents();
$autonomyEvents = $dash->getAutonomyEvents();
$deltaSnapshot = $dash->getLatestDelta();
$recentRoutes = $dash->getRecentRoutes();
$logHighlights = $dash->getLogHighlights();
$autonomyGoals = $dash->getAutonomyGoals();
$browserStatus = $dash->getBrowserStatus();
$envSnapshot = $dash->getEnvSnapshot('diagnostic');
$envDelta = $dash->getEnvSnapshot('diagnostic_delta');
$llmStatus = $dash->getLLMStatus();
$llmWarmAttempted = false;
$permissionIssues = $dash->getPermissionIssues();
$worstRoutes = $dash->getWorstRoutes();
$recentIntents = $dash->getRecentIntents();
$recentDecisions = $dash->getRecentDecisions();
$recentOutcomes = [];
$decisionDbPath = $projectRoot . '/.bgl_core/brain/decision.db';
if (file_exists($decisionDbPath)) {
    try {
        $dec = new PDO("sqlite:" . $decisionDbPath);
        $sql = "SELECT o.result, o.notes, o.timestamp, i.intent, d.decision, d.risk_level
                FROM outcomes o
                JOIN decisions d ON o.decision_id = d.id
                JOIN intents i ON d.intent_id = i.id
                ORDER BY o.id DESC LIMIT 10";
        $recentOutcomes = $dec->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        $recentOutcomes = [];
    }
}
$pendingPlaybooks = glob($projectRoot . '/.bgl_core/brain/playbooks_proposed/*.md');
$pendingPlaybooks = $pendingPlaybooks ? count($pendingPlaybooks) : 0;
$externalChecks = [];
$perfMetrics = [];
$callgraphMeta = [];
$configPath = $projectRoot . '/.bgl_core/config.yml';
$flagsPath = dirname(__DIR__) . '/storage/agent_flags.json';
$executionMode = 'sandbox';
$agentMode = 'assisted';
$decisionMode = 'assisted';
$domainMap = null;
$domainMapRaw = null;
$flows = [];
$kpiCurrent = [];
$kpiScopes = [];
$directAttempts = 0;
$successRate = null;
$proposedPatterns = [];
$cfg = [];
$dashboardRuns = bgl_read_json($projectRoot . '/storage/logs/dashboard_runs.json');
$dashboardTestLogs = [
    'run_pytest_smoke' => bgl_tail_file($projectRoot . '/storage/logs/dashboard_run_pytest_smoke.log'),
    'run_pytest_full' => bgl_tail_file($projectRoot . '/storage/logs/dashboard_run_pytest_full.log'),
    'run_pytest_custom' => bgl_tail_file($projectRoot . '/storage/logs/dashboard_run_pytest_custom.log'),
    'run_phpunit' => bgl_tail_file($projectRoot . '/storage/logs/dashboard_run_phpunit.log'),
    'run_ci' => bgl_tail_file($projectRoot . '/storage/logs/dashboard_run_ci.log'),
];
$proposalChangeMap = [];
$proposalChangePath = $projectRoot . '/.bgl_core/logs/proposal_changes.jsonl';
if (file_exists($proposalChangePath)) {
    $lines = file($proposalChangePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        $lines = array_slice($lines, -200);
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) continue;
            $pid = $entry['id'] ?? null;
            if ($pid === null || $pid === '') continue;
            $proposalChangeMap[(string)$pid] = $entry;
        }
    }
}
$pytestFiles = [];
$testsRoot = $projectRoot . '/tests';
if (is_dir($testsRoot)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile()) continue;
        $name = $file->getFilename();
        if (!preg_match('/^test_.*\\.py$/i', $name)) continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
        $pytestFiles[] = $rel;
        if (count($pytestFiles) >= 120) break;
    }
    sort($pytestFiles);
}
if (file_exists($configPath)) {
    $parsed = bgl_yaml_parse($configPath);
    $cfg = is_array($parsed) ? $parsed : [];
}
$agentFlags = bgl_read_json($flagsPath);
$effectiveCfg = bgl_deep_merge($cfg, $agentFlags);
$effectiveSources = [];
try {
    $flatCfg = bgl_flatten($cfg);
    $flatFlags = bgl_flatten($agentFlags);
    foreach (array_keys($flatCfg + $flatFlags) as $k) {
        if (array_key_exists($k, $flatFlags)) {
            $effectiveSources[$k] = 'flags';
        } elseif (array_key_exists($k, $flatCfg)) {
            $effectiveSources[$k] = 'config';
        }
    }
} catch (\Exception $e) {}
$llmCfg = is_array($effectiveCfg['llm'] ?? null) ? $effectiveCfg['llm'] : [];
$llmCfgModel = (string)($llmCfg['model'] ?? ($effectiveCfg['llm_model'] ?? 'llama3.1:latest'));
$llmCfgBase = (string)($llmCfg['base_url'] ?? ($effectiveCfg['llm_base_url'] ?? ''));

$toolServerPort = (int)($effectiveCfg['tool_server_port'] ?? 8891);
$toolServerUrl = "http://127.0.0.1:{$toolServerPort}/tool";
$toolServerOnline = bgl_service_ping($toolServerUrl, 0.5);
$toolServerAutoStarted = false;
// Avoid repeated auto-starts during live polling.
$isLivePoll = (isset($_GET['live']) && $_GET['live'] === '1' && bgl_is_ajax());
$cooldownPath = $projectRoot . "/storage/tool_server_autostart.lock";
if (!$toolServerOnline && !$isLivePoll) {
    $toolServerOnline = bgl_ensure_tool_server(
        $pythonBin,
        $projectRoot . "/scripts/tool_watchdog.py",
        $toolServerPort,
        $cooldownPath,
        15
    );
    $toolServerAutoStarted = $toolServerOnline;
}
$copilotWidgetPath = __DIR__ . '/app/copilot/dist/copilot-widget.js';
$copilotWidgetPresent = file_exists($copilotWidgetPath);

if (($llmStatus['state'] ?? '') === 'COLD' && !empty($llmStatus['base_url'])) {
    $modelHint = $llmCfgModel !== '' ? $llmCfgModel : (string)($llmStatus['model'] ?? '');
    $llmWarmAttempted = bgl_ensure_llm_hot((string)$llmStatus['base_url'], $projectRoot . "/storage/llm_autowarm.lock", 20, $modelHint);
    if ($llmWarmAttempted) {
        usleep(350000);
        $llmStatus = $dash->getLLMStatus();
    }
}

$dataQualityDetails = bgl_data_quality_details($projectRoot . '/storage/database/app.sqlite', 20);

function bgl_build_live_payload(
    array $latestReport,
    array $stats,
    array $vitals,
    array $domainMap,
    array $kpiCurrent,
    int $pendingPlaybooks,
    array $proposals,
    array $permissionIssues,
    array $experienceStats,
    array $deltaSnapshot,
    array $recentRoutes,
    array $logHighlights,
    array $autonomyGoals,
    string $systemStatusText,
    string $systemStatusTone,
    int $toolServerPort,
    string $toolServerUrl,
    string $pythonBin,
    string $projectRoot,
    string $llmCfgModel
): array {
    $routesDbPath = $projectRoot . '/.bgl_core/brain/knowledge.db';
    $routeHealth = bgl_route_health_from_db($routesDbPath, 7, 24);
    $exploreStats = bgl_exploration_failure_stats($routesDbPath, 180);
    $cooldownPath = $projectRoot . "/storage/tool_server_autostart.lock";
    $liveToolServerOnline = bgl_ensure_tool_server(
        $pythonBin,
        $projectRoot . "/scripts/tool_watchdog.py",
        $toolServerPort,
        $cooldownPath,
        15
    );
    $dash = new PremiumDashboard();
    $liveLlmStatus = $dash->getLLMStatus();
    if (!empty($liveLlmStatus['base_url'])) {
        $modelHint = $llmCfgModel !== '' ? $llmCfgModel : (string)($liveLlmStatus['model'] ?? '');
        if (($liveLlmStatus['state'] ?? '') === 'COLD') {
            if (bgl_ensure_llm_hot((string)$liveLlmStatus['base_url'], $projectRoot . "/storage/llm_autowarm.lock", 20, $modelHint)) {
                usleep(200000);
                $liveLlmStatus = $dash->getLLMStatus();
            }
        } else {
            // Keep model hot while dashboard is open.
            bgl_llm_keepalive((string)$liveLlmStatus['base_url'], $projectRoot . "/storage/llm_keepalive.lock", 30, $modelHint);
        }
    }
    $healthVal = null;
    if (isset($stats['health_score']) && $stats['health_score'] !== null) {
        $healthVal = (float)$stats['health_score'];
    }
    $healthDash = $healthVal === null ? 0 : max(0, min(100, (float)$healthVal));
    $healthDisplay = $healthVal === null ? 'غير متوفر' : (round($healthVal, 1) . '%');

    $ts = $latestReport['timestamp'] ?? null;
    $snapshotTs = $ts ? date('Y-m-d H:i', (int)$ts) : 'غير متوفر';
    $runtimeCount = $latestReport['runtime_events_meta']['count'] ?? null;
    $routeScanLimit = $latestReport['route_scan_limit'] ?? null;
    $readinessOk = $latestReport['readiness']['ok'] ?? null;
    $readinessText = $readinessOk === null ? 'غير متوفر' : ($readinessOk ? 'OK' : 'WARN');
    $expTotal = (int)($experienceStats['total'] ?? 0);
    $expRecent = (int)($experienceStats['recent'] ?? 0);
    $expLast = $experienceStats['last_ts'] ?? null;
    $expLastText = $expLast ? date('H:i:s', (int)$expLast) : 'غير متوفر';
    $deltaPayload = is_array($deltaSnapshot) ? $deltaSnapshot : ['summary' => ['changed_keys' => 0], 'highlights' => []];
    $routePayload = is_array($recentRoutes) ? $recentRoutes : [];
    $logPayload = is_array($logHighlights) ? $logHighlights : [];
    $goalPayload = is_array($autonomyGoals) ? $autonomyGoals : [];

    $vitalPayload = [];
    foreach ($vitals as $key => $v) {
        $vitalPayload[$key] = [
            'status' => $v['status'] ?? '',
            'ok' => (bool)($v['ok'] ?? false),
        ];
    }

    $kpiDisplay = [];
    if (!empty($domainMap['operational_kpis']) && is_array($domainMap['operational_kpis'])) {
        foreach ($domainMap['operational_kpis'] as $kpi) {
            $name = (string)($kpi['name'] ?? '');
            if ($name === '') continue;
            $slug = preg_replace('/[^a-zA-Z0-9_]+/', '_', $name);
            $current = $kpiCurrent[$name] ?? null;
            $unit = str_ends_with($name, '_rate') ? '%' : (str_ends_with($name, '_ms') ? ' ms' : '');
            $kpiDisplay[$slug] = $current !== null ? ($current . $unit) : 'غير متوفر';
        }
    }

    return [
        'ok' => true,
        'system_status_text' => $systemStatusText ?? 'غير متوفر',
        'system_status_tone' => $systemStatusTone ?? 'unknown',
        'system_state' => empty($routeHealth['failing_routes']) ? 'PASS' : 'WARN',
        'pending_playbooks' => (int)$pendingPlaybooks,
        'proposal_count' => is_array($proposals) ? count($proposals) : 0,
        'permission_issues_count' => empty($permissionIssues) ? 0 : count($permissionIssues),
        'experience_total' => $expTotal,
        'experience_recent' => $expRecent,
        'experience_last' => $expLastText,
        'snapshot_delta' => $deltaPayload,
        'recent_routes' => $routePayload,
        'log_highlights' => $logPayload,
        'autonomy_goals' => $goalPayload,
        'tool_server_online' => (bool)$liveToolServerOnline,
        'tool_server_port' => (int)$toolServerPort,
        'llm_state' => strtoupper((string)($liveLlmStatus['state'] ?? 'UNKNOWN')),
        'llm_model' => (string)($liveLlmStatus['model'] ?? ''),
        'llm_base_url' => (string)($liveLlmStatus['base_url'] ?? ''),
        'health_score_value' => $healthVal,
        'health_score_display' => $healthDisplay,
        'health_score_dash' => $healthDash,
        'snapshot_timestamp' => $snapshotTs,
        'snapshot_runtime_events' => $runtimeCount !== null ? (int)$runtimeCount : 'غير متوفر',
        'snapshot_route_scan_limit' => $routeScanLimit !== null ? (int)$routeScanLimit : 'غير متوفر',
        'snapshot_readiness' => $readinessText,
        'vitals' => $vitalPayload,
        'kpi_current' => $kpiDisplay,
        'exploration_stats' => $exploreStats,
    ];
}

if (isset($effectiveCfg['execution_mode'])) {
    $executionMode = strtolower((string)$effectiveCfg['execution_mode']);
}
if (isset($effectiveCfg['agent_mode'])) {
    $agentMode = strtolower((string)$effectiveCfg['agent_mode']);
} elseif (isset($effectiveCfg['decision']['mode'])) {
    $agentMode = strtolower((string)$effectiveCfg['decision']['mode']);
}
if (isset($effectiveCfg['decision']['mode'])) {
    $decisionMode = strtolower((string)$effectiveCfg['decision']['mode']);
}
$reportJson = $projectRoot . '/.bgl_core/logs/latest_report.json';
$latestReport = [];
$volition = [];
$autonomousPolicy = [];
// Canonical routes source: knowledge.db (no report-as-input for routes).
$routesDbPath = $projectRoot . '/.bgl_core/brain/knowledge.db';
$routeHealth = bgl_route_health_from_db($routesDbPath, 7, 24);
$exploreStats = bgl_exploration_failure_stats($routesDbPath, 180);
if (file_exists($reportJson)) {
    $jr = json_decode(file_get_contents($reportJson), true);
    if (is_array($jr)) {
        $latestReport = $jr;
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
        if (isset($jr['volition']) && is_array($jr['volition'])) {
            $volition = $jr['volition'];
        }
        if (isset($jr['autonomous_policy']) && is_array($jr['autonomous_policy'])) {
            $autonomousPolicy = $jr['autonomous_policy'];
        }
    }
}
// Ensure callgraph uses canonical routes count from DB.
if (empty($callgraphMeta) || !is_array($callgraphMeta)) {
    $callgraphMeta = [];
}
if (isset($routeHealth['routes_count'])) {
    $callgraphMeta['total_routes'] = (int)$routeHealth['routes_count'];
}

// Use DB-derived health score as canonical for routes.
if ($routeHealth['health_score'] !== null) {
    $stats['health_score'] = $routeHealth['health_score'];
}

// Header status (avoid static "active" labels)
$systemStatusText = 'غير متوفر';
$systemStatusTone = 'unknown'; // ok | warn | unknown
{
    $failingRoutes = count($routeHealth['failing_routes'] ?? []);
    $healthScore = $routeHealth['health_score'];
    if ($healthScore !== null) {
        if ($healthScore >= 90 && $failingRoutes === 0) {
            $systemStatusText = 'مستقر';
            $systemStatusTone = 'ok';
        } else {
            $systemStatusText = 'يحتاج مراجعة';
            $systemStatusTone = 'warn';
        }
    } else {
        if ($failingRoutes > 0) {
            $systemStatusText = 'يحتاج مراجعة';
            $systemStatusTone = 'warn';
        } else {
            $systemStatusText = 'غير مؤكد';
            $systemStatusTone = 'unknown';
        }
    }
}
// count direct attempts from decision outcomes if available
$decisionDbPath = $projectRoot . '/.bgl_core/brain/decision.db';
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
$proposedPath = $projectRoot . '/.bgl_core/brain/proposed_patterns.json';
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

        // Dynamic KPI scopes from runtime_events (fresh, not from domain_map.yml)
        $kpiScopes['import_success_rate'] = $lite->query(
            "SELECT DISTINCT event_type FROM runtime_events WHERE event_type IN ('import_suppliers','import_banks')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $scopeRoutes = $lite->prepare("SELECT DISTINCT route FROM runtime_events WHERE route IN ($writePlaceholders) AND route IS NOT NULL");
        $scopeRoutes->execute($writeRoutes);
        $presentRoutes = $scopeRoutes->fetchAll(PDO::FETCH_COLUMN);
        $kpiScopes['api_error_rate'] = $presentRoutes;
        $kpiScopes['validation_failure_rate'] = $presentRoutes;
        $kpiScopes['contract_latency_ms'] = $presentRoutes;

        // Data Quality Score (derived from core tables: banks + suppliers)
        $qualityScore = bgl_calc_data_quality_score($projectRoot . '/storage/database/app.sqlite');
        if ($qualityScore !== null) {
            $kpiCurrent['data_quality_score'] = $qualityScore;
        }
        $kpiScopes['data_quality_score'] = ['banks', 'suppliers'];
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

// Lightweight live snapshot for AJAX updates (no full page reload)
if (isset($_GET['live']) && $_GET['live'] === '1' && bgl_is_ajax()) {
    $payload = bgl_build_live_payload(
        $latestReport,
        $stats,
        $vitals,
        $domainMap ?? [],
        $kpiCurrent,
        (int)$pendingPlaybooks,
        $proposals ?? [],
        $permissionIssues ?? [],
        $experienceStats ?? [],
        $deltaSnapshot ?? [],
        $recentRoutes ?? [],
        $logHighlights ?? [],
        $autonomyGoals ?? [],
        $systemStatusText ?? 'غير متوفر',
        $systemStatusTone ?? 'unknown',
        (int)$toolServerPort,
        $toolServerUrl,
        $pythonBin,
        $projectRoot,
        (string)$llmCfgModel
    );
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'text/event-stream') !== false) {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', 0);
    @ob_end_flush();
    @ob_implicit_flush(true);

    $start = time();
    $maxSeconds = 90;
    $interval = 2;
    while (true) {
        $payload = bgl_build_live_payload(
            $latestReport,
            $stats,
            $vitals,
            $domainMap ?? [],
            $kpiCurrent,
            (int)$pendingPlaybooks,
            $proposals ?? [],
            $permissionIssues ?? [],
            $experienceStats ?? [],
            $deltaSnapshot ?? [],
            $recentRoutes ?? [],
            $logHighlights ?? [],
            $autonomyGoals ?? [],
            $systemStatusText ?? 'غير متوفر',
            $systemStatusTone ?? 'unknown',
            (int)$toolServerPort,
            $toolServerUrl,
            $pythonBin,
            $projectRoot,
            (string)$llmCfgModel
        );
        echo "event: live\n";
        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();
        if (connection_aborted()) break;
        if ((time() - $start) >= $maxSeconds) break;
        sleep($interval);
    }
    exit;
}
?>
