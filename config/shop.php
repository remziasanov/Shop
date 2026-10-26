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
    | Sitemap directory (SF-82)
    |--------------------------------------------------------------------------
    | Where sitemap.xml is written, relative to the project root; null = public/.
    */
    'sitemap_dir' => env('SITEMAP_DIR') ? base_path((string) env('SITEMAP_DIR')) : null,

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

];
