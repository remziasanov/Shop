<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ImportFinished;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Home\HomePageRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelSettings\Events\SettingsSaved;

/**
 * Drops the cached home page HTML whenever something it shows changes (5.2, D-054): the builder and the
 * other settings, products and their photos, categories, finished imports.
 */
class HomePageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Forget now and again after commit, so a parallel request cannot cache a pre-commit state.
        $forget = static function (): void {
            HomePageRenderer::forget();
            DB::afterCommit(static fn () => HomePageRenderer::forget());
        };

        Event::listen(SettingsSaved::class, $forget);
        Event::listen(ImportFinished::class, $forget);

        foreach ([Product::class, Category::class, ProductImage::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                Event::listen("eloquent.{$event}: {$model}", $forget);
            }
        }

        foreach (['restored', 'forceDeleted'] as $event) {
            Event::listen("eloquent.{$event}: ".Product::class, $forget);
        }
    }
}
