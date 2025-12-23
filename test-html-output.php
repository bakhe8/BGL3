<?php
// Direct test - what does index.php actually generate?
ob_start();
$_GET['id'] = 1;
include __DIR__ . '/index.php';
$output = ob_get_clean();

// Extract the record data from JavaScript
if (preg_match('/record:\s*\{([^}]+)\}/', $output, $matches)) {
    echo "Found record data in HTML:\n";
    echo "record: {" . $matches[1] . "}\n\n";
}

// Look for specific values
echo "Searching for specific values in HTML:\n";
echo "guarantee_number: ";
if (preg_match('/guarantee_number:\s*[\'"]([^\'"]+)[\'"]/', $output, $m)) {
    echo $m[1] . "\n";
} else {
    echo "NOT FOUND\n";
}

echo "supplier_name: ";
if (preg_match('/supplier_name:\s*[\'"]([^\'"]+)[\'"]/', $output, $m)) {
    echo $m[1] . "\n";
} else {
    echo "NOT FOUND\n";
}

echo "bank_name: ";
if (preg_match('/bank_name:\s*[\'"]([^\'"]+)[\'"]/', $output, $m)) {
    echo $m[1] . "\n";
} else {
    echo "NOT FOUND\n";
}

echo "amount: ";
if (preg_match('/amount:\s*(\d+)/', $output, $m)) {
    echo $m[1] . "\n";
} else {
    echo "NOT FOUND\n";
}
