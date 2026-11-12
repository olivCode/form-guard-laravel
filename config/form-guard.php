<?php

declare(strict_types=1);

return [
    'profile' => 'standard',
    'scope' => 'contact',
    'directory' => storage_path('app/private/form-guard'),
    'locale' => 'en',
    'options' => [],
    // Class names resolved by the container; no objects or closures in cached config.
    'plugins' => [],
    'http' => [
        'origins' => [\FormGuard\Http\RequestGuard::origin((string) env('APP_URL', 'http://localhost'), true)],
        'max_body_bytes' => 16384,
        'max_fields' => 32,
        'max_depth' => 4,
        'max_field_bytes' => 8192,
        'allow_missing_origin' => true,
    ],
];
