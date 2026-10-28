<?php

declare(strict_types=1);

namespace App\Services\Age;

use App\Enums\AgeGateMode;
use App\Enums\AgeRestriction;
use App\Models\Product;
use App\Providers\AgeGateServiceProvider;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\RedirectService;
use App\Settings\AgeGateSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Age gate decisions (TZ 16.5): which level the current page asks for, whether the shop has restricted products,
 * the confirmation cookie and the data of the overlay.
 *
 * Cached fragments never depend on the visitor's cookie (AG-07): restricted cards always carry data-age and CSS plus a
 * tiny inline script unblur them for a visitor who has already answered. The layout is not cached, so
 * {@see overlay()} leaves the overlay out for a visitor whose cookie already confirms the level (no-JavaScript
 * visitors can then pass); the cookie is also read by the server-side checks (checkout).
 *
 * Request scoped (see {@see AgeGateServiceProvider}): controllers of category and product pages
 * tell it which level their page has.
 */
final class AgeGate
{
    public const COOKIE = 'shop_age';

    /** Flash key set by the decline endpoint (AG-08); the value is the declined level. */
    public const FLASH_DECLINED = 'age_gate_declined';

    /** AG-03: «the shop has restricted products», cached for 10 minutes under `age:has_restricted:{catalog_version}` (TZ 26.3). */
    public const CACHE_KEY = 'age:has_restricted';

    public const CACHE_TTL = 600;

    private ?AgeRestriction $pageLevel = null;

    public function __construct(
        private readonly AgeGateSettings $settings,
        private readonly AgeRestrictionResolver $resolver,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    public function mode(): AgeGateMode
    {
        return $this->settings->mode;
    }

    /**
     * The level asked by the «site» mode.
     */
    public function siteLevel(): AgeRestriction
    {
        return AgeRestriction::fromAge($this->settings->site_min_age) ?? AgeRestriction::Age18;
    }

    /**
     * Called by the category and product pages: the effective level of the page (null = unrestricted).
     */
    public function setPageLevel(?AgeRestriction $level): void
    {
        $this->pageLevel = $level;
    }

    /**
     * Level the current page asks for, or null when no overlay is needed (TZ 16.5 gate_required, before the cookie).
     */
    public function requiredLevel(): ?AgeRestriction
    {
        if (! $this->enabled()) {
            return null;
        }

        return match ($this->settings->mode) {
            AgeGateMode::Site => $this->hasRestricted() ? $this->siteLevel() : null,
            AgeGateMode::Restricted => $this->pageLevel,
        };
    }

    /**
     * AG-11: the age the order must be confirmed for (the strictest level among the products), or null when the
     * gate is off or nothing is restricted. Independent of the visitor's cookie.
     *
     * @param  iterable<Product>  $products
     */
    public function requiredForProducts(iterable $products): ?AgeRestriction
    {
        return $this->enabled() ? $this->resolver->strictest($products) : null;
    }

    /**
     * AG-03: at least one active, visible product has an effective restriction.
     */
    public function hasRestricted(): bool
    {
        return (bool) Cache::remember(self::cacheKey(), self::CACHE_TTL, function (): bool {
            $categoryIds = $this->resolver->restrictedCategoryIds();

            return Product::query()
                ->active()
                ->where(function ($query) use ($categoryIds): void {
                    $query->whereNotNull('products.age_restriction')
                        ->orWhereIn('products.category_id', $categoryIds);
                })
                ->exists();
        });
    }

    /**
     * Dropped whenever products or categories change (see {@see AgeGateServiceProvider}).
     */
    public static function forgetCache(): void
    {
        Cache::forget(self::cacheKey());
    }

    public static function cacheKey(): string
    {
        return self::CACHE_KEY.':'.CatalogVersion::current();
    }

    /**
     * Age confirmed by the visitor's cookie: 0, 16 or 18. Used by server-side checks only, never by templates.
     */
    public function confirmedAge(Request $request): int
    {
        $value = $request->cookies->get(self::COOKIE);

        return is_string($value) ? (AgeRestriction::tryFrom($value)?->minAge() ?? 0) : 0;
    }

    /**
     * AG-06: technical cookie with the confirmed level. Readable by JavaScript and CSS, hence not HttpOnly.
     */
    public function cookie(AgeRestriction $level, bool $secure): Cookie
    {
        $days = max(1, min(365, $this->settings->remember_days));

        return new Cookie(
            name: self::COOKIE,
            value: $level->value,
            expire: now()->addDays($days)->getTimestamp(),
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: false,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /**
     * AG-08, TZ 16.7: only a site-local path is accepted, anything else leads to the home page.
     */
    public function safeReturnPath(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2000 || ! RedirectService::isSafeTarget($value)) {
            return '/';
        }

        return $value;
    }

    /**
     * Replaces the {age} placeholder of a settings text.
     */
    public function replaceAge(string $text, AgeRestriction $level): string
    {
        return str_replace('{age}', (string) $level->minAge(), $text);
    }

    /**
     * Data of the overlay for the layout, or null when this page needs none.
     *
     * @return array{level: AgeRestriction, title: string, text: string, yes: string, no: string, message: string, declined: bool}|null
     */
    public function overlay(Request $request): ?array
    {
        $level = $this->requiredLevel();

        // The answer is already in the cookie: nothing to ask (the overlay is not a cached fragment).
        if ($level === null || $this->confirmedAge($request) >= $level->minAge()) {
            return null;
        }

        return [
            'level' => $level,
            'title' => $this->replaceAge($this->settings->title, $level),
            'text' => $this->replaceAge($this->settings->text, $level),
            'yes' => $this->replaceAge($this->settings->yes_label, $level),
            'no' => $this->replaceAge($this->settings->no_label, $level),
            'message' => $this->replaceAge($this->settings->decline_message, $level),
            'declined' => (bool) $request->session()->get(self::FLASH_DECLINED, false),
        ];
    }

    /**
     * The refusal message shown as a plain notice when the visitor was sent home and no overlay is on that page.
     */
    public function declinedNotice(Request $request): ?string
    {
        if (! $this->enabled() || $this->requiredLevel() !== null || ! $request->session()->get(self::FLASH_DECLINED, false)) {
            return null;
        }

        $flashed = $request->session()->get(self::FLASH_DECLINED);
        $level = (is_string($flashed) ? AgeRestriction::tryFrom($flashed) : null) ?? $this->siteLevel();

        return $this->replaceAge($this->settings->decline_message, $level);
    }

    public function showBadge(): bool
    {
        return $this->settings->show_badge;
    }
}
