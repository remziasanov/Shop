<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Enums\MenuLinkType;
use App\Enums\MenuLocation;
use App\Enums\StorefrontSection;
use App\Models\MenuItem;
use App\Models\Page;
use App\Services\Access\AccessSet;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryNode;
use App\Services\Catalog\CategoryTree;
use App\Settings\LegalSettings;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;

/**
 * Storefront menus (SF-01, SF-02, SF-72, AD-61): header and footer items, links to the legal pages
 * and the seller requisites, built per customer group and cached (NF-04) under `menus:{group}:{catalog_version}` (CG-19).
 * Items hidden from the viewer's group are left out (CG-10, CG-13). {@see flush()} moves the catalog version on when a
 * menu item, a page, a category, a group or the settings change.
 *
 * @phpstan-type Link array{label: string, url: string}
 * @phpstan-type HeaderItem array{label: string, url: string, children: list<Link>}
 * @phpstan-type Menus array{header: list<HeaderItem>, footer: list<Link>, privacy: Link|null, consent: Link|null, seller: string|null}
 */
final class MenuService
{
    public const CACHE_KEY = 'menus';

    public const TTL_SECONDS = 600;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly CategoryTree $tree,
        private readonly ViewerContext $viewer,
    ) {}

    /**
     * @return Menus
     */
    public function all(): array
    {
        $access = $this->viewer->access();

        /** @var Menus */
        return $this->cache->remember(self::cacheKey($access), self::TTL_SECONDS, fn (): array => $this->build($access, $this->viewer->group()->id));
    }

    /**
     * Link to the consent page when one is chosen and active (SF-71).
     *
     * @return Link|null
     */
    public function consentLink(): ?array
    {
        return $this->all()['consent'];
    }

    public static function cacheKey(AccessSet $access): string
    {
        return self::CACHE_KEY.':'.$access->cacheKey().':'.CatalogVersion::current();
    }

    public function flush(): void
    {
        CatalogVersion::bump();
        // Again after the surrounding transaction commits, so a concurrent request cannot re-cache the old state.
        DB::afterCommit(fn () => CatalogVersion::bump());
    }

    /**
     * @return Menus
     */
    private function build(AccessSet $access, ?int $groupId): array
    {
        $items = MenuItem::query()
            ->active()
            ->with(['category', 'page'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $header = [];
        $footer = [];

        foreach ($items as $item) {
            if (! $item->isVisibleToGroup($groupId)) {
                continue;
            }

            $url = $this->resolve($item, $access);

            if ($url === null) {
                continue;
            }

            if ($item->location === MenuLocation::Footer) {
                $footer[] = ['label' => $item->label, 'url' => $url];

                continue;
            }

            $header[] = ['label' => $item->label, 'url' => $url, 'children' => $this->children($item, $access)];
        }

        $legal = app(LegalSettings::class);
        $pages = Page::query()
            ->active()
            ->whereIn('id', array_filter([$legal->privacy_page_id, $legal->consent_page_id]))
            ->get(['id', 'title', 'slug', 'visible_group_ids'])
            ->filter(fn (Page $page): bool => $page->isVisibleToGroup($groupId))
            ->keyBy('id');

        $link = fn (?int $id): ?array => $id !== null && $pages->has($id)
            ? ['label' => (string) $pages[$id]->title, 'url' => route('page.show', $pages[$id]->slug, false)]
            : null;

        $seller = trim((string) $legal->seller_details);

        return [
            'header' => $header,
            'footer' => $footer,
            'privacy' => $link($legal->privacy_page_id),
            'consent' => $link($legal->consent_page_id),
            'seller' => $seller === '' ? null : $seller,
        ];
    }

    private function resolve(MenuItem $item, AccessSet $access): ?string
    {
        if ($item->link_type === MenuLinkType::Category) {
            // A category hidden by an inactive ancestor is hidden from the menu as well (SF-16); so is one the group may not open.
            if ($item->category_id === null || ! $this->tree->isVisible($item->category_id) || $item->category === null
                || ! $access->allowsSection(StorefrontSection::Catalog) || ! $access->canSeeCategory($item->category_id)) {
                return null;
            }

            return route('catalog.category', $item->category->slug, false);
        }

        $url = $item->url();

        if ($url === null) {
            return null;
        }

        if ($item->link_type === MenuLinkType::Page && $item->page !== null) {
            // CG-10: a link to a page hidden from the group is not shown either.
            return $item->page->isVisibleToGroup($this->viewer->group()->id) ? route('page.show', $item->page->slug, false) : null;
        }

        return $url;
    }

    /**
     * Second menu level: visible children of a category item (SF-01).
     *
     * @return list<Link>
     */
    private function children(MenuItem $item, AccessSet $access): array
    {
        if ($item->link_type !== MenuLinkType::Category || $item->category_id === null) {
            return [];
        }

        $node = $this->tree->node($item->category_id);

        if ($node === null) {
            return [];
        }

        return array_values(array_map(
            fn (CategoryNode $child): array => ['label' => $child->name, 'url' => route('catalog.category', $child->slug, false)],
            array_filter($node->children, fn (CategoryNode $child): bool => $child->isActive && $access->canSeeCategory($child->id)),
        ));
    }
}
