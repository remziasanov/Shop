<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Access\CatalogAccess;
use App\Services\Access\ViewerContext;
use Illuminate\Support\ServiceProvider;

/**
 * Viewer / access wiring (stage 8, TZ 25-26). The catalog version (26.3) is bumped by the existing invalidation
 * points: HomePageRenderer::forget() (products, categories, product images, settings, finished imports, price and
 * stock changes), MenuService::flush() (pages, menu items, categories, settings) and the customer group observer.
 *
 * ViewerContext is a singleton whose per-request state {@see \App\Http\Middleware\ResolveViewer} resets; it is not
 * "scoped" because long-lived services hold it and must see the same object the middleware resets.
 */
class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CatalogAccess::class);
        $this->app->singleton(ViewerContext::class);
    }
}
