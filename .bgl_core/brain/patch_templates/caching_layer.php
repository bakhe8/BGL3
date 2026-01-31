<?php
// Template: Simple per-route cache wrapper for expensive report endpoints.
// Placeholders: {{cache_key}}, {{ttl_seconds}}

use Illuminate\Support\Facades\Cache;

function cached_report_response(string $key, callable $generator)
{
    $cacheKey = '{{cache_key}}'; // e.g. 'reports:banks:summary'
    $ttl      = {{ttl_seconds}}; // e.g. 300
    return Cache::remember($cacheKey, $ttl, $generator);
}
