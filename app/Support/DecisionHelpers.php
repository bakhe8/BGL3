<?php
/**
 * Decision Helpers - Helper Functions for save-and-next.php
 * 
 * هذا الملف يحتوي على دوال مساعدة لتبسيط منطق save-and-next.php
 * دون تغيير السلوك الحالي.
 * 
 * @created 2026-01-04
 * @purpose Phase 2A - Safe Improvements
 */

use App\Support\Database;

/**
 * حل معرف المورد من الاسم
 * 
 * @param PDO $db اتصال قاعدة البيانات
 * @param string $supplierName اسم المورد
 * @return int|null معرف المورد أو null إذا لم يُعثر عليه
 */
function resolveSupplierIdByName(PDO $db, string $supplierName): ?int
{
    if (empty($supplierName)) {
        return null;
    }
    
    $normStub = mb_strtolower(trim($supplierName));
    
    // Strategy A: Exact Match
    $stmt = $db->prepare('SELECT id FROM suppliers WHERE official_name = ?');
    $stmt->execute([$supplierName]);
    $id = $stmt->fetchColumn();
    
    if ($id) {
        return (int)$id;
    }
    
    // Strategy B: Normalized Match (Case insensitive)
    $stmt = $db->prepare('SELECT id FROM suppliers WHERE normalized_name = ?');
    $stmt->execute([$normStub]);
    $id = $stmt->fetchColumn();
    
    return $id ? (int)$id : null;
}

/**
 * التحقق من تطابق ID واسم المورد (Safeguard)
 * 
 * @param PDO $db
 * @param int|null $supplierId
 * @param string $supplierName
 * @return bool true إذا كان هناك عدم تطابق (يجب إزالة ID)
 */
function hasSupplierIdNameMismatch(PDO $db, ?int $supplierId, string $supplierName): bool
{
    if (!$supplierId || empty($supplierName)) {
        return false;
    }
    
    $stmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
    $stmt->execute([$supplierId]);
    $dbName = $stmt->fetchColumn();
    
    if (!$dbName) {
        return false;
    }
    
    // Compare normalized
    return mb_strtolower(trim($dbName)) !== mb_strtolower(trim($supplierName));
}

/**
 * جلب معرف البنك من القرار الحالي أو من raw_data
 * 
 * @param PDO $db
 * @param int $guaranteeId
 * @param array $rawData
 * @return int|null
 */
function resolveBankId(PDO $db, int $guaranteeId, array $rawData): ?int
{
    // Try to get from decision first
    $stmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
    $stmt->execute([$guaranteeId]);
    $bankId = $stmt->fetchColumn();
    
    if ($bankId) {
        return (int)$bankId;
    }
    
    // Fallback: resolve from raw_data
    $rawBankName = $rawData['bank'] ?? '';
    if (empty($rawBankName)) {
        return null;
    }
    
    // Try exact match
    $stmt = $db->prepare('SELECT id FROM banks WHERE arabic_name = ?');
    $stmt->execute([$rawBankName]);
    $bankId = $stmt->fetchColumn();
    
    if ($bankId) {
        return (int)$bankId;
    }
    
    // Try normalized match
    require_once __DIR__ . '/BankNormalizer.php';
    $normalized = \App\Support\BankNormalizer::normalize($rawBankName);
    $stmt = $db->prepare("
        SELECT b.id FROM banks b 
        JOIN bank_alternative_names a ON b.id = a.bank_id 
        WHERE a.normalized_name = ? 
        LIMIT 1
    ");
    $stmt->execute([$normalized]);
    $bankId = $stmt->fetchColumn();
    
    return $bankId ? (int)$bankId : null;
}

/**
 * جلب اسم المورد الرسمي من معرفه
 * 
 * @param PDO $db
 * @param int $supplierId
 * @return string
 */
function getSupplierName(PDO $db, int $supplierId): string
{
    $stmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
    $stmt->execute([$supplierId]);
    return $stmt->fetchColumn() ?: '';
}

/**
 * جلب اسم البنك الرسمي من معرفه
 * 
 * @param PDO $db
 * @param int $bankId
 * @return string
 */
function getBankName(PDO $db, int $bankId): string
{
    $stmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
    $stmt->execute([$bankId]);
    return $stmt->fetchColumn() ?: '';
}

/**
 * اكتشاف التغييرات بين الحالة القديمة والجديدة
 * 
 * @param string $oldSupplier
 * @param string $newSupplier
 * @param string $oldBank
 * @param string $newBank
 * @return array قائمة التغييرات (strings)
 */
function detectChanges(string $oldSupplier, string $newSupplier, string $oldBank, string $newBank): array
{
    $changes = [];
    
    if (trim($oldSupplier) !== trim($newSupplier)) {
        $changes[] = "تغيير المورد من [{$oldSupplier}] إلى [{$newSupplier}]";
    }
    
    if (trim($oldBank) !== trim($newBank)) {
        $changes[] = "تغيير البنك من [{$oldBank}] إلى [{$newBank}]";
    }
    
    return $changes;
}

/**
 * التحقق من وجود قرار سابق
 * 
 * @param PDO $db
 * @param int $guaranteeId
 * @return bool
 */
function hasExistingDecision(PDO $db, int $guaranteeId): bool
{
    $stmt = $db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ?');
    $stmt->execute([$guaranteeId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * حفظ القرار (Insert أو Update)
 * 
 * @param PDO $db
 * @param int $guaranteeId
 * @param int $supplierId
 * @param int|null $bankId
 * @param string $status
 * @param string $now
 * @return void
 */
function saveDecision(PDO $db, int $guaranteeId, int $supplierId, ?int $bankId, string $status, string $now): void
{
    if (hasExistingDecision($db, $guaranteeId)) {
        // Update existing
        $stmt = $db->prepare('
            UPDATE guarantee_decisions 
            SET supplier_id = ?, status = ?, decided_at = ?
            WHERE guarantee_id = ?
        ');
        $stmt->execute([$supplierId, $status, $now, $guaranteeId]);
    } else {
        // Insert new
        $stmt = $db->prepare('
            INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decided_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$guaranteeId, $supplierId, $bankId, $status, $now, $now]);
    }
}

/**
 * مسح active_action إذا كانت الحالة ready وهناك تغييرات
 * 
 * @param PDO $db
 * @param int $guaranteeId
 * @param array $changes
 * @return void
 */
function clearActiveActionIfNeeded(PDO $db, int $guaranteeId, array $changes): void
{
    if (empty($changes)) {
        return;
    }
    
    $stmt = $db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ?');
    $stmt->execute([$guaranteeId]);
    $status = $stmt->fetchColumn();
    
    if ($status === 'ready') {
        $stmt = $db->prepare('
            UPDATE guarantee_decisions
            SET active_action = NULL, active_action_set_at = NULL
            WHERE guarantee_id = ?
        ');
        $stmt->execute([$guaranteeId]);
        error_log("📝 Cleared active_action for guarantee {$guaranteeId} due to changes");
    }
}
