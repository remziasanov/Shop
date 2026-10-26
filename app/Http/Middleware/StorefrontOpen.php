<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Closed mode (SF-05): while "shop open" is off, storefront pages answer 503 with the message from the settings.
 * Not affected: the admin panel and its file endpoints, Livewire endpoints (the panel login uses them), the health
 * check and /robots.txt (answers "Disallow: /" itself). Signed-in active staff (administrators and managers) see the
 * storefront normally so they can check it before opening.
 */
class StorefrontOpen
{
    public function __construct(private readonly GeneralSettings $general) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isOpen() || $this->isExempt($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && ($user->is_active ?? false)) {
            return $next($request);
        }

        return response()
            ->view('errors.shop-closed', ['message' => $this->message()], 503)
            ->header('Retry-After', '3600')
            ->header('Cache-Control', 'no-store, private');
    }

    private function isOpen(): bool
    {
        try {
            return $this->general->shop_open;
        } catch (Throwable) {
            return true;
        }
    }

    private function message(): string
    {
        try {
            return trim((string) $this->general->closed_message);
        } catch (Throwable) {
            return '';
        }
    }

    private function isExempt(Request $request): bool
    {
        $admin = trim((string) config('shop.admin_path'), '/');

        return $request->is('up', 'robots.txt', 'livewire*', 'livewire-*/*')
            || ($admin !== '' && $request->is($admin, $admin.'/*'));
    }
}
