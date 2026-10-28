<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ImportFinished;
use App\Models\Category;
use App\Models\Product;
use App\Services\Age\AgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Age gate wiring (TZ 16): the request-scoped {@see AgeGate} and the invalidation of its «shop has restricted
 * products» flag (AG-03) whenever products or categories change.
 */
class AgeGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: the page level set by a controller must not leak into the next request of a long-lived process.
        $this->app->scoped(AgeGate::class);
    }

    public function boot(): void
    {
        $forget = static function (): void {
            AgeGate::forgetCache();
            DB::afterCommit(static fn () => AgeGate::forgetCache());
        };

        Event::listen(ImportFinished::class, $forget);

        foreach ([Product::class, Category::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                Event::listen("eloquent.{$event}: {$model}", $forget);
            }
        }

        foreach (['restored', 'forceDeleted'] as $event) {
            Event::listen("eloquent.{$event}: ".Product::class, $forget);
        }
    }
}
