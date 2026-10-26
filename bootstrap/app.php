<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Services\Catalog\RedirectService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // SF-80, D-018: a storefront 404 (unknown route or ModelNotFoundException, which Laravel converts
        // to NotFoundHttpException) is checked against the slug-change redirects; only then is a query made.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
                return null;
            }

            $adminPath = trim((string) config('shop.admin_path'), '/');

            if ($request->is('api/*', 'livewire*', 'livewire-*/*') || ($adminPath !== '' && $request->is($adminPath, $adminPath.'/*'))) {
                return null;
            }

            $path = '/'.trim($request->path(), '/');
            $target = app(RedirectService::class)->find($path);

            if ($target === null || $target === $path || ! RedirectService::isSafeTarget($target)) {
                return null;
            }

            // Raw query string: Request::getQueryString() would re-sort the parameters.
            $query = $request->server->get('QUERY_STRING');

            return new RedirectResponse($target.(is_string($query) && $query !== '' ? '?'.$query : ''), 301);
        });
    })->create();
