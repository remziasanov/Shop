<?php

declare(strict_types=1);

use App\Providers\AccessServiceProvider;
use App\Providers\AgeGateServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CustomerServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HomePageServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\SeoServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HomePageServiceProvider::class,
    AccessServiceProvider::class,
    AgeGateServiceProvider::class,
    NotificationServiceProvider::class,
    CustomerServiceProvider::class,
    SeoServiceProvider::class,
];
