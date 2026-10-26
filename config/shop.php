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
    | Bulk price change limit
    |--------------------------------------------------------------------------
    | Largest selection of products one bulk price change accepts (AD-42, D-032).
    */
    'bulk_price_max_selection' => 20_000,

];
