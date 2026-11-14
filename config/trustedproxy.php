<?php

declare(strict_types=1);

/**
 * Reverse proxies whose X-Forwarded-Proto header is believed (TM-03). Only that header is trusted (bootstrap/app.php): the
 * client IP and host are never taken from proxy headers, so `*` cannot be used to spoof `$request->ip()`. On Beget nginx terminates TLS in front of Apache/PHP, so
 * `$request->isSecure()` (and with it the SameSite=None; Secure; Partitioned cookies of the Mini App) is only right when
 * the proxy is trusted. `TRUSTED_PROXIES`: empty = nobody (default, safe for direct access and local development), `*` =
 * every caller (only behind a proxy that overwrites the headers), or a comma-separated list of addresses / CIDR ranges.
 * Read by Laravel's TrustProxies middleware (the headers are set in bootstrap/app.php).
 */
$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => $proxies === '' ? null : ($proxies === '*' || $proxies === '**' ? $proxies : array_values(array_filter(array_map('trim', explode(',', $proxies))))),
];
