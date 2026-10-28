<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Admin panel path
    |--------------------------------------------------------------------------
    | URL prefix of the Filament admin panel (D-004).
    */
    'admin_path' => env('ADMIN_PATH', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Hosts (D-057)
    |--------------------------------------------------------------------------
    | canonical_host_redirect: in production the www./bare twin of the APP_URL host is redirected to APP_URL.
    | extra_hosts: additional trusted hosts (SHOP_EXTRA_HOSTS, comma separated), e.g. the Beget technical domain.
    */
    'canonical_host_redirect' => true,
    'extra_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('SHOP_EXTRA_HOSTS', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Sitemap directory (SF-82)
    |--------------------------------------------------------------------------
    | Where sitemap.xml is written, relative to the project root; null = public/.
    */
    'sitemap_dir' => env('SITEMAP_DIR') ? base_path((string) env('SITEMAP_DIR')) : null,

    /*
    | TTL in seconds of the cache lock around sitemap generation (D-056); must exceed the longest run on a large catalog.
    */
    'sitemap_lock_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Scheduler heartbeat file (AD-11)
    |--------------------------------------------------------------------------
    | Relative to the project root; null = storage/app/heartbeat. Tests point it to a scratch file.
    */
    'heartbeat_path' => env('HEARTBEAT_PATH') ? base_path((string) env('HEARTBEAT_PATH')) : null,

    /*
    |--------------------------------------------------------------------------
    | Price boundaries last-run file (PR-02)
    |--------------------------------------------------------------------------
    | Relative to the project root; null = storage/app/pricing-boundaries-last-run. Tests point it to a scratch file.
    */
    'pricing_boundaries_path' => env('PRICING_BOUNDARIES_PATH') ? base_path((string) env('PRICING_BOUNDARIES_PATH')) : null,

    /*
    |--------------------------------------------------------------------------
    | Bulk price change limit
    |--------------------------------------------------------------------------
    | Largest selection of products one bulk price change accepts (AD-42, D-032).
    */
    'bulk_price_max_selection' => 20_000,

    /*
    |--------------------------------------------------------------------------
    | Exports (EX-01, EX-03)
    |--------------------------------------------------------------------------
    | Up to `export_sync_limit` rows a file is built in the request, larger exports run in the queue.
    | Export files are kept for `export_retention_days` days.
    */
    'export_sync_limit' => 2_000,

    'export_retention_days' => 7,

    /*
    | Largest selection of products "Export to Excel" accepts (AD-42).
    */
    'export_max_selection' => 20_000,

    /*
    | Total uncompressed size (MB) of photos an import ZIP may contain (IM-02, zip bomb guard).
    */
    'import_media_max_total_mb' => 512,

    /*
    | Days import files (source, ZIP, errors report) are kept (IM-13).
    */
    'import_retention_days' => 30,

    /*
    | Days the admin action journal is kept (RB-12).
    */
    'audit_retention_days' => 365,

];
