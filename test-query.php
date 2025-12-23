<?php
// Test if query string is passed
echo "Testing query string passing:\n";
echo str_repeat("=", 60) . "\n\n";

echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET') . "\n";
echo "QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'NOT SET') . "\n";
echo "\$_GET contents:\n";
print_r($_GET);

if (isset($_GET['id'])) {
    echo "\n✅ Query string IS being passed!\n";
    echo "ID = " . $_GET['id'] . "\n";
} else {
    echo "\n❌ Query string NOT being passed!\n";
}
