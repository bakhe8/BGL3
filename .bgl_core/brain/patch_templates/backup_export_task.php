<?php
// Template: Lightweight settings/data export command
// Placeholders: {{export_path}}

$exportPath = base_path('{{export_path}}'); // e.g. storage_path('backups/config-' . date('Ymd-His') . '.json');

$payload = [
    'settings' => config('app'),
    'banks'    => $banks ?? [],
    'suppliers'=> $suppliers ?? [],
    'exported_at' => date('c'),
];

file_put_contents($exportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Exported to {$exportPath}\n";
