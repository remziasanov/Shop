<?php

declare(strict_types=1);

namespace App\Services\Telegram\MiniApp;

use App\Http\Controllers\Storefront\PromoLinkController;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CategoryTree;
use App\Services\Promo\PromoCodeNormalizer;
use App\Services\Telegram\TelegramBotSetup;
use App\Support\StorefrontCookie;
use App\Support\TelegramMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turns the launch parameter of the Mini App into the page to open (TM-08). The value comes from the signed `initData`
 * (`start_param`, `t.me/{bot}?startapp=…`) or, as a hint only, from the `?to=` of a `web_app` button; both go through
 * the same checks, so the unsigned hint grants nothing a signed parameter would not (CA-28 for orders).
 *
 * Grammar: `p_{id}`, `c_{id}`, `cart`, `orders`, `o_{uuid}`, `promo_{code}`, `go_{slug}`, `check`, `staff`,
 * `staff_o_{id}`. Numeric ids, not slugs: short and stable under renaming. An unknown, inactive or hidden object leads
 * to the home page with «Товар недоступен».
 */
final class TelegramStartParamResolver
{
    public const NOTICE_KEY = 'tg_notice';

    public function __construct(
        private readonly ViewerContext $viewer,
        private readonly CategoryTree $tree,
    ) {}

    /**
     * @throws NotFoundHttpException an order that belongs to nobody the session knows (CA-28)
     */
    public function resolve(?string $param, Request $request): RedirectResponse
    {
        $param = $param === null ? '' : trim($param);

        if ($param === '' || preg_match('/^[A-Za-z0-9_-]{1,512}$/', $param) !== 1) {
            return redirect()->route('home');
        }

        return match (true) {
            $param === 'cart' => redirect()->route('cart'),
            $param === 'orders' => redirect()->route('customer.orders'),
            $param === 'check' => $this->check(),
            $param === 'staff' || str_starts_with($param, 'staff_o_') => $this->staff(),
            preg_match('/^p_([1-9][0-9]{0,17})$/', $param, $m) === 1 => $this->product((int) $m[1]),
            preg_match('/^c_([1-9][0-9]{0,17})$/', $param, $m) === 1 => $this->category((int) $m[1]),
            preg_match('/^o_([0-9a-fA-F-]{36})$/', $param, $m) === 1 => $this->order(strtolower($m[1])),
            str_starts_with($param, 'promo_') => $this->promo(substr($param, 6), $request),
            str_starts_with($param, 'go_') => $this->promoLink(substr($param, 3), $request),
            default => $this->unavailable(),
        };
    }

    private function product(int $id): RedirectResponse
    {
        $product = Product::query()->storefront()->whereKey($id)->first();

        return $product === null ? $this->unavailable() : redirect()->route('product.show', $product->slug);
    }

    private function category(int $id): RedirectResponse
    {
        $category = Category::query()->find($id);

        if ($category === null || ! $this->tree->isVisible($category->id) || ! $this->viewer->access()->canSeeCategory($category->id)) {
            return $this->unavailable();
        }

        return redirect()->route('catalog.category', $category->slug);
    }

    /**
     * CA-28: only the Telegram account that placed the order or the signed-in customer of it may open it; anything
     * else, an unknown id included, is the same 404.
     */
    private function order(string $uuid): RedirectResponse
    {
        if (! Str::isUuid($uuid)) {
            throw new NotFoundHttpException;
        }

        $order = Order::query()->where('uuid', $uuid)->first(['id', 'uuid', 'customer_id', 'telegram_account_id']);
        $customer = $this->viewer->customer();
        $accountId = TelegramMode::accountId();

        $ownedByCustomer = $order !== null && $customer !== null && $order->customer_id === $customer->id;
        $ownedByTelegram = $order !== null && $accountId !== null && $order->telegram_account_id === $accountId;

        if ($order === null || (! $ownedByCustomer && ! $ownedByTelegram)) {
            throw new NotFoundHttpException;
        }

        return $ownedByCustomer
            ? redirect()->route('customer.orders.show', $order->uuid)
            : redirect()->route('order.thanks', $order->uuid);
    }

    /**
     * PM-23: the code is only remembered (`shop_promo`); the cart applies it with the usual PM checks when it has
     * lines. A code that does not exist is not stored.
     */
    private function promo(string $code, Request $request): RedirectResponse
    {
        $code = PromoCodeNormalizer::normalize($code);

        if (! PromoCodeNormalizer::isValid($code) || ! PromoCode::query()->where('code', $code)->exists()) {
            return $this->unavailable(__('telegram_miniapp.promo_unavailable'));
        }

        return redirect()->route('home')
            ->withCookie(StorefrontCookie::make(PromoLinkController::PROMO_COOKIE, $code, 60 * 24 * 30, true, null, $request))
            ->with(self::NOTICE_KEY, __('telegram_miniapp.promo_saved', ['code' => $code]));
    }

    /**
     * PM-21…PM-24: the promo link itself, with its cookies and click statistics.
     */
    private function promoLink(string $slug, Request $request): RedirectResponse
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $slug) !== 1 || ! PromoLink::query()->where('slug', $slug)->exists()) {
            return $this->unavailable();
        }

        // A known link that is switched off or expired answers as on the site (PM-22): the catalog with its notice.

        /** @var RedirectResponse */
        return app()->call(PromoLinkController::class, ['request' => $request, 'slug' => $slug]);
    }

    /** TG-03: the startapp link worked. */
    private function check(): RedirectResponse
    {
        app(TelegramBotSetup::class)->markStartappVerified();

        return redirect()->route('home')->with(self::NOTICE_KEY, __('telegram_miniapp.check_ok'));
    }

    /** «Рабочее место» is built in stage 18; until then the shop home opens. */
    private function staff(): RedirectResponse
    {
        return redirect()->route('home');
    }

    private function unavailable(?string $message = null): RedirectResponse
    {
        return redirect()->route('home')->with(self::NOTICE_KEY, $message ?? __('telegram_miniapp.item_unavailable'));
    }
}
