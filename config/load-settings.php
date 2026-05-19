<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function access_logger_load_settings(): array
{
    $settings = require __DIR__ . '/settings.php';
    $localPath = __DIR__ . '/settings.local.php';

    if (is_file($localPath)) {
        $local = require $localPath;
        if (is_array($local)) {
            $settings = array_replace_recursive($settings, $local);
        }
    }

    return $settings;
}
