<?php
declare(strict_types=1);

// Configuration loader from local config.php
function getConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Missing config.php file with required configuration.');
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php must return an associative array.');
    }
    return $config = $loaded;
}

function cfg(string $key, mixed $default = null): mixed
{
    $conf = getConfig();
    return array_key_exists($key, $conf) ? $conf[$key] : $default;
}
