<?php
require __DIR__ . '/app/Support/Database.php';

$db = App\Support\Database::connect();

// Get all suppliers
$stmt = $db->prepare('SELECT id, official_name, is_confirmed, created_at FROM suppliers ORDER BY id DESC LIMIT 20');
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Last 20 Suppliers in Database ===\n\n";
foreach ($suppliers as $s) {
    $marker = ($s['id'] == 57) ? ' ⭐ THIS IS THE ONE' : '';
    echo "ID {$s['id']}: {$s['official_name']} (confirmed: {$s['is_confirmed']}){$marker}\n";
}

// Specifically check supplier 57
echo "\n=== Checking Supplier #57 Specifically ===\n";
$stmt2 = $db->prepare('SELECT * FROM suppliers WHERE id = 57');
$stmt2->execute();
$supplier57 = $stmt2->fetch(PDO::FETCH_ASSOC);

if ($supplier57) {
    echo "✅ Supplier #57 EXISTS\n";
    echo "Official Name: " . $supplier57['official_name'] . "\n";
    echo "Normalized: " . $supplier57['normalized_name'] . "\n";
    echo "Is Confirmed: " . $supplier57['is_confirmed'] . "\n";
    echo "Created: " . $supplier57['created_at'] . "\n";
} else {
    echo "❌ Supplier #57 NOT FOUND\n";
}
