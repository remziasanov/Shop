<?php

declare(strict_types=1);

namespace App\Providers;

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
use App\View\Composers\StorefrontComposer;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(ImageProcessor::class);
        $this->app->singleton(StorefrontComposer::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // NF-02: N+1 problems fail loudly in development and tests, never in production.
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->configureFilamentFormats();

        // A queue worker lives longer than a request: re-read the category tree from the cache per job.
        Queue::looping(function (): void {
            $this->app->make(CategoryTree::class)->forgetMemo();
            $this->app->make(PriceRuleRepository::class)->forgetMemo();
        });

        View::composer('storefront.*', StorefrontComposer::class);

        $this->forgetCartFlagOnChanges();

        // NF-12: passwords of at least 10 characters everywhere (user form, password reset).
        Password::defaults(fn () => Password::min(10));
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
