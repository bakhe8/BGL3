<?php
/**
 * Comprehensive Extension Test for BGL3 Project
 * Tests all PHP extensions, Composer dependencies, and system requirements
 */

class ComprehensiveExtensionTest
{
    private $results = [];
    private $passCount = 0;
    private $failCount = 0;

    public function __construct()
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════╗\n";
        echo "║     اختبار شامل للامتدادات - BGL3 System Test          ║\n";
        echo "╚═══════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    /**
     * Test PHP version requirement
     */
    public function testPhpVersion(): void
    {
        $this->printHeader("PHP Version Check");
        
        $currentVersion = PHP_VERSION;
        $requiredVersion = '8.0.0';
        
        $pass = version_compare($currentVersion, $requiredVersion, '>=');
        
        echo "Current Version: " . $currentVersion . "\n";
        echo "Required Version: >= " . $requiredVersion . "\n";
        
        $this->recordResult('PHP Version', $pass, $currentVersion);
    }

    /**
     * Test required PHP extensions
     */
    public function testPhpExtensions(): void
    {
        $this->printHeader("PHP Extensions Check");
        
        $requiredExtensions = [
            'pdo' => 'PDO Database Support',
            'pdo_sqlite' => 'SQLite PDO Driver',
            'sqlite3' => 'SQLite3 Extension',
            'fileinfo' => 'File Information',
            'mbstring' => 'Multibyte String',
            'json' => 'JSON Support',
            'zip' => 'ZIP Archive',
            'openssl' => 'OpenSSL',
            'curl' => 'cURL (optional)',
        ];

        foreach ($requiredExtensions as $ext => $description) {
            $loaded = extension_loaded($ext);
            $status = $loaded ? '✓ Loaded' : '✗ Missing';
            
            echo sprintf("%-20s %-30s %s\n", $ext, $description, $status);
            
            // Only mark as fail if it's not optional
            if ($ext !== 'curl') {
                $this->recordResult("Extension: $ext", $loaded, $description);
            } else {
                if ($loaded) {
                    echo "  (Optional - Available)\n";
                } else {
                    echo "  (Optional - Not critical)\n";
                }
            }
        }
    }

    /**
     * Test SQLite functionality
     */
    public function testSqlite(): void
    {
        $this->printHeader("SQLite Database Test");
        
        try {
            // Create temporary test database
            $testDb = sys_get_temp_dir() . '/test_' . time() . '.db';
            $pdo = new PDO("sqlite:$testDb");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create test table
            $pdo->exec("CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)");
            
            // Insert test data
            $stmt = $pdo->prepare("INSERT INTO test (name) VALUES (?)");
            $stmt->execute(['Test Entry']);
            
            // Query test data
            $result = $pdo->query("SELECT * FROM test")->fetch();
            
            $pass = $result['name'] === 'Test Entry';
            
            // Cleanup
            unlink($testDb);
            
            echo "Database Creation: ✓\n";
            echo "Table Creation: ✓\n";
            echo "Data Insert: ✓\n";
            echo "Data Query: ✓\n";
            
            $this->recordResult('SQLite Operations', $pass, 'All operations successful');
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            $this->recordResult('SQLite Operations', false, $e->getMessage());
        }
    }

    /**
     * Test file operations
     */
    public function testFileOperations(): void
    {
        $this->printHeader("File Operations Test");
        
        try {
            // Test file creation
            $testFile = sys_get_temp_dir() . '/test_' . time() . '.txt';
            $written = file_put_contents($testFile, "Test content");
            
            // Test file reading
            $content = file_get_contents($testFile);
            
            $pass = $written > 0 && $content === "Test content";
            
            echo "File Write: ✓\n";
            echo "File Read: ✓\n";
            
            // Test fileinfo if available
            if (extension_loaded('fileinfo')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $testFile);
                finfo_close($finfo);
                echo "MIME Detection: ✓ ($mimeType)\n";
            } else {
                echo "MIME Detection: ⚠ (fileinfo extension not loaded)\n";
            }
            
            // Cleanup
            unlink($testFile);
            
            $this->recordResult('File Operations', $pass, 'All file operations successful');
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            $this->recordResult('File Operations', false, $e->getMessage());
        }

    }

    /**
     * Test Composer autoloader
     */
    public function testComposerAutoloader(): void
    {
        $this->printHeader("Composer Autoloader Test");
        
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            
            echo "Autoloader File: ✓ Found\n";
            echo "Path: $autoloadPath\n";
            
            // Test if PhpSpreadsheet is available
            $phpSpreadsheetExists = class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet');
            
            if ($phpSpreadsheetExists) {
                echo "PhpSpreadsheet: ✓ Loaded\n";
                $this->recordResult('Composer Dependencies', true, 'PhpSpreadsheet available');
            } else {
                echo "PhpSpreadsheet: ✗ Not found\n";
                $this->recordResult('Composer Dependencies', false, 'PhpSpreadsheet not found');
            }
        } else {
            echo "Autoloader File: ✗ Not found\n";
            echo "Run: composer install\n";
            $this->recordResult('Composer Dependencies', false, 'Vendor directory missing');
        }
    }

    /**
     * Test memory and performance settings
     */
    public function testSystemSettings(): void
    {
        $this->printHeader("System Settings Check");
        
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = ini_get('max_execution_time');
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $postMaxSize = ini_get('post_max_size');
        
        echo sprintf("%-25s %s\n", "Memory Limit:", $memoryLimit);
        echo sprintf("%-25s %s\n", "Max Execution Time:", $maxExecutionTime . " seconds");
        echo sprintf("%-25s %s\n", "Upload Max Filesize:", $uploadMaxFilesize);
        echo sprintf("%-25s %s\n", "Post Max Size:", $postMaxSize);
        
        // Check if settings are reasonable
        $memoryOk = $this->parseSize($memoryLimit) >= $this->parseSize('128M');
        $uploadOk = $this->parseSize($uploadMaxFilesize) >= $this->parseSize('2M');
        
        $this->recordResult('System Settings', $memoryOk && $uploadOk, 'Settings configured');
    }

    /**
     * Test project database
     */
    public function testProjectDatabase(): void
    {
        $this->printHeader("Project Database Test");
        
        $dbPath = __DIR__ . '/../storage/database.db';
        
        if (file_exists($dbPath)) {
            echo "Database File: ✓ Found\n";
            echo "Path: $dbPath\n";
            echo "Size: " . $this->formatBytes(filesize($dbPath)) . "\n";
            
            try {
                $pdo = new PDO("sqlite:$dbPath");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Test connection
                $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
                
                echo "Connection: ✓ Successful\n";
                echo "Tables Found: " . count($result) . "\n";
                
                foreach ($result as $table) {
                    echo "  - " . $table['name'] . "\n";
                }
                
                $this->recordResult('Project Database', true, count($result) . ' tables found');
                
            } catch (Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
                $this->recordResult('Project Database', false, $e->getMessage());
            }
        } else {
            echo "Database File: ⚠ Not found (will be created on first run)\n";
            echo "Expected Path: $dbPath\n";
            $this->recordResult('Project Database', true, 'Will be auto-created');
        }
    }

    /**
     * Print summary
     */
    public function printSummary(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════╗\n";
        echo "║                    Test Summary - ملخص الاختبار          ║\n";
        echo "╚═══════════════════════════════════════════════════════════╝\n";
        echo "\n";
        
        echo "Total Tests: " . ($this->passCount + $this->failCount) . "\n";
        echo "Passed (نجح): " . $this->passCount . " ✓\n";
        echo "Failed (فشل): " . $this->failCount . " ✗\n";
        
        if ($this->failCount === 0) {
            echo "\n";
            echo "🎉 جميع الاختبارات اجتازت بنجاح! All tests passed successfully!\n";
            echo "✓ النظام جاهز للعمل - System is ready to use\n";
        } else {
            echo "\n";
            echo "⚠ بعض الاختبارات فشلت - Some tests failed\n";
            echo "يرجى مراجعة النتائج أعلاه - Please review results above\n";
        }
        
        echo "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        // Detailed results
        if (!empty($this->results)) {
            echo "\nDetailed Results:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            
            foreach ($this->results as $result) {
                $icon = $result['pass'] ? '✓' : '✗';
                $status = $result['pass'] ? 'PASS' : 'FAIL';
                
                echo sprintf("%s %-30s [%s] %s\n", 
                    $icon, 
                    $result['test'], 
                    $status, 
                    $result['details']
                );
            }
        }
    }

    /**
     * Helper: Print section header
     */
    private function printHeader(string $title): void
    {
        echo "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  " . $title . "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    }

    /**
     * Helper: Record test result
     */
    private function recordResult(string $test, bool $pass, string $details = ''): void
    {
        $this->results[] = [
            'test' => $test,
            'pass' => $pass,
            'details' => $details
        ];
        
        if ($pass) {
            $this->passCount++;
        } else {
            $this->failCount++;
        }
    }

    /**
     * Helper: Parse size string to bytes
     */
    private function parseSize(string $size): int
    {
        $size = trim($size);
        
        // Handle empty string safely
        if ($size === '') {
            return 0;
        }
        
        // Explicitly handle unlimited memory convention
        if ($size === '-1') {
            return -1;
        }
        
        $last = strtolower(substr($size, -1));
        
        // If the last character is a recognized unit, parse accordingly
        if (in_array($last, ['g', 'm', 'k'], true)) {
            $numeric = (float)substr($size, 0, -1);
            
            switch ($last) {
                case 'g':
                    $numeric *= 1024; // intentional fall-through to 'm' and 'k'
                case 'm':
                    $numeric *= 1024; // intentional fall-through to 'k'
                case 'k':
                    $numeric *= 1024;
            }
            
            return (int)$numeric;
        }
        
        // No recognized suffix: treat as bytes
        return (int)$size;
    }

    /**
     * Helper: Format bytes to human readable
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Run all tests
     */
    public function runAllTests(): void
    {
        $this->testPhpVersion();
        $this->testPhpExtensions();
        $this->testSqlite();
        $this->testFileOperations();
        $this->testComposerAutoloader();
        $this->testSystemSettings();
        $this->testProjectDatabase();
        $this->printSummary();
    }
}

// Run the tests
$tester = new ComprehensiveExtensionTest();
$tester->runAllTests();
