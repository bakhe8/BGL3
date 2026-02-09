<?php
// c:\Users\Bakheet\Documents\Projects\BGL3\agent.php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Support\Database;
use Symfony\Component\Yaml\Yaml;

// إعداد بيئة بسيطة للوكيل
class AgentConsole
{
    private $db;
    private $projectPath;

    public function __construct()
    {
        $this->projectPath = __DIR__;
        // محاولة الاتصال بقاعدة البيانات (إذا كانت مهيأة)
        try {
            $this->db = Database::connect();
        } catch (\Exception $e) {
            $this->db = null;
        }
    }

    public function handle(array $argv)
    {
        $command = $argv[1] ?? 'help';

        echo "\n🤖 \033[1;36mBGL3 ARTIFICIAL AGENT INTERFACE\033[0m\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        switch ($command) {
            case 'status':
                $this->showStatus();
                break;
            case 'stats':
                $this->showStats();
                break;
            case 'rules':
                $this->showRules();
                break;
            case 'chat':
                $this->handleChat($argv);
                break;
            case 'explain':
                $this->showReasoning();
                break;
            case 'help':
            default:
                $this->showHelp();
                break;
        }
        echo "\n";
    }

    private function showStatus()
    {
        echo "Checking system vitals...\n\n";

        // 1. Check Rules
        $rulesPath = $this->projectPath . '/.bgl_core/brain/domain_rules.yml';
        if (file_exists($rulesPath)) {
            echo "✅ \033[32mBrain (Rules):\033[0m   Active\n";
        } else {
            echo "❌ \033[31mBrain (Rules):\033[0m   MISSING\n";
        }

        // 2. Check Database
        if ($this->db) {
            echo "✅ \033[32mMemory (DB):\033[0m     Connected\n";
        } else {
            echo "⚠️ \033[33mMemory (DB):\033[0m     Disconnected (Check config)\n";
        }

        // 3. Check Logs
        $logPath = $this->projectPath . '/storage/logs';
        if (is_dir($logPath)) {
            echo "✅ \033[32mAudit Logs:\033[0m      Ready\n";
        } else {
            echo "⚠️ \033[33mAudit Logs:\033[0m      Directory missing\n";
        }
    }

    private function showStats()
    {
        if (!$this->db) {
            echo "❌ Cannot retrieve stats: Database not connected.\n";
            return;
        }

        echo "Retrieving performance metrics...\n\n";

        // إحصائيات القرارات (مثال)
        try {
            $stmt = $this->db->query("SELECT status, COUNT(*) as count FROM guarantee_decisions GROUP BY status");
            $stats = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            echo "📊 \033[1;33mDecision Statistics:\033[0m\n";
            echo "   • Released: " . ($stats['released'] ?? 0) . "\n";
            echo "   • Blocked:  " . ($stats['blocked'] ?? 0) . "\n";
            echo "   • Manual:   " . ($stats['manual_review'] ?? 0) . "\n";

            // إحصائيات التعلم
            $stmt = $this->db->query("SELECT COUNT(*) FROM guarantee_decisions WHERE override_reason IS NOT NULL");
            $overrides = $stmt->fetchColumn();
            echo "\n🧠 \033[1;33mLearning Metrics:\033[0m\n";
            echo "   • Human Corrections: $overrides\n";

        } catch (\Exception $e) {
            echo "⚠️ Error querying stats: " . $e->getMessage() . "\n";
        }
    }

    private function showRules()
    {
        $rulesPath = $this->projectPath . '/.bgl_core/brain/domain_rules.yml';
        if (!file_exists($rulesPath)) {
            echo "❌ Rules file not found.\n";
            return;
        }

        $yaml = Yaml::parseFile($rulesPath);
        echo "📜 \033[1;33mActive Architectural Laws:\033[0m\n";

        foreach ($yaml['rules'] as $rule) {
            $color = $rule['action'] === 'BLOCK' ? "\033[31m" : "\033[33m";
            echo "   {$color}[{$rule['id']}] {$rule['name']}\033[0m\n";
            echo "   Action: {$rule['action']} | Type: {$rule['description']}\n\n";
        }
    }

    private function showHelp()
    {
        echo "Available Commands:\n";
        echo "  \033[32mphp agent.php status\033[0m   Check agent health and connections.\n";
        echo "  \033[32mphp agent.php stats\033[0m    Show performance statistics from DB.\n";
        echo "  \033[32mphp agent.php rules\033[0m    List active architectural rules.\n";
        echo "  \033[32mphp agent.php chat \"...\"\033[0m Direct conversation with the Smart Brain.\n";
        echo "  \033[32mphp agent.php explain\033[0m    Explain the logic behind recent decisions.\n";
    }

    private function handleChat(array $argv)
    {
        $query = $argv[2] ?? null;
        if (!$query) {
            echo "❌ Please provide a query: php agent.php chat \"Your message\"\n";
            return;
        }

        echo "🧠 Consulting Brain...\n";
        $payload = json_encode(['messages' => [['role' => 'user', 'content' => $query]]]);
        
        $ch = curl_init('http://127.0.0.1:8891/chat');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        echo "\n" . ($data['content'] ?? "⚠️ No response from brain core.") . "\n";
    }

    private function showReasoning()
    {
        echo "🧐 Analyzing recent Chain of Thought...\n\n";
        // Logic to pull from knowledge.db via python or direct sqlite
        system("python .bgl_core/brain/inference.py");
    }
}

// تشغيل الواجهة
$argv = $argv ?? ($_SERVER['argv'] ?? []);
if (!is_array($argv)) {
    $argv = [];
}
if (PHP_SAPI !== 'cli') {
    // Allow simple web usage: /agent.php?cmd=status or /agent.php?cmd=chat&q=...
    $cmd = isset($_GET['cmd']) ? (string)$_GET['cmd'] : null;
    if ($cmd) {
        $argv = array_values(array_filter([
            'agent.php',
            $cmd,
            isset($_GET['q']) ? (string)$_GET['q'] : null,
        ], static fn($v) => $v !== null && $v !== ''));
    }
}
(new AgentConsole())->handle($argv);
