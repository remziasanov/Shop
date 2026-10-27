<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Livewire\Storefront\Concerns\RequiresOpenShop;
use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closed mode (SF-05): while "shop open" is off, storefront pages answer 503 with the message from the settings.
 * Not affected: the admin panel and its file endpoints, Livewire endpoints (the panel login uses them; storefront components guard themselves with
 * RequiresOpenShop, D-057), the health check and /robots.txt (normal rules, D-056). Signed-in active staff (administrators and managers) see the
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

        if (self::isActiveStaff($request->user())) {
            return $next($request);
        }

        return self::closedResponse($this->message());
    }

    /**
     * The 503 "shop closed" response; also thrown by storefront Livewire components ({@see RequiresOpenShop}).
     */
    public static function closedResponse(?string $message = null): Response
    {
        $message ??= trim((string) app(GeneralSettings::class)->closed_message);

        return response()
            ->view('errors.shop-closed', ['message' => $message], 503)
            ->header('Retry-After', '3600')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Signed-in active staff may use the closed storefront (also checked by storefront Livewire components,
     * see {@see RequiresOpenShop}).
     */
    public static function isActiveStaff(?object $user): bool
    {
        return $user !== null && (bool) ($user->is_active ?? false);
    }

    private function isOpen(): bool
    {
        return $this->general->shop_open;
    }

    private function message(): string
    {
        return trim((string) $this->general->closed_message);
    }

    private function isExempt(Request $request): bool
    {
        $admin = trim((string) config('shop.admin_path'), '/');

        return $request->is('up', 'robots.txt', 'livewire*', 'livewire-*/*')
            || ($admin !== '' && $request->is($admin, $admin.'/*'));
    }
}
