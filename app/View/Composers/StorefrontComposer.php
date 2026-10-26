<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Services\Catalog\CategoryNode;
use App\Services\Catalog\CategoryTree;
use App\Settings\AppearanceSettings;
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
            'menuCategories' => $this->menuCategories(),
        ];
    }

    /**
     * Two-level category menu (SF-01): active categories only (a parent that is inactive hides its subtree).
     *
     * @return list<array{name: string, url: string, children: list<array{name: string, url: string}>}>
     */
    private function menuCategories(): array
    {
        $menu = [];

        foreach ($this->tree->roots() as $root) {
            if (! $root->isActive) {
                continue;
            }

            $menu[] = [
                'name' => $root->name,
                'url' => route('catalog.category', $root->slug),
                'children' => array_values(array_map(
                    fn (CategoryNode $child): array => ['name' => $child->name, 'url' => route('catalog.category', $child->slug)],
                    array_filter($root->children, fn (CategoryNode $child): bool => $child->isActive),
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
