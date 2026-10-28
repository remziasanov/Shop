<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasGroupVisibility;
use App\Observers\PageObserver;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Static page /page/{slug} (SF-70, AD-60). Content is sanitized on save by the observer.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $content
 * @property bool $is_active
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property list<int>|null $visible_group_ids groups the page is shown to (CG-10), null = all
 */
#[ObservedBy(PageObserver::class)]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;
    use HasGroupVisibility;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'is_active',
        'meta_title',
        'meta_description',
        'visible_group_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'visible_group_ids' => 'array'];
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    public function url(): string
    {
        return route('page.show', $this->slug);
    }
}
