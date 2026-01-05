<?php
/**
 * Database Reset Script - Option 1
 * 
 * يقوم بـ:
 * 1. أخذ نسخة احتياطية كاملة
 * 2. حذف جميع الضمانات والبيانات المرتبطة
 * 3. إعادة ترقيم البنوك والموردين من 1
 * 4. إعادة ربط جميع العلاقات (alternative_names, learning_confirmations)
 * 5. التحقق من سلامة البيانات
 * 6. إنشاء تقرير تفصيلي
 */

declare(strict_types=1);

// Configuration
define('DB_PATH', __DIR__ . '/../storage/database/app.sqlite');
define('BACKUP_DIR', __DIR__ . '/../storage/backups');
define('LOG_FILE', __DIR__ . '/reset_log_' . date('Y-m-d_His') . '.txt');

class DatabaseResetExecutor
{
    private PDO $db;
    private array $report = [];
    private string $backupPath = '';
    
    public function __construct()
    {
        $this->db = new PDO('sqlite:' . DB_PATH);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->log("=== Database Reset Script Started ===");
        $this->log("Time: " . date('Y-m-d H:i:s'));
    }
    
    public function execute(): void
    {
        try {
            // Step 1: Backup
            $this->step1_createBackup();
            
            // Step 2: Begin Transaction
            $this->db->beginTransaction();
            
            // Step 3: Get current stats
            $this->step2_getCurrentStats();
            
            // Step 4: Delete guarantees (CASCADE will handle related tables)
            $this->step3_deleteGuarantees();
            
            // Step 5: Create mapping tables
            $this->step4_createMappingTables();
            
            // Step 6: Update all relationships
            $this->step5_updateRelationships();
            
            // Step 7: Recreate suppliers table with new IDs
            $this->step6_recreateSuppliersTable();
            
            // Step 8: Recreate banks table with new IDs
            $this->step7_recreateBanksTable();
            
            // Step 9: Recreate indexes
            $this->step8_recreateIndexes();
            
            // Step 10: Reset AUTOINCREMENT counters
            $this->step9_resetCounters();
            
            // Step 11: Verify data integrity
            $this->step10_verifyIntegrity();
            
            // Step 12: Commit
            $this->db->commit();
            $this->log("✅ Transaction committed successfully!");
            
            // Step 13: Final report
            $this->generateReport();
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
                $this->log("❌ ERROR: Transaction rolled back!");
            }
            $this->log("❌ FATAL ERROR: " . $e->getMessage());
            $this->log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    private function step1_createBackup(): void
    {
        $this->log("\n[STEP 1] Creating backup...");
        
        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0755, true);
        }
        
        $timestamp = date('Y-m-d_His');
        $this->backupPath = BACKUP_DIR . "/db_backup_before_reset_{$timestamp}.sqlite";
        
        if (!copy(DB_PATH, $this->backupPath)) {
            throw new Exception("Failed to create backup!");
        }
        
        $backupSize = filesize($this->backupPath);
        $this->log("✅ Backup created: " . basename($this->backupPath));
        $this->log("   Size: " . number_format($backupSize / 1024, 2) . " KB");
        $this->report['backup'] = [
            'path' => $this->backupPath,
            'size' => $backupSize
        ];
    }
    
    private function step2_getCurrentStats(): void
    {
        $this->log("\n[STEP 2] Gathering current statistics...");
        
        $tables = [
            'guarantees',
            'guarantee_decisions',
            'guarantee_history',
            'guarantee_attachments',
            'guarantee_notes',
            'supplier_decisions_log',
            'learning_confirmations',
            'suppliers',
            'supplier_alternative_names',
            'banks',
            'bank_alternative_names'
        ];
        
        $stats = [];
        foreach ($tables as $table) {
            $count = $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $stats[$table] = $count;
            $this->log("   {$table}: {$count}");
        }
        
        $this->report['before'] = $stats;
    }
    
    private function step3_deleteGuarantees(): void
    {
        $this->log("\n[STEP 3] Deleting guarantees (CASCADE)...");
        
        $deleted = $this->db->exec("DELETE FROM guarantees");
        $this->log("✅ Deleted {$deleted} guarantees");
        $this->log("   (CASCADE deleted related: decisions, history, attachments, notes, decisions_log)");
        
        $this->report['deleted_guarantees'] = $deleted;
    }
    
    private function step4_createMappingTables(): void
    {
        $this->log("\n[STEP 4] Creating mapping tables...");
        
        // Suppliers mapping
        $this->log("   Creating supplier_id_mapping...");
        $this->db->exec("
            CREATE TEMP TABLE supplier_id_mapping AS
            SELECT 
                id as old_id,
                ROW_NUMBER() OVER (ORDER BY id) as new_id,
                official_name
            FROM suppliers
        ");
        
        $suppliersCount = $this->db->query("SELECT COUNT(*) FROM supplier_id_mapping")->fetchColumn();
        $this->log("   ✓ {$suppliersCount} suppliers mapped");
        
        // Banks mapping
        $this->log("   Creating bank_id_mapping...");
        $this->db->exec("
            CREATE TEMP TABLE bank_id_mapping AS
            SELECT 
                id as old_id,
                ROW_NUMBER() OVER (ORDER BY id) as new_id,
                arabic_name
            FROM banks
        ");
        
        $banksCount = $this->db->query("SELECT COUNT(*) FROM bank_id_mapping")->fetchColumn();
        $this->log("   ✓ {$banksCount} banks mapped");
        
        $this->report['mappings'] = [
            'suppliers' => $suppliersCount,
            'banks' => $banksCount
        ];
    }
    
    private function step5_updateRelationships(): void
    {
        $this->log("\n[STEP 5] Updating all relationships...");
        
        // First, cleanup orphaned records (alternative names pointing to deleted suppliers/banks)
        $this->log("   Cleaning up orphaned records...");
        
        $orphanedSuppliers = $this->db->exec("
            DELETE FROM supplier_alternative_names
            WHERE supplier_id NOT IN (SELECT id FROM suppliers)
        ");
        $this->log("   ✓ Removed {$orphanedSuppliers} orphaned supplier alternative names");
        
        $orphanedBanks = $this->db->exec("
            DELETE FROM bank_alternative_names
            WHERE bank_id NOT IN (SELECT id FROM banks)
        ");
        $this->log("   ✓ Removed {$orphanedBanks} orphaned bank alternative names");
        
        $orphanedLearning = $this->db->exec("
            DELETE FROM learning_confirmations
            WHERE supplier_id NOT IN (SELECT id FROM suppliers)
        ");
        $this->log("   ✓ Removed {$orphanedLearning} orphaned learning confirmations");
        
        // Now update the remaining relationships
        $this->log("   Updating supplier_alternative_names...");
        $updated = $this->db->exec("
            UPDATE supplier_alternative_names
            SET supplier_id = COALESCE((
                SELECT new_id 
                FROM supplier_id_mapping 
                WHERE old_id = supplier_alternative_names.supplier_id
            ), supplier_id)
        ");
        $this->log("   ✓ Updated {$updated} supplier alternative names");
        
        // Update bank_alternative_names
        $this->log("   Updating bank_alternative_names...");
        $updated = $this->db->exec("
            UPDATE bank_alternative_names
            SET bank_id = COALESCE((
                SELECT new_id 
                FROM bank_id_mapping 
                WHERE old_id = bank_alternative_names.bank_id
            ), bank_id)
        ");
        $this->log("   ✓ Updated {$updated} bank alternative names");
        
        // Update learning_confirmations
        $this->log("   Updating learning_confirmations...");
        $updated = $this->db->exec("
            UPDATE learning_confirmations
            SET supplier_id = COALESCE((
                SELECT new_id 
                FROM supplier_id_mapping 
                WHERE old_id = learning_confirmations.supplier_id
            ), supplier_id)
        ");
        $this->log("   ✓ Updated {$updated} learning confirmations");
        
        $this->report['updated_relationships'] = [
            'supplier_alternative_names' => true,
            'bank_alternative_names' => true,
            'learning_confirmations' => true
        ];
        
        $this->report['cleanup'] = [
            'orphaned_supplier_alt' => $orphanedSuppliers,
            'orphaned_bank_alt' => $orphanedBanks,
            'orphaned_learning' => $orphanedLearning
        ];
    }
    
    private function step6_recreateSuppliersTable(): void
    {
        $this->log("\n[STEP 6] Recreating suppliers table...");
        
        // Create new table
        $this->db->exec("
            CREATE TABLE suppliers_new AS
            SELECT 
                m.new_id as id,
                s.official_name,
                s.display_name,
                s.normalized_name,
                s.supplier_normalized_key,
                s.is_confirmed,
                s.created_at,
                s.english_name
            FROM suppliers s
            JOIN supplier_id_mapping m ON s.id = m.old_id
            ORDER BY m.new_id
        ");
        
        // Drop old and rename
        $this->db->exec("DROP TABLE suppliers");
        $this->db->exec("ALTER TABLE suppliers_new RENAME TO suppliers");
        
        $count = $this->db->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
        $this->log("   ✓ Recreated suppliers table with {$count} records");
        
        // Verify IDs start from 1
        $firstId = $this->db->query("SELECT MIN(id) FROM suppliers")->fetchColumn();
        $lastId = $this->db->query("SELECT MAX(id) FROM suppliers")->fetchColumn();
        $this->log("   ✓ ID range: {$firstId} to {$lastId}");
    }
    
    private function step7_recreateBanksTable(): void
    {
        $this->log("\n[STEP 7] Recreating banks table...");
        
        // Create new table
        $this->db->exec("
            CREATE TABLE banks_new AS
            SELECT 
                m.new_id as id,
                b.arabic_name,
                b.english_name,
                b.short_name,
                b.created_at,
                b.updated_at,
                b.department,
                b.address_line1,
                b.contact_email
            FROM banks b
            JOIN bank_id_mapping m ON b.id = m.old_id
            ORDER BY m.new_id
        ");
        
        // Drop old and rename
        $this->db->exec("DROP TABLE banks");
        $this->db->exec("ALTER TABLE banks_new RENAME TO banks");
        
        $count = $this->db->query("SELECT COUNT(*) FROM banks")->fetchColumn();
        $this->log("   ✓ Recreated banks table with {$count} records");
        
        // Verify IDs start from 1
        $firstId = $this->db->query("SELECT MIN(id) FROM banks")->fetchColumn();
        $lastId = $this->db->query("SELECT MAX(id) FROM banks")->fetchColumn();
        $this->log("   ✓ ID range: {$firstId} to {$lastId}");
    }
    
    private function step8_recreateIndexes(): void
    {
        $this->log("\n[STEP 8] Recreating indexes...");
        
        // Suppliers indexes
        $this->db->exec("CREATE INDEX idx_suppliers_normalized ON suppliers(normalized_name)");
        $this->db->exec("CREATE INDEX idx_suppliers_key ON suppliers(supplier_normalized_key)");
        
        // Banks indexes
        $this->db->exec("CREATE INDEX idx_banks_arabic_name ON banks(arabic_name)");
        $this->db->exec("CREATE INDEX idx_banks_short_name ON banks(short_name)");
        
        $this->log("   ✓ All indexes recreated");
    }
    
    private function step9_resetCounters(): void
    {
        $this->log("\n[STEP 9] Resetting AUTOINCREMENT counters...");
        
        // Clear all counters
        $this->db->exec("DELETE FROM sqlite_sequence");
        
        // Set counters for tables we kept
        $suppliers = $this->db->query("SELECT MAX(id) FROM suppliers")->fetchColumn();
        $banks = $this->db->query("SELECT MAX(id) FROM banks")->fetchColumn();
        $suppAlt = $this->db->query("SELECT MAX(id) FROM supplier_alternative_names")->fetchColumn();
        $bankAlt = $this->db->query("SELECT MAX(id) FROM bank_alternative_names")->fetchColumn();
        $learning = $this->db->query("SELECT MAX(id) FROM learning_confirmations")->fetchColumn();
        
        $this->db->exec("INSERT INTO sqlite_sequence VALUES ('suppliers', {$suppliers})");
        $this->db->exec("INSERT INTO sqlite_sequence VALUES ('banks', {$banks})");
        $this->db->exec("INSERT INTO sqlite_sequence VALUES ('supplier_alternative_names', {$suppAlt})");
        $this->db->exec("INSERT INTO sqlite_sequence VALUES ('bank_alternative_names', {$bankAlt})");
        $this->db->exec("INSERT INTO sqlite_sequence VALUES ('learning_confirmations', {$learning})");
        
        $this->log("   ✓ Counters set:");
        $this->log("     - suppliers: {$suppliers}");
        $this->log("     - banks: {$banks}");
        $this->log("     - supplier_alternative_names: {$suppAlt}");
        $this->log("     - bank_alternative_names: {$bankAlt}");
        $this->log("     - learning_confirmations: {$learning}");
    }
    
    private function step10_verifyIntegrity(): void
    {
        $this->log("\n[STEP 10] Verifying data integrity...");
        
        $checks = [];
        
        // Check 1: All supplier_alternative_names have valid supplier_id
        $orphaned = $this->db->query("
            SELECT COUNT(*) FROM supplier_alternative_names 
            WHERE supplier_id NOT IN (SELECT id FROM suppliers)
        ")->fetchColumn();
        $checks['supplier_alt_orphaned'] = $orphaned;
        $this->log("   Orphaned supplier_alternative_names: {$orphaned} " . ($orphaned == 0 ? '✓' : '❌'));
        
        // Check 2: All bank_alternative_names have valid bank_id
        $orphaned = $this->db->query("
            SELECT COUNT(*) FROM bank_alternative_names 
            WHERE bank_id NOT IN (SELECT id FROM banks)
        ")->fetchColumn();
        $checks['bank_alt_orphaned'] = $orphaned;
        $this->log("   Orphaned bank_alternative_names: {$orphaned} " . ($orphaned == 0 ? '✓' : '❌'));
        
        // Check 3: All learning_confirmations have valid supplier_id
        $orphaned = $this->db->query("
            SELECT COUNT(*) FROM learning_confirmations 
            WHERE supplier_id NOT IN (SELECT id FROM suppliers)
        ")->fetchColumn();
        $checks['learning_orphaned'] = $orphaned;
        $this->log("   Orphaned learning_confirmations: {$orphaned} " . ($orphaned == 0 ? '✓' : '❌'));
        
        // Check 4: Verify guarantees are empty
        $guarantees = $this->db->query("SELECT COUNT(*) FROM guarantees")->fetchColumn();
        $checks['guarantees_empty'] = ($guarantees == 0);
        $this->log("   Guarantees table empty: " . ($guarantees == 0 ? '✓' : '❌'));
        
        // Check 5: Verify IDs start from 1
        $supplierMinId = $this->db->query("SELECT MIN(id) FROM suppliers")->fetchColumn();
        $bankMinId = $this->db->query("SELECT MIN(id) FROM banks")->fetchColumn();
        $checks['ids_start_from_1'] = ($supplierMinId == 1 && $bankMinId == 1);
        $this->log("   IDs start from 1: " . ($checks['ids_start_from_1'] ? '✓' : '❌'));
        
        $this->report['integrity_checks'] = $checks;
        
        // Check if all passed: no orphaned records (numeric = 0) and all boolean checks are true
        $numericSum = $checks['supplier_alt_orphaned'] + $checks['bank_alt_orphaned'] + $checks['learning_orphaned'];
        $booleansPassed = $checks['guarantees_empty'] && $checks['ids_start_from_1'];
        
        $allPassed = ($numericSum == 0) && $booleansPassed;
        
        if ($allPassed) {
            $this->log("\n✅ All integrity checks PASSED!");
        } else {
            $this->log("\n❌ Integrity checks details:");
            $this->log("   Numeric sum (should be 0): {$numericSum}");
            $this->log("   Booleans passed: " . ($booleansPassed ? 'Yes' : 'No'));
            throw new Exception("Integrity checks FAILED! Check log for details.");
        }
    }
    
    private function generateReport(): void
    {
        $this->log("\n" . str_repeat("=", 70));
        $this->log("DATABASE RESET REPORT");
        $this->log(str_repeat("=", 70));
        
        $this->log("\n📦 BACKUP:");
        $this->log("   Path: " . $this->report['backup']['path']);
        $this->log("   Size: " . number_format($this->report['backup']['size'] / 1024, 2) . " KB");
        
        $this->log("\n🗑️ DELETED:");
        $this->log("   Guarantees: " . $this->report['deleted_guarantees']);
        $this->log("   (CASCADE deleted all related tables)");
        
        $this->log("\n🔄 REMAPPED:");
        $this->log("   Suppliers: " . $this->report['mappings']['suppliers']);
        $this->log("   Banks: " . $this->report['mappings']['banks']);
        
        $this->log("\n✅ PRESERVED:");
        $this->log("   Suppliers: " . $this->report['before']['suppliers']);
        $this->log("   Banks: " . $this->report['before']['banks']);
        $this->log("   Supplier alternative names: " . $this->report['before']['supplier_alternative_names']);
        $this->log("   Bank alternative names: " . $this->report['before']['bank_alternative_names']);
        $this->log("   Learning confirmations: " . $this->report['before']['learning_confirmations']);
        
        $this->log("\n✨ FINAL STATE:");
        foreach (['suppliers', 'banks', 'supplier_alternative_names', 'bank_alternative_names', 'learning_confirmations', 'guarantees'] as $table) {
            $count = $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $this->log("   {$table}: {$count}");
        }
        
        $this->log("\n" . str_repeat("=", 70));
        $this->log("✅ DATABASE RESET COMPLETED SUCCESSFULLY!");
        $this->log(str_repeat("=", 70));
        
        echo "\n\n" . file_get_contents(LOG_FILE);
    }
    
    private function log(string $message): void
    {
        $line = $message . "\n";
        file_put_contents(LOG_FILE, $line, FILE_APPEND);
    }
}

// Execute
try {
    $executor = new DatabaseResetExecutor();
    $executor->execute();
    echo "\n\n✅ SUCCESS! Check log file: " . basename(LOG_FILE) . "\n\n";
} catch (Exception $e) {
    echo "\n\n❌ FAILED: " . $e->getMessage() . "\n";
    echo "Check log file: " . basename(LOG_FILE) . "\n\n";
    exit(1);
}
