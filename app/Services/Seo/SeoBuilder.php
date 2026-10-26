<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\ProductView;
use App\Settings\GeneralSettings;
use App\Settings\SeoSettings;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds {@see SeoMeta} for storefront pages (SF-81, SF-83): manual meta values win, otherwise the templates of
 * SeoSettings with the placeholders {name}, {price}, {category}, {shop} (plus {brand}, {sku}, {short_description}).
 */
final class SeoBuilder
{
    private const DESCRIPTION_LIMIT = 300;

    public function __construct(
        private readonly SeoSettings $seo,
        private readonly GeneralSettings $general,
    ) {}

    /**
     * For HomeController: `SeoBuilder::forHome()`.
     */
    public static function forHome(): SeoMeta
    {
        return app(self::class)->home();
    }

    public function home(): SeoMeta
    {
        $shop = $this->shop();
        $title = $this->text($this->seo->home_title);

        if ($title === '') {
            $tagline = $this->text($this->general->tagline);
            $title = $tagline !== '' ? $shop.' — '.$tagline : $shop;
        }

        $description = $this->text($this->seo->home_description) ?: $this->text($this->general->tagline);
        $url = route('home');

        return new SeoMeta(
            title: $title,
            description: $description,
            canonical: $url,
            ogImage: $this->defaultImage(),
            ogUrl: $url,
            jsonLd: [
                array_filter([
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => $shop,
                    'url' => $url,
                    'logo' => $this->general->logo ? $this->absolute(Storage::disk('public')->url($this->general->logo)) : null,
                ]),
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => $shop,
                    'url' => $url,
                ],
            ],
        );
    }

    /**
     * @param  list<array{name: string, url: string|null}>  $breadcrumbs
     */
    public function catalog(array $breadcrumbs, string $canonical): SeoMeta
    {
        return $this->simple(__('storefront.catalog.title'), '', $canonical, $breadcrumbs);
    }

    /**
     * @param  list<array{name: string, url: string|null}>  $breadcrumbs
     */
    public function category(Category $category, array $breadcrumbs, string $canonical): SeoMeta
    {
        $values = ['name' => $category->name, 'category' => $category->name, 'price' => '', 'brand' => '', 'sku' => '', 'short_description' => ''];

        $title = $this->text($category->meta_title)
            ?: $this->fill($this->seo->category_title_template, $values)
            ?: $category->name.' — '.$this->shop();
        $description = $this->text($category->meta_description)
            ?: $this->fill($this->seo->category_description_template, $values)
            ?: $this->excerpt($category->description);

        $image = $category->image_path ? $this->absolute(Storage::disk('public')->url($category->image_path)) : $this->defaultImage();

        return new SeoMeta(
            title: $title,
            description: Str::limit($description, self::DESCRIPTION_LIMIT, '…'),
            canonical: $canonical,
            ogImage: $image,
            ogUrl: $canonical,
            jsonLd: $this->breadcrumbList($breadcrumbs, $canonical),
        );
    }

    /**
     * @param  list<array{name: string, url: string|null}>  $breadcrumbs
     */
    public function product(Product $product, ProductView $view, array $breadcrumbs): SeoMeta
    {
        $category = $product->relationLoaded('category') ? $product->category->name : '';
        $price = $product->price_on_request ? '' : Money::format(Money::fromRubles($product->final_price));
        $values = [
            'name' => $product->name,
            'category' => $category,
            'price' => $price,
            'brand' => (string) $product->brand,
            'sku' => (string) $product->sku,
            'short_description' => $this->excerpt($product->short_description),
        ];

        $title = $this->text($product->meta_title)
            ?: $this->fill($this->seo->product_title_template, $values)
            ?: $product->name.' — '.$this->shop();
        $description = $this->text($product->meta_description)
            ?: $this->fill($this->seo->product_description_template, $values)
            ?: $this->excerpt($product->short_description ?: $product->description);

        $url = route('product.show', $product->slug);
        $images = [];

        foreach ($product->images as $image) {
            $images[] = $this->absolute($image->urlLarge());
        }

        return new SeoMeta(
            title: $title,
            description: Str::limit($description, self::DESCRIPTION_LIMIT, '…'),
            canonical: $url,
            ogType: 'product',
            ogImage: $images[0] ?? $this->defaultImage(),
            ogUrl: $url,
            jsonLd: [
                $this->productJsonLd($product, $view, $url, $description, $images),
                ...$this->breadcrumbList($breadcrumbs, $url),
            ],
        );
    }

    /**
     * SF-22: search results are not indexed and have no canonical.
     */
    public function search(string $term): SeoMeta
    {
        $title = $term !== '' ? __('storefront.search.results_for', ['term' => $term]) : __('storefront.search.title');

        return new SeoMeta(
            title: $title.' — '.$this->shop(),
            robots: 'noindex,follow',
            ogImage: $this->defaultImage(),
        );
    }

    /**
     * Static page: the manual meta values or the page title and a text excerpt.
     *
     * @param  list<array{name: string, url: string|null}>  $breadcrumbs
     */
    public function page(string $title, ?string $metaTitle, ?string $metaDescription, ?string $content, string $url, array $breadcrumbs = []): SeoMeta
    {
        return new SeoMeta(
            title: $this->text($metaTitle) ?: $title.' — '.$this->shop(),
            description: $this->text($metaDescription) ?: Str::limit($this->excerpt($content), 160),
            canonical: $url,
            ogImage: $this->defaultImage(),
            ogUrl: $url,
            jsonLd: $this->breadcrumbList($breadcrumbs, $url),
        );
    }

    /**
     * Defaults for pages that pass no SeoMeta (cart, checkout, pages of other modules, the home page): the layout
     * sections `title` / `meta_description` are used.
     *
     * @param  list<array{name: string, url: string|null}>|null  $breadcrumbs
     */
    public function fallback(string $title, string $description, ?array $breadcrumbs = null): SeoMeta
    {
        if (request()->routeIs('home')) {
            $home = $this->home();

            return $home->description === '' && $description !== '' ? new SeoMeta($home->title, $description, $home->canonical, null, $home->ogType, null, null, $home->ogImage, $home->ogUrl, $home->jsonLd) : $home;
        }

        $url = url()->current();

        return new SeoMeta(
            title: $title !== '' ? $title.' — '.$this->shop() : $this->shop(),
            description: $description,
            canonical: $url,
            ogImage: $this->defaultImage(),
            ogUrl: $url,
            jsonLd: $breadcrumbs !== null ? $this->breadcrumbList($breadcrumbs, $url) : [],
        );
    }

    /**
     * @param  list<array{name: string, url: string|null}>  $breadcrumbs
     */
    private function simple(string $title, string $description, string $canonical, array $breadcrumbs): SeoMeta
    {
        return new SeoMeta(
            title: $title.' — '.$this->shop(),
            description: $description,
            canonical: $canonical,
            ogImage: $this->defaultImage(),
            ogUrl: $canonical,
            jsonLd: $this->breadcrumbList($breadcrumbs, $canonical),
        );
    }

    /**
     * @param  list<string>  $images
     * @return array<string, mixed>
     */
    private function productJsonLd(Product $product, ProductView $view, string $url, string $description, array $images): array
    {
        $offer = ['@type' => 'Offer', 'url' => $url];

        if (! $product->price_on_request) {
            $offer['price'] = number_format((float) $product->final_price, 2, '.', '');
            $offer['priceCurrency'] = 'RUB';
        }

        $offer['availability'] = 'https://schema.org/'.match (true) {
            $view->productActions->outOfStock => 'OutOfStock',
            $view->productActions->backorder => 'PreOrder',
            default => 'InStock',
        };

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $description !== '' ? $description : null,
            'sku' => $product->sku !== '' ? $product->sku : null,
            'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand] : null,
            'image' => $images !== [] ? $images : null,
            'url' => $url,
            'offers' => $offer,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  list<array{name: string, url: string|null}>  $breadcrumbs
     * @return list<array<string, mixed>>
     */
    private function breadcrumbList(array $breadcrumbs, string $currentUrl): array
    {
        if ($breadcrumbs === []) {
            return [];
        }

        $items = [];

        foreach ($breadcrumbs as $i => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'] ?? $currentUrl,
            ];
        }

        return [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $template, array $values): string
    {
        $values['shop'] = $this->shop();

        $text = preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => $values[$m[1]] ?? '', $template) ?? '';
        $text = preg_replace('/[ \t\r\n]+/', ' ', $text) ?? '';

        return trim($text, " \t\n\r—–-,:;|");
    }

    private function shop(): string
    {
        return $this->general->shop_name;
    }

    private function text(?string $value): string
    {
        return trim((string) $value);
    }

    private function excerpt(?string $html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function defaultImage(): ?string
    {
        $path = $this->seo->og_image ?: $this->general->logo;

        return $path ? $this->absolute(Storage::disk('public')->url($path)) : null;
    }

    private function absolute(string $url): string
    {
        return preg_match('#^https?://#i', $url) === 1 ? $url : url($url);
    }
}
