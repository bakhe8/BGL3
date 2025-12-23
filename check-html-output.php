<?php
// Capture exact HTML output
ob_start();
include __DIR__ . '/index.php';
$html = ob_get_clean();

// Extract record data from JavaScript
if (preg_match('/record:\s*\{([^}]+)\}/s', $html, $matches)) {
    echo "Found in HTML:\n";
    echo "record: {" . $matches[1] . "}\n\n";
}

// Search for specific values
echo "Searching in HTML for:\n";
echo str_repeat("=", 80) . "\n";

if (preg_match('/supplier_name:\s*[\'"]([^\'"]+)[\'"]/', $html, $m)) {
    echo "supplier_name: " . $m[1] . "\n";
}

if (preg_match('/bank_name:\s*[\'"]([^\'"]+)[\'"]/', $html, $m)) {
    echo "bank_name: " . $m[1] . "\n";
}

if (preg_match('/amount:\s*(\d+)/', $html, $m)) {
    echo "amount: " . $m[1] . "\n";
}

if (preg_match('/guarantee_number:\s*[\'"]([^\'"]+)[\'"]/', $html, $m)) {
    echo "guarantee_number: " . $m[1] . "\n";
}
