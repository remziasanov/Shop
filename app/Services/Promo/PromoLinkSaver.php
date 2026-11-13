<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\PromoLinkTargetType;
use App\Models\Category;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Models\User;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Saves a promo link from the admin form state (PM-21) and answers the questions of the link form: the public address,
 * the check of the destination (PM-26), the status of the attached code.
 *
 * Form state keys: name, slug, target_type, product_id, variant_id, category_id, filter_category_id, filter_query,
 * target_url, promo_code_id, utm_*, starts_at, ends_at, is_active. Fields that do not belong to the chosen destination
 * type are dropped; `catalog_filter` is stored as `/catalog[/{category slug}][?query]`.
 */
final class PromoLinkSaver
{
    public const SLUG_PATTERN = '/^[a-z0-9-]{3,64}$/';

    public function __construct(private readonly PromoLinkResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function save(?PromoLink $link, array $state, ?User $user = null): PromoLink
    {
        $type = PromoLinkTargetType::tryFrom((string) ($state['target_type'] ?? ''));

        if ($type === null) {
            throw new InvalidArgumentException('Incomplete promo link.');
        }

        $link ??= new PromoLink;
        $creating = ! $link->exists;

        $link->fill([
            'name' => trim((string) ($state['name'] ?? '')),
            'slug' => trim((string) ($state['slug'] ?? '')),
            'target_type' => $type,
            'target_id' => match ($type) {
                PromoLinkTargetType::Product, PromoLinkTargetType::Variant => $this->int($state['product_id'] ?? null),
                PromoLinkTargetType::Category => $this->int($state['category_id'] ?? null),
                default => null,
            },
            'variant_id' => $type === PromoLinkTargetType::Variant ? $this->int($state['variant_id'] ?? null) : null,
            'target_url' => match ($type) {
                PromoLinkTargetType::CatalogFilter => $this->catalogPath($state),
                PromoLinkTargetType::Url => trim((string) ($state['target_url'] ?? '')),
                default => null,
            },
            'promo_code_id' => $this->int($state['promo_code_id'] ?? null),
            'starts_at' => $this->date($state['starts_at'] ?? null),
            'ends_at' => $this->date($state['ends_at'] ?? null),
            'is_active' => (bool) ($state['is_active'] ?? true),
        ]);

        foreach (PromoLink::UTM_KEYS as $key) {
            $value = PromoLinkResolver::sanitizeUtm(trim((string) ($state[$key] ?? '')));
            $link->{$key} = $value === '' ? null : $value;
        }

        if ($creating) {
            $link->created_by = $user?->id;
        }

        $link->save();

        return $link;
    }

    /**
     * Form state of an existing link.
     *
     * @return array<string, mixed>
     */
    public function formState(PromoLink $link): array
    {
        $state = [
            'name' => $link->name,
            'slug' => $link->slug,
            'target_type' => $link->target_type->value,
            'product_id' => in_array($link->target_type, [PromoLinkTargetType::Product, PromoLinkTargetType::Variant], true) ? $link->target_id : null,
            'variant_id' => $link->variant_id,
            'category_id' => $link->target_type === PromoLinkTargetType::Category ? $link->target_id : null,
            'filter_category_id' => null,
            'filter_query' => null,
            'target_url' => $link->target_type === PromoLinkTargetType::Url ? $link->target_url : null,
            'promo_code_id' => $link->promo_code_id,
            'starts_at' => $link->starts_at,
            'ends_at' => $link->ends_at,
            'is_active' => $link->is_active,
        ];

        if ($link->target_type === PromoLinkTargetType::CatalogFilter && preg_match('~^/catalog(?:/([^?#]+))?(?:\?(.*))?$~', (string) $link->target_url, $m) === 1) {
            $slug = isset($m[1]) && $m[1] !== '' ? rawurldecode($m[1]) : null;
            $state['filter_category_id'] = $slug === null ? null : Category::query()->where('slug', $slug)->value('id');
            $state['filter_query'] = $m[2] ?? null;
        }

        foreach (PromoLink::UTM_KEYS as $key) {
            $state[$key] = $link->{$key};
        }

        return $state;
    }

    /**
     * The public address `{APP_URL}/go/{slug}` (PM-26).
     */
    public function publicUrl(string $slug): string
    {
        return rtrim((string) config('app.url'), '/').'/go/'.$slug;
    }

    /**
     * PM-26: the final address for a guest (with UTM) or the reason the link would not work now.
     */
    public function check(PromoLink $link, ?CarbonInterface $now = null): PromoLinkResolution
    {
        return $this->resolver->resolve($link, null, $now);
    }

    /**
     * PM-26: a sentence about the code the link applies and its state.
     */
    public function codeStatus(PromoLink $link, ?CarbonInterface $now = null): string
    {
        $code = $link->promoCode;

        if (! $code instanceof PromoCode) {
            return __('promo_admin.links.code_status.none');
        }

        $now ??= now();

        $status = match (true) {
            ! $code->is_active => 'inactive',
            $code->starts_at !== null && $code->starts_at->gt($now) => 'not_started',
            $code->ends_at !== null && $code->ends_at->lt($now) => 'expired',
            $code->usage_limit !== null && $code->usage_count >= $code->usage_limit => 'exhausted',
            default => 'active',
        };

        return __('promo_admin.links.code_status.ok', ['code' => $code->code, 'status' => __('promo_admin.links.code_status.'.$status)]);
    }

    /**
     * Whether the query string of a catalog filter can be part of an internal address.
     */
    public static function validQuery(string $query): bool
    {
        return $query === '' || PromoLinkResolver::isInternalPath('/catalog?'.ltrim($query, '?'));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function catalogPath(array $state): string
    {
        $categoryId = $this->int($state['filter_category_id'] ?? null);
        $slug = $categoryId === null ? null : Category::query()->whereKey($categoryId)->value('slug');
        $query = ltrim(trim((string) ($state['filter_query'] ?? '')), '?');

        return '/catalog'.(is_string($slug) ? '/'.rawurlencode($slug) : '').($query === '' ? '' : '?'.$query);
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function date(mixed $value): ?string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_string($value) && trim($value) !== '' => date('Y-m-d H:i:s', (int) strtotime($value)),
            default => null,
        };
    }
}
