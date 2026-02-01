<?php
// Template: Alerts aggregator wiring (PSR-4 friendly)
// Placeholders: {{channel}} {{event_name}} {{log_path}}
// Minimal, safe default: log JSON events to storage/logs/alerts.log

namespace App\Support;

class AlertsHandler
{
    public static function notify(array $payload): void
    {
        $channel = '{{channel}}'; // e.g. 'ops'
        $event   = '{{event_name}}'; // e.g. 'import_failed'
        $path    = base_path('{{log_path}}'); // e.g. 'storage/logs/alerts.log'

        $record = [
            'channel' => $channel,
            'event'   => $event,
            'time'    => date('c'),
            'data'    => $payload,
        ];

        @file_put_contents($path, json_encode($record) . PHP_EOL, FILE_APPEND);
    }
}
