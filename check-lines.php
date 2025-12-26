<?php
require __DIR__ . '/setup/SimpleWordReader.php';

$file = glob(__DIR__ . '/setup/input/word/*.docx')[0];
$text = SimpleWordReader::extractText($file);

$lines = explode("\n", $text);

echo "=== First 10 Lines ===\n\n";
for ($i = 0; $i < min(10, count($lines)); $i++) {
    echo "Line $i: [" . trim($lines[$i]) . "]\n";
}
