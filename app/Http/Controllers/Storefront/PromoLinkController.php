<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\PromoLink;
use App\Services\Access\ViewerContext;
use App\Services\Promo\BotDetector;
use App\Services\Promo\PromoLinkResolver;
use App\Services\Promo\PromoLinkStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * GET /go/{slug} (PM-21…PM-25, PM-27): a promo link. A usable link answers 302 to its internal target with the UTM
 * labels, remembers the link (`shop_pl`, last click, PM-24) and its promo code (`shop_promo`, PM-23) and counts the click
 * unless a bot asked. Anything else (unknown, switched off, outside its period, target gone for the viewer's group)
 * leads to the catalog with the «Ссылка устарела» notice. The address always comes from {@see PromoLinkResolver}, which
 * only returns internal paths (no open redirect).
 */
class PromoLinkController extends Controller
{
    public const LINK_COOKIE = 'shop_pl';

    public const PROMO_COOKIE = 'shop_promo';

    /** Ids of the links this browser has already visited (unique clicks, PM-25). */
    public const SEEN_COOKIE = 'shop_pl_seen';

    private const DAYS = 30;

    private const SEEN_LIMIT = 30;

    public function __invoke(Request $request, string $slug, PromoLinkResolver $resolver, PromoLinkStats $stats, ViewerContext $viewer): RedirectResponse
    {
        $link = PromoLink::query()->with('promoCode')->where('slug', $slug)->first();
        $resolution = $link !== null ? $resolver->resolve($link, $viewer->group()) : null;

        // Belt and braces: whatever the resolver says, only a site-local path is ever used as the target.
        if ($link === null || $resolution === null || ! $resolution->ok || $resolution->url === null || ! PromoLinkResolver::isInternalPath($resolution->url)) {
            return $this->noindex(redirect()->route('catalog.index', [], 302)->with('promo_link_notice', __('promo.link_notice')));
        }

        $response = redirect()->to($resolution->url, 302);
        $secure = (bool) (config('session.secure') ?? $request->isSecure());

        $response->withCookie($this->cookie(self::LINK_COOKIE, (string) $link->id, $secure));

        if ($link->promoCode !== null) {
            $response->withCookie($this->cookie(self::PROMO_COOKIE, $link->promoCode->code, $secure));
        }

        $seen = $this->seen($request);
        $isUnique = ! in_array($link->id, $seen, true);

        if ($isUnique) {
            $seen[] = $link->id;
            $response->withCookie($this->cookie(self::SEEN_COOKIE, implode(',', array_slice($seen, -self::SEEN_LIMIT)), $secure));
        }

        // PM-25: robots do not count (the cookies above are harmless for them).
        if (! BotDetector::isBot($request->userAgent())) {
            $stats->recordClick($link, $isUnique);
        }

        return $this->noindex($response);
    }

    /**
     * @return list<int>
     */
    private function seen(Request $request): array
    {
        $value = $request->cookie(self::SEEN_COOKIE);
        $ids = [];

        if (is_string($value) && preg_match('/^[0-9]+(,[0-9]+)*$/', $value) === 1) {
            $ids = array_map('intval', explode(',', $value));
        }

        return $ids;
    }

    /**
     * 30 days, HttpOnly (read only on the server), SameSite=Lax; encrypted by the cookie middleware.
     */
    private function cookie(string $name, string $value, bool $secure): Cookie
    {
        return new Cookie($name, $value, now()->addDays(self::DAYS)->getTimestamp(), '/', null, $secure, true, false, Cookie::SAMESITE_LAX);
    }

    private function noindex(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
