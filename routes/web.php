<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CronHelpController;
use App\Http\Controllers\Admin\ExportDownloadController;
use App\Http\Controllers\Admin\ImportFileDownloadController;
use App\Http\Controllers\Admin\ImportProcessNextController;
use App\Http\Controllers\Admin\ImportTemplateController;
use App\Http\Controllers\Admin\OrderPrintController;
use App\Http\Controllers\Storefront\AgeGateController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\CustomerLoginController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderThanksController;
use App\Http\Controllers\Storefront\PageController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\RobotsController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Controllers\Storefront\SearchSuggestController;
use App\Http\Controllers\Storefront\SitemapController;
use App\Http\Controllers\Storefront\ViewerPreviewController;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// SF-82: robots.txt from the SEO settings; sitemap.xml is a static file written by shop:sitemap (this route is the fallback).
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{slug}', [CatalogController::class, 'category'])->name('catalog.category');
Route::get('/product/{slug}', [ProductController::class, 'show'])->name('product.show');
Route::get('/page/{slug}', [PageController::class, 'show'])->name('page.show');
Route::get('/search', SearchController::class)->name('search');
Route::get('/search/suggest', SearchSuggestController::class)->middleware('throttle:60,1')->name('search.suggest');

// Age gate (stage 8, TZ 16): the answers of the overlay; both work as plain links / forms without JavaScript.
Route::post('/age-gate/confirm', [AgeGateController::class, 'confirm'])->middleware('throttle:60,1')->name('age-gate.confirm');
Route::get('/age-gate/decline', [AgeGateController::class, 'decline'])->name('age-gate.decline');

// Customer groups (stage 8, CG-04, CG-06): the sign-in route that «войдите» links and hidden sections point to. A
// placeholder page until stage 9 brings the customer accounts.
Route::get('/account/login', CustomerLoginController::class)->name('customer.login');

// CG-18: staff look at the storefront as a customer group (a flag of the staff session, not a customer login).
Route::get('/preview-as/exit', [ViewerPreviewController::class, 'exit'])->name('viewer.preview.exit');
Route::get('/preview-as/{group}', [ViewerPreviewController::class, 'enter'])->whereNumber('group')->name('viewer.preview');

// Sales (stage 4): cart, checkout and the "thank you" page addressed by the order UUID (SF-40…47).
Route::get('/cart', [CartController::class, 'show'])->name('cart');
Route::post('/cart/add', [CartController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
Route::get('/checkout', CheckoutController::class)->name('checkout');
Route::get('/order/{uuid}', OrderThanksController::class)->name('order.thanks');

// Admin file endpoints (exports, import template): panel authentication, administrators and managers only.
Route::middleware([FilamentAuthenticate::class])
    ->prefix((string) config('shop.admin_path'))
    ->group(function (): void {
        Route::get('/exports/{export}/download', ExportDownloadController::class)->name('admin.exports.download');
        Route::get('/import/template', ImportTemplateController::class)->name('admin.import.template');
        Route::get('/imports/{import}/download/{kind}', ImportFileDownloadController::class)
            ->whereIn('kind', ['source', 'errors'])
            ->name('admin.imports.download');
        // Browser fallback of the import queue (IM-10); POST: CSRF-protected by the web middleware group.
        Route::post('/imports/{import}/process-next', ImportProcessNextController::class)->name('admin.imports.process-next');
    });

// ---------------------------------------------------------------------------------------------
// Admin: requests (stage 4, OR-06). Kept apart from the storefront routes above.
// ---------------------------------------------------------------------------------------------
Route::middleware([FilamentAuthenticate::class])
    ->prefix((string) config('shop.admin_path'))
    ->group(function (): void {
        Route::get('/orders/{order}/print', OrderPrintController::class)->name('admin.orders.print');
    });

// AD-11: cron setup instructions linked from the scheduler warning; administrators and managers.
Route::middleware([FilamentAuthenticate::class])
    ->prefix((string) config('shop.admin_path'))
    ->group(function (): void {
        Route::get('/help/cron', CronHelpController::class)->name('admin.help.cron');
    });
