<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\PromoLinkTargetType;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoLink;
use App\Services\Access\CatalogAccess;
use App\Services\Catalog\CategoryTree;
use Carbon\CarbonInterface;

/**
 * Decides where `/go/{slug}` leads (PM-21, PM-22, PM-27): the link must be active and inside its period, its target must
 * be available for the viewer's customer group, and the address is always an internal path. UTM labels are added only
 * when the address does not carry them yet and only with a sanitized value.
 */
final class PromoLinkResolver
{
    public const MAX_URL = 500;

    public const MAX_UTM = 100;

    public const REASON_INACTIVE = 'inactive';

    public const REASON_NOT_STARTED = 'not_started';

    public const REASON_EXPIRED = 'expired';

    public const REASON_TARGET_UNAVAILABLE = 'target_unavailable';

    public const REASON_TARGET_INVALID = 'target_invalid';

    public function __construct(
        private readonly CategoryTree $tree,
        private readonly CatalogAccess $access,
    ) {}

    /**
     * The final address for the viewer: link status, availability of the target, UTM.
     *
     * @param  CustomerGroup|null  $group  the viewer's group; «Гость» when null
     */
    public function resolve(PromoLink $link, ?CustomerGroup $group = null, ?CarbonInterface $now = null): PromoLinkResolution
    {
        $problem = $this->statusProblem($link, $now ?? now());

        if ($problem !== null) {
            return PromoLinkResolution::stale($problem);
        }

        $path = $this->targetPath($link);

        if ($path === null) {
            return PromoLinkResolution::stale(self::REASON_TARGET_INVALID);
        }

        if (! $this->targetAvailable($link, $group ?? $this->access->guestGroup())) {
            return PromoLinkResolution::stale(self::REASON_TARGET_UNAVAILABLE);
        }

        return PromoLinkResolution::to($this->withUtm($path, $link));
    }

    /**
     * Active and inside the period, otherwise the reason key.
     */
    public function statusProblem(PromoLink $link, CarbonInterface $now): ?string
    {
        return match (true) {
            ! $link->is_active => self::REASON_INACTIVE,
            $link->starts_at !== null && $link->starts_at->gt($now) => self::REASON_NOT_STARTED,
            $link->ends_at !== null && $link->ends_at->lt($now) => self::REASON_EXPIRED,
            default => null,
        };
    }

    /**
     * The internal path (+ query) of the target without UTM labels, or null when the stored target is unusable
     * (missing record, external or malformed address). Does not check availability for a viewer.
     */
    public function targetPath(PromoLink $link): ?string
    {
        switch ($link->target_type) {
            case PromoLinkTargetType::Product:
                $slug = $link->target_id === null ? null : Product::withTrashed()->whereKey($link->target_id)->value('slug');

                return is_string($slug) ? '/product/'.rawurlencode($slug) : null;

            case PromoLinkTargetType::Variant:
                $slug = $link->target_id === null ? null : Product::withTrashed()->whereKey($link->target_id)->value('slug');

                return is_string($slug) && $link->variant_id !== null ? '/product/'.rawurlencode($slug).'?variant='.$link->variant_id : null;

            case PromoLinkTargetType::Category:
                $slug = $link->target_id === null ? null : Category::query()->whereKey($link->target_id)->value('slug');

                return is_string($slug) ? '/catalog/'.rawurlencode($slug) : null;

            case PromoLinkTargetType::CatalogFilter:
                $url = (string) $link->target_url;

                return self::isInternalPath($url) && preg_match('~^/catalog(?:[/?#]|$)~', $url) === 1 ? $url : null;

            case PromoLinkTargetType::Url:
                $url = (string) $link->target_url;

                return self::isInternalPath($url) ? $url : null;
        }
    }

    /**
     * PM-22: product active and not deleted (and visible to the group), variant active, category visible for the group.
     */
    public function targetAvailable(PromoLink $link, CustomerGroup $group): bool
    {
        $access = $this->access->for($group);

        switch ($link->target_type) {
            case PromoLinkTargetType::Product:
            case PromoLinkTargetType::Variant:
                $product = $link->target_id === null ? null : Product::query()->whereKey($link->target_id)->first();

                if ($product === null || ! $product->is_active || ! $this->tree->isVisible($product->category_id) || ! $access->canSeeProduct($product)) {
                    return false;
                }

                if ($link->target_type === PromoLinkTargetType::Product) {
                    return true;
                }

                return ProductVariant::query()
                    ->whereKey($link->variant_id)
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->exists();

            case PromoLinkTargetType::Category:
                $id = (int) $link->target_id;

                return $id > 0 && $this->tree->isVisible($id) && $access->canSeeCategory($id);

            case PromoLinkTargetType::CatalogFilter:
                // `/catalog/{slug}?…`: the category of the path must be visible too; plain `/catalog` is always open.
                $path = (string) parse_url((string) $link->target_url, PHP_URL_PATH);

                if (preg_match('~^/catalog/([^/]+)/?$~', $path, $m) !== 1) {
                    return true;
                }

                $id = Category::query()->where('slug', rawurldecode($m[1]))->value('id');

                return $id !== null && $this->tree->isVisible((int) $id) && $access->canSeeCategory((int) $id);

            default:
                return true;
        }
    }

    /**
     * PM-27: the UTM labels of the link appended to the path; labels the address already carries are left alone.
     */
    public function withUtm(string $path, PromoLink $link): string
    {
        $fragment = '';

        if (($hash = strpos($path, '#')) !== false) {
            $fragment = substr($path, $hash);
            $path = substr($path, 0, $hash);
        }

        $query = '';

        if (($mark = strpos($path, '?')) !== false) {
            $query = substr($path, $mark + 1);
            $path = substr($path, 0, $mark);
        }

        $present = [];

        foreach (explode('&', $query) as $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);

            if ($name !== '') {
                $present[$name] = true;
            }
        }

        $added = [];

        foreach ($link->utm() as $key => $value) {
            $value = self::sanitizeUtm($value);

            if ($value !== '' && ! isset($present[$key])) {
                $added[] = $key.'='.$value;
            }
        }

        $query = implode('&', array_filter([$query, implode('&', $added)], fn (string $part): bool => $part !== ''));

        return $path.($query === '' ? '' : '?'.$query).$fragment;
    }

    /**
     * PM-27: only Latin letters, digits and `.` `_` `-`, at most 100 characters; anything else is dropped.
     */
    public static function sanitizeUtm(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $value), 0, self::MAX_UTM);
    }

    /**
     * PM-21, PM-22: an address of this shop — one leading `/`, not `//`, no backslash, no control characters, no scheme
     * or host (open redirect guard).
     */
    public static function isInternalPath(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL || $url[0] !== '/') {
            return false;
        }

        if (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false && ! isset($parts['scheme']) && ! isset($parts['host']);
    }
}
