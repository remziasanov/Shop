<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CronHelpController;
use App\Http\Controllers\Admin\ExportDownloadController;
use App\Http\Controllers\Admin\ImportFileDownloadController;
use App\Http\Controllers\Admin\ImportProcessNextController;
use App\Http\Controllers\Admin\ImportTemplateController;
use App\Http\Controllers\Admin\OrderPdfController;
use App\Http\Controllers\Admin\OrderPrintController;
use App\Http\Controllers\Storefront\Account\AccountController;
use App\Http\Controllers\Storefront\Account\AddressController;
use App\Http\Controllers\Storefront\Account\CompanyController;
use App\Http\Controllers\Storefront\Account\EmailConfirmationController;
use App\Http\Controllers\Storefront\Account\ExportController;
use App\Http\Controllers\Storefront\Account\LoginController;
use App\Http\Controllers\Storefront\Account\LogoutController;
use App\Http\Controllers\Storefront\Account\NotificationController;
use App\Http\Controllers\Storefront\Account\OrderBlankController;
use App\Http\Controllers\Storefront\Account\OrderHistoryController;
use App\Http\Controllers\Storefront\Account\PasswordResetController;
use App\Http\Controllers\Storefront\Account\ProfileController;
use App\Http\Controllers\Storefront\Account\RegisterController;
use App\Http\Controllers\Storefront\Account\SecurityController;
use App\Http\Controllers\Storefront\Account\TelegramLinkController;
use App\Http\Controllers\Storefront\Account\WholesaleApplicationController;
use App\Http\Controllers\Storefront\AgeGateController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\ManifestController;
use App\Http\Controllers\Storefront\OrderThanksController;
use App\Http\Controllers\Storefront\PageController;
use App\Http\Controllers\Storefront\PriceListController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\PromoLinkController;
use App\Http\Controllers\Storefront\RobotsController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Controllers\Storefront\SearchSuggestController;
use App\Http\Controllers\Storefront\SitemapController;
use App\Http\Controllers\Storefront\Telegram\TelegramCabinetController;
use App\Http\Controllers\Storefront\Telegram\TelegramEntryController;
use App\Http\Controllers\Storefront\ThemePreviewController;
use App\Http\Controllers\Storefront\ViewerPreviewController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// robots.txt and the manifest are public, cacheable files: no session, cookies or CSRF token (nothing is set on a fetch).
$statelessMiddleware = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
];

// TG-04: the bot webhook. Outside the web group (no session, cookies or CSRF, not subject to «Магазин закрыт»); the
// secret header is the authentication, the limit is 120 requests per minute per IP.
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->withoutMiddleware('web')
    ->middleware('throttle:telegram-webhook')
    ->name('telegram.webhook');

Route::get('/', HomeController::class)->name('home');

// SF-82: robots.txt from the SEO settings; sitemap.xml is a static file written by shop:sitemap (this route is the fallback).
Route::get('/robots.txt', RobotsController::class)->name('robots')->withoutMiddleware($statelessMiddleware);
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// MB-20: the web app manifest of the installable shop (404 while the setting is off).
Route::get('/manifest.webmanifest', ManifestController::class)->name('pwa.manifest')->withoutMiddleware($statelessMiddleware);

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{slug}', [CatalogController::class, 'category'])->name('catalog.category');
Route::get('/product/{slug}', [ProductController::class, 'show'])->name('product.show');
Route::get('/page/{slug}', [PageController::class, 'show'])->name('page.show');
Route::get('/search', SearchController::class)->name('search');
Route::get('/search/suggest', SearchSuggestController::class)->middleware('throttle:60,1')->name('search.suggest');

// Age gate (stage 8, TZ 16): the answers of the overlay; both work as plain links / forms without JavaScript.
Route::post('/age-gate/confirm', [AgeGateController::class, 'confirm'])->middleware('throttle:60,1')->name('age-gate.confirm');
Route::get('/age-gate/decline', [AgeGateController::class, 'decline'])->name('age-gate.decline');

// Telegram Mini App of the customer (stage 17, TZ 29): `/tg` is the address of the menu button and of every web_app
// button. Not indexed, not in the sitemap. The pages after the entry are the usual storefront routes in the Telegram mode.
Route::prefix('tg')->group(function (): void {
    Route::get('/', [TelegramEntryController::class, 'show'])->name('tg.entry');
    Route::post('/session', [TelegramEntryController::class, 'session'])->middleware('throttle:tg-session')->name('tg.session');
    Route::post('/leave', [TelegramEntryController::class, 'leave'])->middleware('throttle:tg-leave')->name('tg.leave');
    Route::get('/start', [TelegramEntryController::class, 'start'])->middleware('throttle:tg-start')->name('tg.start');
    Route::post('/write-access', [TelegramEntryController::class, 'writeAccess'])->middleware('throttle:tg-write-access')->name('tg.write-access');
    Route::get('/phone', [TelegramEntryController::class, 'phone'])->middleware('throttle:tg-phone')->name('tg.phone');
    Route::get('/me', [TelegramCabinetController::class, 'me'])->name('tg.me');
    Route::get('/about', [TelegramCabinetController::class, 'about'])->name('tg.about');
});

// Customer account (stage 9, TZ 17): registration with an e-mail code, sign-in by password or code, password reset,
// profile. The pages carry personal data and one-time flows: private, not indexed. `customer.login` is also the target
// of the «войдите» links and of the redirects of hidden sections (CG-04, CG-06). Limits (CA-12) live in the services.
Route::prefix('account')->middleware('account.private')->group(function (): void {
    Route::middleware('customer.guest')->group(function (): void {
        Route::get('/register', [RegisterController::class, 'show'])->name('customer.register');
        Route::post('/register', [RegisterController::class, 'store'])->name('customer.register.store');
        Route::get('/confirm', [EmailConfirmationController::class, 'show'])->name('customer.confirm');
        Route::post('/confirm', [EmailConfirmationController::class, 'store'])->name('customer.confirm.store');
        Route::post('/confirm/resend', [EmailConfirmationController::class, 'resend'])->name('customer.confirm.resend');

        Route::get('/login', [LoginController::class, 'show'])->name('customer.login');
        Route::post('/login', [LoginController::class, 'store'])->name('customer.login.store');
        Route::post('/login/code', [LoginController::class, 'sendCode'])->name('customer.login.send-code');
        Route::get('/login/code', [LoginController::class, 'showCode'])->name('customer.login.code');
        Route::post('/login/code/verify', [LoginController::class, 'verifyCode'])->name('customer.login.verify');
        Route::post('/login/code/resend', [LoginController::class, 'resendCode'])->name('customer.login.resend');

        Route::get('/password/forgot', [PasswordResetController::class, 'showRequest'])->name('customer.password.forgot');
        Route::post('/password/forgot', [PasswordResetController::class, 'sendCode'])->name('customer.password.send-code');
        Route::get('/password/code', [PasswordResetController::class, 'showCode'])->name('customer.password.code');
        Route::post('/password/code', [PasswordResetController::class, 'verifyCode'])->name('customer.password.verify');
        Route::post('/password/code/resend', [PasswordResetController::class, 'resendCode'])->name('customer.password.resend');
        Route::get('/password/reset', [PasswordResetController::class, 'showReset'])->name('customer.password.reset');
        Route::post('/password/reset', [PasswordResetController::class, 'reset'])->name('customer.password.update');
    });

    Route::middleware('customer.auth')->group(function (): void {
        Route::get('/', [AccountController::class, 'index'])->name('account');
        Route::post('/logout', LogoutController::class)->name('customer.logout');

        Route::get('/profile', [ProfileController::class, 'show'])->name('customer.profile');
        Route::post('/profile', [ProfileController::class, 'update'])->name('customer.profile.update');
        Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('customer.profile.password');
        Route::post('/profile/email', [ProfileController::class, 'requestEmail'])->name('customer.email.request');
        Route::get('/profile/email/confirm', [ProfileController::class, 'showEmailConfirm'])->name('customer.email.confirm');
        Route::post('/profile/email/confirm', [ProfileController::class, 'confirmEmail'])->name('customer.email.confirm.store');
        Route::post('/profile/email/confirm/resend', [ProfileController::class, 'resendEmailCode'])->name('customer.email.confirm.resend');

        // CA-09: address book.
        Route::get('/addresses', [AddressController::class, 'index'])->name('customer.addresses');
        Route::post('/addresses', [AddressController::class, 'store'])->name('customer.addresses.store');
        Route::post('/addresses/{address}', [AddressController::class, 'update'])->whereNumber('address')->name('customer.addresses.update');
        Route::post('/addresses/{address}/default', [AddressController::class, 'makeDefault'])->whereNumber('address')->name('customer.addresses.default');
        Route::post('/addresses/{address}/delete', [AddressController::class, 'destroy'])->whereNumber('address')->name('customer.addresses.destroy');

        // CA-10: company requisites.
        Route::get('/company', [CompanyController::class, 'show'])->name('customer.company');
        Route::post('/company', [CompanyController::class, 'save'])->name('customer.company.save');
        Route::post('/company/delete', [CompanyController::class, 'destroy'])->name('customer.company.destroy');

        // CA-11, CA-13, CA-28: order history, detail by UUID, repeat order.
        Route::get('/orders', [OrderHistoryController::class, 'index'])->name('customer.orders');
        Route::get('/orders/{uuid}', [OrderHistoryController::class, 'show'])->name('customer.orders.show');
        Route::post('/orders/{uuid}/repeat', [OrderHistoryController::class, 'repeat'])->name('customer.orders.repeat');
        // CX-10: the order blank / invoice as PDF (groups with the allow_order_blank flag).
        Route::get('/orders/{uuid}/blank', OrderBlankController::class)->name('customer.orders.blank');

        // CX-01, CX-04…CX-09: order history exports, the list «Мои выгрузки», the download by UUID.
        Route::get('/exports', [ExportController::class, 'index'])->name('customer.exports');
        Route::post('/exports', [ExportController::class, 'store'])->name('customer.exports.store');
        Route::get('/exports/{uuid}', [ExportController::class, 'download'])->name('customer.exports.download');

        // CA-18: cabinet notifications.
        Route::get('/notifications', [NotificationController::class, 'index'])->name('customer.notifications');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('customer.notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification')->name('customer.notifications.read');

        // TG-07: connect / disconnect the customer's Telegram (the bot asks for confirmation).
        Route::post('/notifications/telegram', [TelegramLinkController::class, 'connect'])->middleware('throttle:10,1')->name('customer.telegram.connect');
        Route::post('/notifications/telegram/disconnect', [TelegramLinkController::class, 'disconnect'])->name('customer.telegram.disconnect');

        // CA-16: security section, deleting the account with a code.
        Route::get('/security', [SecurityController::class, 'show'])->name('customer.security');
        Route::post('/security/delete', [SecurityController::class, 'requestDeletion'])->name('customer.security.delete.request');
        Route::get('/security/delete', [SecurityController::class, 'showConfirm'])->name('customer.security.delete');
        Route::post('/security/delete/confirm', [SecurityController::class, 'confirmDeletion'])->name('customer.security.delete.confirm');
        Route::post('/security/delete/resend', [SecurityController::class, 'resendDeletionCode'])->name('customer.security.delete.resend');

        // CG-17: the wholesale price list of the customer's group, 10 downloads per hour.
        Route::get('/price-list', [PriceListController::class, 'account'])->middleware('throttle:10,60')->name('customer.price-list');

        // CG-20: wholesale access application (stage 9, part B).
        Route::post('/wholesale', [WholesaleApplicationController::class, 'store'])->name('customer.wholesale.store');
    });

    // CG-20: a guest sees an invitation to register, a signed-in customer the application form or its status.
    Route::get('/wholesale', [WholesaleApplicationController::class, 'show'])->name('customer.wholesale');
});

// CG-17: the price list of «Гость», only while that group sees the section; 10 downloads per hour.
Route::get('/price-list.xlsx', [PriceListController::class, 'guest'])->middleware('throttle:10,60')->name('price-list.guest');

// CG-18: staff look at the storefront as a customer group (a flag of the staff session, not a customer login); POST so that CSRF protects it.
Route::post('/preview-as/exit', [ViewerPreviewController::class, 'exit'])->name('viewer.preview.exit');
Route::post('/preview-as/{group}', [ViewerPreviewController::class, 'enter'])->whereNumber('group')->name('viewer.preview');

// TH-27: buttons of the staff theme preview bar (a flag of the staff session; each action re-checks the right).
Route::post('/theme-preview/step', [ThemePreviewController::class, 'step'])->name('theme.preview.step');
Route::post('/theme-preview/apply', [ThemePreviewController::class, 'apply'])->name('theme.preview.apply');
Route::post('/theme-preview/exit', [ThemePreviewController::class, 'exit'])->name('theme.preview.exit');

// Sales (stage 4): cart, checkout and the "thank you" page addressed by the order UUID (SF-40…47).
Route::get('/cart', [CartController::class, 'show'])->name('cart');
Route::post('/cart/add', [CartController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
Route::get('/checkout', CheckoutController::class)->name('checkout');
Route::get('/order/{uuid}', OrderThanksController::class)->name('order.thanks');

// Promo links (stage 13, PM-21…PM-25): 302 to an internal target, attribution and promo code cookies; noindex.
Route::get('/go/{slug}', PromoLinkController::class)->where('slug', '[A-Za-z0-9-]{1,64}')->middleware('throttle:120,1')->name('promo-link');

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
        // CX-13: the same document as the cabinet blank (invoice or blank), as a PDF file.
        Route::get('/orders/{order}/pdf', OrderPdfController::class)->name('admin.orders.pdf');
    });

// AD-11: cron setup instructions linked from the scheduler warning; administrators and managers.
Route::middleware([FilamentAuthenticate::class])
    ->prefix((string) config('shop.admin_path'))
    ->group(function (): void {
        Route::get('/help/cron', CronHelpController::class)->name('admin.help.cron');
    });
