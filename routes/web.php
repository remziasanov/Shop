<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ExportDownloadController;
use App\Http\Controllers\Admin\ImportFileDownloadController;
use App\Http\Controllers\Admin\ImportProcessNextController;
use App\Http\Controllers\Admin\ImportTemplateController;
use App\Http\Controllers\Admin\OrderPrintController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderThanksController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Controllers\Storefront\SearchSuggestController;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{slug}', [CatalogController::class, 'category'])->name('catalog.category');
Route::get('/product/{slug}', [ProductController::class, 'show'])->name('product.show');
Route::get('/search', SearchController::class)->name('search');
Route::get('/search/suggest', SearchSuggestController::class)->middleware('throttle:60,1')->name('search.suggest');

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
