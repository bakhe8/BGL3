<?php
/**
 * Migration: Unify Status Values to 'ready'
 * 
 * This script updates all 'approved' status values to 'ready' in the database.
 * Run this once after deploying the code changes.
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

echo "🔄 بدء توحيد Status Values إلى 'ready'...\n\n";

try {
    $db = Database::connect();
    
    // Start transaction
    $db->beginTransaction();
    
    // Count current statuses
    echo "📊 الحالة الحالية:\n";
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM guarantee_decisions GROUP BY status");
    $currentStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($currentStatus as $row) {
        $status = $row['status'] ?? 'NULL';
        echo "   - $status: {$row['count']} سجل\n";
    }
    echo "\n";
    
    // Update all 'approved' to 'ready'
    echo "🔄 تحديث 'approved' إلى 'ready'...\n";
    $stmt = $db->prepare("UPDATE guarantee_decisions SET status = 'ready' WHERE status = 'approved'");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "✅ تم تحديث $affected سجل من 'approved' إلى 'ready'\n\n";
    
    // Verify final state
    echo "📊 الحالة النهائية:\n";
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM guarantee_decisions GROUP BY status");
    $finalStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($finalStatus as $row) {
        $status = $row['status'] ?? 'NULL';
        echo "   - $status: {$row['count']} سجل\n";
    }
    
    // Commit transaction
    $db->commit();
    
    echo "\n✅ تم إكمال التوحيد بنجاح!\n";
    echo "📋 القيم المسموح بها الآن: 'pending', 'ready'\n";
    
} catch (\Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n❌ خطأ: " . $e->getMessage() . "\n";
    exit(1);
}
