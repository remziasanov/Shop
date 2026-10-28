<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Enums\StorefrontSection;
use App\Models\MenuItem;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CategoryNode;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\MenuService;
use App\Services\Sales\CartAvailability;
use App\Settings\AppearanceSettings;
use App\Settings\CustomerSettings;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Shares storefront settings with the layout and its partials (SF-01, SF-02, SF-04).
 */
class StorefrontComposer
{
    private const DEFAULT_PRIMARY = '#2563eb';

    private const DEFAULT_ACCENT = '#f59e0b';

    public function __construct(
        private readonly GeneralSettings $general,
        private readonly AppearanceSettings $appearance,
        private readonly CategoryTree $tree,
        private readonly CartAvailability $cartAvailability,
        private readonly MenuService $menus,
        private readonly ViewerContext $viewer,
        private readonly CustomerSettings $customers,
    ) {}

    /** @var array<string, mixed>|null Data built once per request (the composer runs for every storefront view and partial). */
    private ?array $data = null;

    private ?Request $dataRequest = null;

    public function compose(View $view): void
    {
        $request = request();

        if ($this->data === null || $this->dataRequest !== $request) {
            $this->data = $this->build();
            $this->dataRequest = $request;
        }

        $view->with($this->data);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $phones = array_values(array_filter($this->general->phones, fn ($phone) => trim($phone) !== ''));

        $menus = $this->menus->all();
        $access = $this->viewer->access();

        return [
            'shopName' => $this->general->shop_name,
            'shopTagline' => $this->general->tagline,
            'shopLogoUrl' => $this->general->logo ? Storage::disk('public')->url($this->general->logo) : null,
            'shopFaviconUrl' => $this->general->favicon ? Storage::disk('public')->url($this->general->favicon) : null,
            'shopPhones' => $phones,
            'shopEmail' => $this->general->email,
            'shopAddress' => $this->general->address,
            'shopWorkingHours' => $this->general->working_hours,
            'shopFooterText' => $this->general->footer_text,
            'primaryColor' => $this->safeColor($this->appearance->primary_color, self::DEFAULT_PRIMARY),
            'accentColor' => $this->safeColor($this->appearance->accent_color, self::DEFAULT_ACCENT),
            'searchQuery' => is_string(request()->query('q')) ? request()->query('q') : '',
            'menuCategories' => $this->headerMenu($menus['header']),
            'footerMenu' => $menus['footer'],
            'footerPrivacyLink' => $menus['privacy'],
            'footerConsentLink' => $menus['consent'],
            'sellerDetails' => $menus['seller'],
            'socialLinks' => $this->socialLinks(),
            'cartEnabled' => $this->cartAvailability->enabled(),
            // CG-04: the header search needs the search and catalog sections.
            'searchEnabled' => $access->allowsSection(StorefrontSection::Search) && $access->allowsSection(StorefrontSection::Catalog),
            // CA-19: «Войти» / «Личный кабинет» in the header and the bottom bar; hidden when registration is off.
            'accountLinks' => $this->customers->registration_enabled || $this->viewer->customer() !== null,
            'accountCustomerName' => $this->viewer->customer()?->name,
            'previewGroup' => $this->viewer->isPreview() ? $this->viewer->group()->name : null,
        ];
    }

    /**
     * Header menu (SF-01, AD-61): the configured items (a category item carries its children as the second level);
     * with no active items - the root categories with their children.
     *
     * @param  list<array{label: string, url: string, children: list<array{label: string, url: string}>}>  $items
     * @return list<array{name: string, url: string, children: list<array{name: string, url: string}>}>
     */
    private function headerMenu(array $items): array
    {
        if ($items === []) {
            return $this->menuCategories();
        }

        return array_map(fn (array $item): array => [
            'name' => $item['label'],
            'url' => $item['url'],
            'children' => array_map(fn (array $child): array => ['name' => $child['label'], 'url' => $child['url']], $item['children']),
        ], $items);
    }

    /**
     * Social and messenger links from the general settings (SF-02); only http(s) URLs.
     *
     * @return list<array{title: string, url: string}>
     */
    private function socialLinks(): array
    {
        $links = [];

        foreach ($this->general->social_links as $link) {
            $url = trim((string) ($link['url'] ?? ''));

            if (preg_match('#^https?://#i', $url) === 1 && MenuItem::isSafeUrl($url)) {
                $title = trim((string) ($link['title'] ?? ''));
                $links[] = ['title' => $title !== '' ? $title : (string) parse_url($url, PHP_URL_HOST), 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * Two-level category menu (SF-01): active categories only (a parent that is inactive hides its subtree).
     *
     * @return list<array{name: string, url: string, children: list<array{name: string, url: string}>}>
     */
    private function menuCategories(): array
    {
        $menu = [];
        $access = $this->viewer->access();

        // CG-13: no category links for a group without the catalog section or for categories it may not open.
        if (! $access->allowsSection(StorefrontSection::Catalog)) {
            return [];
        }

        foreach ($this->tree->roots() as $root) {
            if (! $root->isActive || ! $access->canSeeCategory($root->id)) {
                continue;
            }

            $menu[] = [
                'name' => $root->name,
                'url' => route('catalog.category', $root->slug),
                'children' => array_values(array_map(
                    fn (CategoryNode $child): array => ['name' => $child->name, 'url' => route('catalog.category', $child->slug)],
                    array_filter($root->children, fn (CategoryNode $child): bool => $child->isActive && $access->canSeeCategory($child->id)),
                )),
            ];
        }

        return $menu;
    }

    /**
     * Only #rgb / #rrggbb values are allowed inside the inline <style> block.
     */
    private function safeColor(string $value, string $default): string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1 ? $value : $default;
    }
}
