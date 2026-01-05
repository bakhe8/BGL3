<?php
/**
 * Update raw_data for guarantees 9 & 18
 * Reflect matched bank and supplier names as if they were imported that way
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Updating raw_data for Guarantees 9 & 18 ===" . PHP_EOL . PHP_EOL;

foreach ([9, 18] as $gid) {
    echo "Processing Guarantee $gid:" . PHP_EOL;
    
    // Get current raw_data
    $stmt = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
    $stmt->execute([$gid]);
    $g = $stmt->fetch();
    
    $raw = json_decode($g['raw_data'], true);
    
    echo "  BEFORE: bank='{$raw['bank']}', supplier='{$raw['supplier']}'" . PHP_EOL;
    
    // Update with matched names
    $raw['bank'] = 'مصرف الإنماء';  // Matched Arabic name
    $raw['supplier'] = 'شركة مبدعون التجارية';  // Matched Arabic name
    
    // Save back
    $stmt = $db->prepare("UPDATE guarantees SET raw_data = ? WHERE id = ?");
    $stmt->execute([json_encode($raw, JSON_UNESCAPED_UNICODE), $gid]);
    
    echo "  AFTER: bank='{$raw['bank']}', supplier='{$raw['supplier']}'" . PHP_EOL;
    echo "  ✓ Updated" . PHP_EOL;
    echo PHP_EOL;
}

echo "=== DONE ===" . PHP_EOL;
echo "raw_data now reflects matched names!" . PHP_EOL;
