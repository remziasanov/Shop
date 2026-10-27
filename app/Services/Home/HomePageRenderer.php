<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Models\Product;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CategoryNode;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\ProductPresenter;
use App\Services\Content\HtmlSanitizer;
use App\Services\Media\ImageProcessor;
use App\Settings\AppearanceSettings;
use App\Settings\HomePageSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the home page blocks (5.2, D-054). Only visible blocks are rendered, in their saved order.
 * Without any renderable block the page shows the root categories and the 12 latest products.
 *
 * The HTML is cached for 10 minutes under {@see CACHE_KEY} and dropped by {@see HomePageServiceProvider}
 * listeners. Product cards contain plain POST forms with a CSRF token (NF-02), which is per session:
 * the token is swapped for a placeholder before caching and filled in for the current session on every
 * {@see render()}, so the cached markup never serves a foreign or stale token.
 */
final class HomePageRenderer
{
    public const CACHE_KEY = 'home.html';

    public const CACHE_TTL = 600;

    public const FALLBACK_PRODUCTS = 12;

    private const CSRF_PLACEHOLDER = '__HOME_CSRF_TOKEN__';

    private const TYPES = ['banner', 'slider', 'categories', 'products', 'text', 'advantages', 'html'];

    public function __construct(
        private readonly HomePageSettings $settings,
        private readonly CategoryTree $tree,
        private readonly ProductPresenter $presenter,
        private readonly AppearanceSettings $appearance,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function render(): string
    {
        return $this->page()['html'];
    }

    /**
     * The page HTML plus the images worth preloading in `<head>` (the first banner / slide, NF-03). Both are
     * cached together, so the preload always matches the markup.
     *
     * @return array{html: string, preload: list<array{href: string, srcset: string, sizes: string, media: string|null}>}
     */
    public function page(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached) || ! is_string($cached['html'] ?? null) || ! is_array($cached['preload'] ?? null)) {
            $cached = $this->build();
            Cache::put(self::CACHE_KEY, $cached, self::CACHE_TTL);
        }

        return [
            'html' => str_replace(self::CSRF_PLACEHOLDER, (string) csrf_token(), $cached['html']),
            'preload' => $cached['preload'],
        ];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{html: string, preload: list<array{href: string, srcset: string, sizes: string, media: string|null}>}
     */
    private function build(): array
    {
        $parts = [];
        $preload = [];

        foreach ($this->visibleBlocks() as $block) {
            $data = $this->data($block['type'], $block['data'], $parts === []);

            if ($data !== null) {
                if ($parts === []) {
                    $preload = $this->preloadFor($block['type'], $data);
                }

                $parts[] = view('storefront.home.blocks.'.$block['type'], $data)->render();
            }
        }

        if ($parts === []) {
            $parts = $this->fallback();
        }

        $html = implode("\n", $parts);
        $token = (string) csrf_token();

        return [
            'html' => $token !== '' ? str_replace($token, self::CSRF_PLACEHOLDER, $html) : $html,
            'preload' => $preload,
        ];
    }

    /**
     * Preload links for the first image of a banner / slider that opens the page (it is the LCP element).
     *
     * @param  array<string, mixed>  $data  view data of the block
     * @return list<array{href: string, srcset: string, sizes: string, media: string|null}>
     */
    private function preloadFor(string $type, array $data): array
    {
        $slide = match ($type) {
            'banner' => $data['slide'] ?? null,
            'slider' => is_array($data['slides'] ?? null) ? ($data['slides'][0] ?? null) : null,
            default => null,
        };

        if (! is_array($slide) || ! is_string($slide['src'] ?? null) || ! is_string($slide['srcset'] ?? null)) {
            return [];
        }

        $desktopSizes = '(min-width: 1280px) 1216px, 100vw';

        if (is_string($slide['mobileSrcset'] ?? null)) {
            $mobile = explode(' ', trim(explode(',', $slide['mobileSrcset'])[0]))[0];

            return [
                ['href' => $mobile, 'srcset' => $slide['mobileSrcset'], 'sizes' => '100vw', 'media' => '(max-width: 767px)'],
                ['href' => $slide['src'], 'srcset' => $slide['srcset'], 'sizes' => $desktopSizes, 'media' => '(min-width: 768px)'],
            ];
        }

        return [['href' => $slide['src'], 'srcset' => $slide['srcset'], 'sizes' => $desktopSizes, 'media' => null]];
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function visibleBlocks(): array
    {
        $blocks = [];

        foreach ((array) $this->settings->blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if (is_string($type) && in_array($type, self::TYPES, true) && ($data['visible'] ?? true)) {
                $blocks[] = ['type' => $type, 'data' => $data];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null view data, or null when the block has nothing to show
     */
    private function data(string $type, array $data, bool $first): ?array
    {
        return match ($type) {
            'banner' => $this->banner($data, $first),
            'slider' => $this->slider($data, $first),
            'categories' => $this->categories($data),
            'products' => $this->products($data, $first),
            'text' => $this->text($data),
            'advantages' => $this->advantages($data),
            default => $this->html($data),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function banner(array $data, bool $first): ?array
    {
        $slide = $this->slide($data);

        return $slide === null ? null : ['slide' => $slide, 'first' => $first];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function slider(array $data, bool $first): ?array
    {
        $slides = [];

        foreach (is_array($data['slides'] ?? null) ? $data['slides'] : [] as $slide) {
            $slide = is_array($slide) ? $this->slide($slide) : null;

            if ($slide !== null) {
                $slides[] = $slide;
            }
        }

        if ($slides === []) {
            return null;
        }

        return [
            'slides' => $slides,
            'first' => $first,
            'autoplay' => (bool) ($data['autoplay'] ?? true),
            'interval' => max(2, min(60, (int) ($data['interval'] ?? 5))) * 1000,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function slide(array $data): ?array
    {
        $image = HomeImages::normalize($data['image'] ?? null);

        if ($image === null) {
            return null;
        }

        $mobile = HomeImages::normalize($data['image_mobile'] ?? null);
        $buttonUrl = HomeLink::clean($data['button_link'] ?? null);
        $buttonText = $this->string($data['button_text'] ?? null);

        $title = $this->string($data['title'] ?? null);

        return [
            'title' => $title,
            'alt' => $this->string($data['image_alt'] ?? null) ?? $title ?? '',
            'subtitle' => $this->string($data['subtitle'] ?? null),
            'src' => $this->url($image['large']),
            'srcset' => $this->srcset($image),
            'width' => $image['width'],
            'height' => $image['height'],
            'mobileSrcset' => $mobile !== null ? $this->srcset($mobile) : null,
            'buttonText' => $buttonUrl !== null ? $buttonText : null,
            'url' => $buttonUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function categories(array $data): ?array
    {
        $max = max(1, min(24, (int) ($data['max_count'] ?? 8)));

        if (($data['mode'] ?? 'auto') === 'selected') {
            $nodes = [];

            foreach ((array) ($data['categories'] ?? []) as $id) {
                $node = $this->tree->node((int) $id);

                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
        } else {
            $nodes = $this->tree->roots();
        }

        $tiles = array_slice($this->tiles($nodes), 0, $max);

        return $tiles === [] ? null : ['title' => $this->string($data['title'] ?? null), 'tiles' => $tiles];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function products(array $data, bool $first): ?array
    {
        $source = is_string($data['source'] ?? null) ? $data['source'] : 'hits';
        $limit = max(1, min(24, (int) ($data['limit'] ?? 8)));
        $sort = is_string($data['sort'] ?? null) ? $data['sort'] : 'default';

        $query = Product::query()->active()->with('mainImage');
        $manualOrder = [];

        switch ($source) {
            case 'new':
                $query->where('is_new', true);
                break;
            case 'discounted':
                $query->whereNotNull('display_old_price');
                break;
            case 'category':
                $categoryId = (int) ($data['category_id'] ?? 0);
                $query->whereIn('category_id', $this->tree->descendantIds($categoryId));
                break;
            case 'manual':
                $manualOrder = array_values(array_unique(array_map('intval', (array) ($data['product_ids'] ?? []))));
                $query->whereIn('id', $manualOrder);
                break;
            default:
                $source = 'hits';
                $query->where('is_featured', true);
        }

        $this->sort($query, $sort, $source === 'manual');

        $products = $query->limit($source === 'manual' ? max(count($manualOrder), 1) : $limit)->get();

        if ($source === 'manual' && $sort === 'default') {
            $products = $products->sortBy(fn (Product $product): int|false => array_search($product->id, $manualOrder, true))->values();
        }

        if ($source === 'manual') {
            $products = $products->take($limit)->values();
        }

        if ($products->isEmpty()) {
            return null;
        }

        return [
            'title' => $this->string($data['title'] ?? null),
            'cards' => $this->presenter->many($products, false),
            'first' => $first,
            'columns' => $this->appearance->grid_columns === 3 ? 3 : 4,
            'allUrl' => ($data['show_all'] ?? true) ? $this->allUrl($source, (int) ($data['category_id'] ?? 0)) : null,
        ];
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function sort(Builder $query, string $sort, bool $manual): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('final_price')->orderBy('id'),
            'price_desc' => $query->orderByDesc('final_price')->orderBy('id'),
            'new' => $query->orderByDesc('is_new')->orderByDesc('created_at')->orderByDesc('id'),
            default => $manual ? $query->orderBy('id') : $query->orderBy('sort_order')->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * «Смотреть все» target by the product source.
     */
    private function allUrl(string $source, int $categoryId): ?string
    {
        $flag = static fn (string $flag): string => route('catalog.index', ['flags' => [$flag]], false);

        return match ($source) {
            'hits' => $flag(CatalogFilters::FLAG_HIT),
            'new' => $flag(CatalogFilters::FLAG_NEW),
            'discounted' => $flag(CatalogFilters::FLAG_SALE),
            'category' => ($node = $this->tree->node($categoryId)) !== null ? route('catalog.category', $node->slug, false) : null,
            default => route('catalog.index', [], false),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function text(array $data): ?array
    {
        $content = $this->sanitizer->sanitize(is_string($data['content'] ?? null) ? $data['content'] : '');

        return $content === '' ? null : ['title' => $this->string($data['title'] ?? null), 'content' => $content];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function advantages(array $data): ?array
    {
        $items = [];

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (is_array($item) && ($title = $this->string($item['title'] ?? null)) !== null) {
                $items[] = [
                    'icon' => is_string($item['icon'] ?? null) ? $item['icon'] : 'star',
                    'title' => $title,
                    'text' => $this->string($item['text'] ?? null),
                ];
            }
        }

        return $items === [] ? null : ['items' => $items];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function html(array $data): ?array
    {
        $html = is_string($data['html'] ?? null) ? $data['html'] : '';

        return trim($html) === '' ? null : ['html' => $html];
    }

    /**
     * Root categories and the latest products (nothing configured).
     *
     * @return list<string>
     */
    private function fallback(): array
    {
        $parts = [];
        $tiles = $this->tiles($this->tree->roots());

        if ($tiles !== []) {
            $parts[] = view('storefront.home.blocks.categories', ['title' => __('storefront.catalog.categories'), 'tiles' => $tiles])->render();
        }

        $products = Product::query()->active()->with('mainImage')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::FALLBACK_PRODUCTS)->get();

        if ($products->isNotEmpty()) {
            $parts[] = view('storefront.home.blocks.products', [
                'title' => __('home.storefront.latest'),
                'cards' => $this->presenter->many($products, false),
                'first' => $parts === [],
                'columns' => $this->appearance->grid_columns === 3 ? 3 : 4,
                'allUrl' => route('catalog.index', [], false),
            ])->render();
        }

        return $parts;
    }

    /**
     * Category tiles of the active, visible categories (as on the catalog page).
     *
     * @param  list<CategoryNode>  $nodes
     * @return list<array{name: string, url: string, image: string|null, count: int}>
     */
    private function tiles(array $nodes): array
    {
        $counts = null;
        $tiles = [];

        foreach ($nodes as $node) {
            if (! $node->isActive || ! $this->tree->isVisible($node->id)) {
                continue;
            }

            $counts ??= $this->tree->productCountsWithDescendants(true);
            $tiles[] = [
                'name' => $node->name,
                'url' => route('catalog.category', $node->slug, false),
                'image' => $node->imagePath !== null ? $this->url($node->imagePath) : null,
                'count' => $counts[$node->id] ?? 0,
            ];
        }

        return $tiles;
    }

    /**
     * @param  array{large: string, small: string, width: int, height: int}  $image
     */
    private function srcset(array $image): string
    {
        $small = min($image['width'], ImageProcessor::BANNER_SMALL);

        return $small < $image['width']
            ? $this->url($image['small']).' '.$small.'w, '.$this->url($image['large']).' '.$image['width'].'w'
            : $this->url($image['large']).' '.$image['width'].'w';
    }

    private function url(string $path): string
    {
        return Storage::disk('public')->url($path);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
