<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use App\Services\Media\ImageProcessor;
use App\View\Composers\StorefrontComposer;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(ImageProcessor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureFilamentFormats();

        View::composer('storefront.*', StorefrontComposer::class);

        // NF-12: passwords of at least 10 characters everywhere (user form, password reset).
        Password::defaults(fn () => Password::min(10));
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
