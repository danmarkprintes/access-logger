<?php

declare(strict_types=1);

$origins = getenv('CORS_ORIGINS');
if ($origins === false || $origins === '') {
    $origins = '*';
}

return [
    'display_error_details' => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOL),
    'db' => [
        // Docker: host=mysql. Produção LSWS: 127.0.0.1 ou socket — ver settings.local.php
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=access_logger;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: 'root',
    ],
    'cors' => [
        'allowed_origins' => array_map('trim', explode(',', $origins)),
    ],
    'rate_limit' => [
        'ip_per_minute' => (int)(getenv('RATE_LIMIT_IP') ?: 20),
        'ua_per_minute' => (int)(getenv('RATE_LIMIT_UA') ?: 40),
        'storage_path' => getenv('RATE_LIMIT_PATH') ?: '/tmp/access-logger-rl',
    ],
    'filters' => [
        'geo_brazil_only' => filter_var(getenv('GEO_BR_ONLY') ?: '0', FILTER_VALIDATE_BOOL),
        'filtered_hosts' => ['deve.meelion.com'],
    ],
];
