<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HomePageServiceProvider;
use App\Providers\SeoServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HomePageServiceProvider::class,
    SeoServiceProvider::class,
];
