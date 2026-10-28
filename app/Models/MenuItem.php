<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MenuLinkType;
use App\Enums\MenuLocation;
use App\Models\Concerns\HasGroupVisibility;
use App\Observers\MenuItemObserver;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Header or footer menu item (AD-61). The relations must be loaded before {@see url()} is called.
 *
 * @property int $id
 * @property MenuLocation $location
 * @property string $label
 * @property MenuLinkType $link_type
 * @property int|null $category_id
 * @property int|null $page_id
 * @property string|null $url
 * @property int $sort_order
 * @property bool $is_active
 * @property list<int>|null $visible_group_ids groups the item is shown to (CG-10), null = all
 * @property-read Category|null $category
 * @property-read Page|null $page
 */
#[ObservedBy(MenuItemObserver::class)]
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;
    use HasGroupVisibility;

    protected $fillable = [
        'location',
        'label',
        'link_type',
        'category_id',
        'page_id',
        'url',
        'sort_order',
        'is_active',
        'visible_group_ids',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'location' => MenuLocation::class,
            'link_type' => MenuLinkType::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'visible_group_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @param  Builder<MenuItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Link target, or null when it cannot be resolved (deleted or inactive target, unsafe URL).
     */
    public function url(): ?string
    {
        return match ($this->link_type) {
            MenuLinkType::Category => $this->category !== null && $this->category->is_active
                ? route('catalog.category', $this->category->slug)
                : null,
            MenuLinkType::Page => $this->page !== null && $this->page->is_active
                ? route('page.show', $this->page->slug)
                : null,
            MenuLinkType::Url => $this->url !== null && self::isSafeUrl($this->url) ? $this->url : null,
        };
    }

    /**
     * Allowed: http(s) URLs, site-relative paths ("/x", "#x"), tel: and mailto:. Everything else (javascript:, data:, …) is not.
     */
    public static function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        if (preg_match('#^(?:https?://[^/?\#]+|tel:\+?[0-9()\-.,;]+$|mailto:[^\s@]+@[^\s@]+)#i', $url) === 1) {
            return true;
        }

        return preg_match('#^(?:/(?![/\\\\])|\#)#', $url) === 1;
    }
}
