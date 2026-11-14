<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Admin\StaffLogoutController;
use App\Listeners\ApplyShopNameToMail;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use App\Services\Content\MenuService;
use App\Services\Import\DnsHostResolver;
use App\Services\Import\HostResolver;
use App\Services\Media\ImageProcessor;
use App\Services\Pricing\PriceChangeContext;
use App\Services\Pricing\PriceRuleRepository;
use App\Services\Sales\CartAvailability;
use App\Services\Telegram\MiniApp\MiniAppConfig;
use App\Services\Themes\ActiveTheme;
use App\Services\Themes\FontRegistry;
use App\Services\Themes\ThemeRegistry;
use App\Services\Users\PermissionService;
use App\Services\Variants\VariantAggregator;
use App\Settings\TelegramSettings;
use App\View\Composers\StorefrontComposer;
use App\View\Composers\ThemeComposer;
use Filament\Auth\Http\Controllers\LogoutController as FilamentLogoutController;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelSettings\Events\SettingsSaved;

class AppServiceProvider extends ServiceProvider
{
    public const DATE_FORMAT = 'd.m.Y';

    public const DATE_TIME_FORMAT = 'd.m.Y H:i';

    public const TIME_FORMAT = 'H:i';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CategoryTree::class);
        $this->app->singleton(PriceRuleRepository::class);
        $this->app->singleton(PriceChangeContext::class);
        // Holds the deferred-refresh state of bulk variant operations (VR-06).
        $this->app->singleton(VariantAggregator::class);
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(PermissionService::class);
        $this->app->singleton(ImageProcessor::class);
        $this->app->singleton(StorefrontComposer::class);
        // Themes (stage 20): the registries are read once, the active theme is resolved once per request.
        $this->app->singleton(ThemeRegistry::class);
        $this->app->singleton(FontRegistry::class);
        $this->app->singleton(ActiveTheme::class);
        $this->app->singleton(ThemeComposer::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        // CA-32: the admin sign-out must not end a customer session of the same browser.
        $this->app->bind(FilamentLogoutController::class, StaffLogoutController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // NF-02: N+1 problems fail loudly in development and tests, never in production.
        Model::preventLazyLoading(! $this->app->isProduction());

        // TG-04: Telegram sends updates from a few addresses.
        RateLimiter::for('telegram-webhook', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->ip()));

        $this->configureFilamentFormats();
        $this->forceCanonicalUrl();

        // A queue worker lives longer than a request: re-read the category tree from the cache per job.
        Queue::looping(function (): void {
            $this->app->make(CategoryTree::class)->forgetMemo();
            $this->app->make(PriceRuleRepository::class)->forgetMemo();
            $this->app->make(PermissionService::class)->flush();
        });

        // RB-04: the per-request permission memo never outlives a request.
        Event::listen(RequestHandled::class, fn () => $this->app->make(PermissionService::class)->flush());
        Event::listen(MessageSending::class, ApplyShopNameToMail::class);

        // The theme head partial is also printed by the error pages, which must render without the settings or the
        // database (SF-07): it gets the theme from ThemeComposer only, so the storefront composer skips it.
        // TM-01: neither does the light entry page of the Mini App (no catalog SQL before the session exists).
        View::composer('storefront.*', fn (ViewContract $view) => in_array($view->name(), ['storefront.partials.theme-head', 'storefront.telegram.entry'], true)
            ? null
            : $this->app->make(StorefrontComposer::class)->compose($view));
        // TH-05: `$theme` and the theme assets for the storefront and the error pages.
        View::composer(['storefront.*', 'errors.*'], ThemeComposer::class);

        // TM-09: the cached base of the «Открыть в Telegram» links follows the Telegram settings.
        Event::listen(SettingsSaved::class, function (SettingsSaved $event): void {
            if ($event->settings instanceof TelegramSettings) {
                (new MiniAppConfig($event->settings))->rememberSiteLinkBase();
            }
        });

        $this->forgetCartFlagOnChanges();

        // NF-12: passwords of at least 10 characters everywhere (user form, password reset).
        Password::defaults(fn () => Password::min(10));
    }

    /**
     * D-057: in production every generated absolute URL (canonical, Open Graph, sitemap, cached fragments) is built
     * from APP_URL, never from the Host header of whichever request happened to come first.
     */
    public function forceCanonicalUrl(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        $url = trim((string) config('app.url'));

        if (parse_url($url, PHP_URL_HOST) === null || parse_url($url, PHP_URL_HOST) === false) {
            return;
        }

        URL::forceRootUrl($url);

        if (str_starts_with(strtolower($url), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * The "cart exists in the shop" flag of the header (SF-01) depends on sales settings, categories and the
     * sales mode of products; drop the cached value when one of them changes.
     */
    private function forgetCartFlagOnChanges(): void
    {
        $forget = fn () => $this->app->make(CartAvailability::class)->forget();

        Event::listen(SettingsSaved::class, $forget);

        // Cached storefront menus (NF-04) depend on categories and on the legal/general settings.
        $forgetMenus = fn () => $this->app->make(MenuService::class)->flush();

        Event::listen(SettingsSaved::class, $forgetMenus);
        Category::saved($forgetMenus);
        Category::deleted($forgetMenus);
        Category::saved($forget);
        Category::deleted($forget);
        Product::saved(function (Product $product) use ($forget): void {
            if ($product->wasRecentlyCreated || $product->wasChanged(['sales_mode', 'is_active'])) {
                $forget();
            }
        });
        Product::deleted($forget);
    }

    /**
     * Russian date/time display formats for the admin panel (AD-01): ДД.ММ.ГГГГ ЧЧ:ММ.
     */
    private function configureFilamentFormats(): void
    {
        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            ->defaultTimeDisplayFormat(self::TIME_FORMAT)
            ->defaultCurrency('rub')
            ->defaultNumberLocale('ru'));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            ->defaultTimeDisplayFormat(self::TIME_FORMAT)
            ->defaultCurrency('rub')
            ->defaultNumberLocale('ru'));

        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            ->defaultTimeDisplayFormat(self::TIME_FORMAT));
    }
}
