<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();
echo "Creating Learning Tables...\n";

// 1. Supplier Alternative Names (Aliases)
$db->exec("
    CREATE TABLE IF NOT EXISTS supplier_alternative_names (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        supplier_id INTEGER NOT NULL,
        alternative_name TEXT NOT NULL,
        normalized_name TEXT NOT NULL,
        source TEXT DEFAULT 'manual',
        usage_count INTEGER DEFAULT 0,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    );
    CREATE INDEX IF NOT EXISTS idx_supplier_norm_name ON supplier_alternative_names(normalized_name);
");
echo "✅ supplier_alternative_names created.\n";

// 2. Bank Alternative Names (Aliases)
$db->exec("
    CREATE TABLE IF NOT EXISTS bank_alternative_names (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bank_id INTEGER NOT NULL,
        alternative_name TEXT NOT NULL,
        normalized_name TEXT NOT NULL,
        source TEXT DEFAULT 'manual',
        usage_count INTEGER DEFAULT 0,
        FOREIGN KEY (bank_id) REFERENCES banks(id)
    );
    CREATE INDEX IF NOT EXISTS idx_bank_norm_name ON bank_alternative_names(normalized_name);
");
echo "✅ bank_alternative_names created.\n";

// 3. Supplier Decisions Log
$db->exec("
    CREATE TABLE IF NOT EXISTS supplier_decisions_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        guarantee_id INTEGER,
        raw_input TEXT,
        normalized_input TEXT,
        chosen_supplier_id INTEGER,
        chosen_supplier_name TEXT,
        decision_source TEXT,
        confidence_score INTEGER,
        was_top_suggestion INTEGER,
        decided_at DATETIME
    );
");
echo "✅ supplier_decisions_log created.\n";

// 4. Bank Decisions Log
$db->exec("
    CREATE TABLE IF NOT EXISTS bank_decisions_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        guarantee_id INTEGER,
        raw_input TEXT,
        normalized_input TEXT,
        chosen_bank_id INTEGER,
        chosen_bank_name TEXT,
        decision_source TEXT,
        confidence_score INTEGER,
        was_top_suggestion INTEGER,
        decided_at DATETIME
    );
");
echo "✅ bank_decisions_log created.\n";

// Seed some initial data for testing if tables were empty
$count = $db->query("SELECT COUNT(*) FROM bank_alternative_names")->fetchColumn();
if ($count == 0) {
    // SNB Alias
    $snb = $db->query("SELECT id FROM banks WHERE official_name LIKE '%Ahli%' OR official_name LIKE '%SNB%' LIMIT 1")->fetchColumn();
    if ($snb) {
        $db->exec("INSERT INTO bank_alternative_names (bank_id, alternative_name, normalized_name, source, usage_count) 
                  VALUES ($snb, 'NCB', 'ncb', 'seed', 1)");
        echo "✅ Seeded NCB alias for SNB.\n";
    }
}
