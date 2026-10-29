<?php

declare(strict_types=1);

/*
 * Fails (exit 1) when a PHP version is below the patched minimum of its branch (config/php_security.php, D-106).
 * Usage: php .github/scripts/php_security_check.php [version]   — without an argument checks the running PHP.
 */

$config = require __DIR__.'/../../config/php_security.php';
$version = $argv[1] ?? PHP_VERSION;

if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
    fwrite(STDERR, "Cannot parse PHP version '{$version}'.\n");
    exit(1);
}

$plain = "{$m[1]}.{$m[2]}.{$m[3]}";
$minimum = $config['patched_minimum']["{$m[1]}.{$m[2]}"] ?? null;

if (version_compare($plain, '8.3.0', '<')) {
    fwrite(STDERR, "PHP {$plain} is not supported: 8.3 or newer is required.\n");
    exit(1);
}

if ($minimum !== null && version_compare($plain, $minimum, '<')) {
    fwrite(STDERR, "PHP {$plain} has known security vulnerabilities ({$config['advisory']}): update to {$minimum} or newer.\n");
    exit(1);
}

echo "PHP {$plain}: OK\n";
